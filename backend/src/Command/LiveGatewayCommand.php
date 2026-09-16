<?php

declare(strict_types=1);

namespace App\Command;

use App\Address\WcsAddressProvider;
use App\Doctrine\OpenEntityManager;
use App\Entity\Intake;
use App\Entity\VoiceSession;
use App\Live\GptLiveClient;
use App\Live\LiveGatewayCommandQueue;
use App\Live\LiveGreeting;
use App\Live\LiveIdlePolicy;
use App\Live\SidebandPayload;
use App\Service\IntakeEventPublisher;
use App\Service\IntakeService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use WebSocket\Client as WebSocketClient;
use WebSocket\Exception\ConnectionClosedException;
use WebSocket\Exception\ConnectionTimeoutException;
use WebSocket\Middleware\CloseHandler;
use WebSocket\Middleware\PingResponder;

/**
 * Long-running GPT-Live sideband worker.
 * Attaches to wss://api.openai.com/v1/live/sessions/{id}/attach and handles client delegations.
 *
 * @see https://developers.openai.com/api/docs/guides/voice-server-controls
 */
#[AsCommand(name: 'woningtriage:live-gateway', description: 'Attach to GPT-Live sideband sessions and process delegations')]
final class LiveGatewayCommand extends Command
{
    public function __construct(
        private readonly LiveGatewayCommandQueue $queue,
        private readonly OpenEntityManager $entityManagers,
        private readonly IntakeService $intakeService,
        private readonly IntakeEventPublisher $events,
        private readonly GptLiveClient $gptLiveClient,
        #[Autowire('%env(default::OPENAI_API_KEY)%')]
        private readonly ?string $apiKey,
        #[Autowire('%env(default::OPENAI_LIVE_VOICE)%')]
        private readonly string $liveVoice = 'marin',
        #[Autowire('%env(default::OPENAI_LIVE_IDLE_PROMPT_SECONDS)%')]
        private readonly string $idlePromptSeconds = '60',
        #[Autowire('%env(default::OPENAI_LIVE_IDLE_CLOSE_SECONDS)%')]
        private readonly string $idleCloseSeconds = '180',
    ) {
        parent::__construct();
    }

    private function entityManager(): EntityManagerInterface
    {
        return $this->entityManagers->get();
    }

    private function apiKey(): string
    {
        return $this->apiKey ?? '';
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        @ini_set('output_buffering', '0');
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        ob_implicit_flush(true);

        if ($this->apiKey() === '') {
            $this->log($output, 'OPENAI_API_KEY is not set. Gateway idle; text intake still works.');
            $this->log($output, 'Set OPENAI_API_KEY in backend/.env and restart: docker compose --profile live up --force-recreate');
            while (true) {
                sleep(30);
            }
        }

        $this->log($output, 'Live gateway waiting for voice sessions. Ctrl+C to stop.');
        $this->log($output, 'Follow logs: docker compose --profile live logs -f live-gateway api');
        $this->log($output, 'Address lookup provider='.WcsAddressProvider::class);
        $voice = strtolower(trim($this->liveVoice));
        $this->log($output, 'Live voice='.($voice !== '' ? $voice : 'marin'));
        $idle = LiveIdlePolicy::fromEnv($this->idlePromptSeconds, $this->idleCloseSeconds);
        $this->log($output, 'Live idle prompt='.$idle->promptAfterSeconds().'s close='.$idle->closeAfterSeconds().'s');
        $idleLoggedAt = 0;
        while (true) {
            $pending = $this->queue->pending();
            if ($pending === [] && time() - $idleLoggedAt >= 30) {
                $this->log($output, 'No open live voice sessions.');
                $idleLoggedAt = time();
            }
            foreach ($pending as $voiceSessionId) {
                $session = $this->entityManager()->find(VoiceSession::class, $voiceSessionId);
                if (!$session instanceof VoiceSession || $session->getProviderSessionId() === null) {
                    $this->queue->ack($voiceSessionId);
                    continue;
                }
                try {
                    $this->attach($session, $output);
                } catch (\Throwable $exception) {
                    $this->log($output, 'Gateway error for '.$voiceSessionId.': '.$exception->getMessage());
                }
                $this->queue->ack($voiceSessionId);
                $this->entityManager()->clear();
            }
            usleep(100_000);
        }
    }

    private function attach(VoiceSession $session, OutputInterface $output): void
    {
        $url = 'wss://api.openai.com/v1/live/sessions/'.$session->getProviderSessionId().'/attach';
        $client = new WebSocketClient($url);
        $client->addHeader('Authorization', 'Bearer '.$this->apiKey());
        $client->addHeader('OpenAI-Beta', 'live=v1');
        $client->addMiddleware(new CloseHandler());
        $client->addMiddleware(new PingResponder());
        $this->log($output, 'Connecting sideband '.$session->getId().' provider='.$session->getProviderSessionId());
        $client->setTimeout(8);
        try {
            $client->connect();
        } catch (\Throwable $exception) {
            $this->log($output, 'Sideband connect failed for '.$session->getId().': '.$exception->getMessage());
            $session->fail('sideband_connect');
            $this->entityManager()->flush();

            return;
        }
        $client->setTimeout(1);
        $this->log($output, 'Attached sideband for '.$session->getId());
        $transcript = '';
        $audioChunks = 0;
        $alreadySpeaking = $this->drainUntilOutputOrTimeout($client, $output, $transcript, $audioChunks, 0.4);
        if ($alreadySpeaking) {
            $this->log($output, 'Greeting already in progress on '.$session->getId().'; skipping duplicate');
        } else {
            $this->requestGreeting($client, $session, $output);
        }
        $client->setTimeout(1);
        $lastSpokenFollowUp = '';
        $lastSpokenUiOffer = '';
        $lastResidentAt = microtime(true);
        $idlePrompted = false;
        $idlePolicy = LiveIdlePolicy::fromEnv($this->idlePromptSeconds, $this->idleCloseSeconds);
        $lastIntakeRevision = $session->getIntake()->getRevision();
        while ($session->isOpen() || $session->getStatus() === VoiceSession::CLOSING) {
            try {
                $message = $client->receive();
            } catch (ConnectionTimeoutException) {
                $this->entityManager()->refresh($session);
                $this->maybeSpeakPendingFollowUp($session, $client, $output, $lastSpokenFollowUp, $lastResidentAt, $idlePrompted);
                $this->maybeSpeakUiOffer($session, $client, $output, $lastSpokenUiOffer);
                $this->markActivityIfIntakeChanged($session, $lastIntakeRevision, $lastResidentAt, $idlePrompted);
                if ($this->handleIdle($session, $client, $output, $idlePolicy, $lastResidentAt, $idlePrompted, $transcript, $audioChunks)) {
                    break;
                }
                continue;
            } catch (ConnectionClosedException $exception) {
                $this->log($output, 'Sideband closed for '.$session->getId().': '.$exception->getMessage());
                break;
            }
            $payload = SidebandPayload::decode($message);
            if ($payload === null) {
                $this->log($output, 'Ignored non-JSON sideband frame on '.$session->getId());
                continue;
            }
            $type = (string) ($payload['type'] ?? '');
            if ($this->isNoisySidebandType($type)) {
                if ($type === 'session.output_audio.delta') {
                    ++$audioChunks;
                }
                // Ambient mic frames are not resident activity. Counting them would
                // keep a paid GPT-Live session open forever.
                continue;
            }
            if ($type === 'session.input_transcript.delta') {
                $delta = (string) ($payload['delta'] ?? '');
                $transcript .= $delta;
                $this->markResidentActivity($lastResidentAt, $idlePrompted);
                $this->log($output, 'input_transcript +'.mb_strlen($delta).' chars: '.$this->clip($delta));
                continue;
            }
            if ($type === 'session.output_transcript.delta') {
                $this->log($output, 'output_transcript: '.$this->clip((string) ($payload['delta'] ?? '')));
                continue;
            }
            if ($type === 'session.closed') {
                $this->log($output, 'session.closed for '.$session->getId());
                break;
            }
            if ($type === 'session.delegation.created' && ($payload['delegation']['target'] ?? '') === 'client') {
                $this->markResidentActivity($lastResidentAt, $idlePrompted);
                $this->handleDelegation($session, $client, $output, $payload, $transcript, $lastSpokenFollowUp, $lastSpokenUiOffer);
                $transcript = '';
                continue;
            }
            if ($type !== '') {
                $this->log($output, 'event '.$type.' keys='.implode(',', array_keys($payload)));
            }
            $this->entityManager()->refresh($session);
            if (in_array($session->getStatus(), [VoiceSession::CLOSED, VoiceSession::FAILED], true)) {
                break;
            }
        }
        if ($audioChunks > 0) {
            $this->log($output, 'Detaching '.$session->getId().' after '.$audioChunks.' audio chunks');
        }
        $client->close();
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function handleDelegation(
        VoiceSession $session,
        WebSocketClient $client,
        OutputInterface $output,
        array $payload,
        string $transcript,
        string &$lastSpokenFollowUp,
        string &$lastSpokenUiOffer,
    ): void {
        $delegationId = (string) ($payload['delegation']['id'] ?? '');
        $intake = $session->getIntake();
        $this->entityManager()->refresh($intake);
        $previousQuestion = (string) ($intake->document()->nextQuestion['text'] ?? '');
        $previousLanguage = $intake->getConversationLanguage();
        $previousUi = (string) ($intake->document()->uiLanguage ?? '');
        $this->log($output, 'delegation '.$delegationId.' transcript_chars='.mb_strlen($transcript).' text='.$this->clip($transcript));
        $this->sendEvent($client, [
            'type' => 'session.thinking.append',
            'event_id' => \App\Domain\IdGenerator::prefixed('evt'),
            'delegation_id' => $delegationId !== '' ? $delegationId : null,
            'content' => \App\Live\LiveFollowUpSpeech::waitingOnBackend(),
        ]);
        $started = microtime(true);
        if (trim($transcript) === '') {
            $this->log($output, 'delegation without transcript; waiting instead of repeating the question');
            $this->sendEvent($client, [
                'type' => 'session.commentary.append',
                'event_id' => \App\Domain\IdGenerator::prefixed('evt'),
                'delegation_id' => $delegationId !== '' ? $delegationId : null,
                'content' => \App\Live\LiveFollowUpSpeech::noTranscript(),
            ]);

            return;
        }
        try {
            $task = new \App\Entity\AnalysisTask(
                \App\Domain\IdGenerator::prefixed('task'),
                $intake,
                'analyze',
                $intake->getRevision(),
            );
            $this->entityManager()->persist($task);
            $this->entityManager()->flush();
            $this->intakeService->runAnalysis(
                $intake,
                $task,
                $transcript,
                \App\Domain\IdGenerator::prefixed('message'),
            );
            $this->entityManager()->refresh($intake);
        } catch (\Throwable $exception) {
            $this->log($output, 'analysis failed: '.$exception->getMessage());
            $this->entityManager();
            $this->sendEvent($client, [
                'type' => 'session.commentary.append',
                'event_id' => \App\Domain\IdGenerator::prefixed('evt'),
                'delegation_id' => $delegationId !== '' ? $delegationId : null,
                'content' => \App\Live\LiveFollowUpSpeech::analysisRetry($previousLanguage),
            ]);
            $this->log($output, 'commentary skipped after analysis failure language='.$previousLanguage);

            return;
        }
        $language = $intake->getConversationLanguage();
        $ui = (string) ($intake->document()->uiLanguage ?? '');
        $uiChanged = $previousUi !== $ui;
        if ($language !== $previousLanguage) {
            $this->sendEvent($client, [
                'type' => 'session.instructions.append',
                'event_id' => \App\Domain\IdGenerator::prefixed('evt'),
                'delegation_id' => $delegationId !== '' ? $delegationId : null,
                'content' => \App\Live\LiveFollowUpSpeech::switchInstructions($language),
            ]);
            $this->log($output, 'language switch '.$previousLanguage.' -> '.$language);
        }
        if ($language !== $previousLanguage || $uiChanged) {
            $this->log($output, sprintf(
                'tool switch_language conversation=%s ui=%s apply_ui=%s previous_ui=%s',
                $language,
                $ui,
                $uiChanged ? '1' : '0',
                $previousUi,
            ));
        }
        $next = $intake->document()->nextQuestion['text'] ?? 'Gegevens zijn bijgewerkt.';
        $ms = (int) round((microtime(true) - $started) * 1000);
        $sameQuestion = $previousQuestion !== '' && $previousQuestion === $next;
        $thankYou = $intake->document()->spokenFollowUp;
        $offer = $intake->document()->uiLanguageOffer;
        $offerQuestion = is_array($offer) && is_string($offer['question'] ?? null) ? (string) $offer['question'] : '';
        if (is_string($thankYou) && $thankYou !== '') {
            $content = \App\Live\LiveFollowUpSpeech::afterAddressVerified($thankYou, (string) $next, $language);
            $lastSpokenFollowUp = $thankYou;
        } elseif ($uiChanged) {
            $content = \App\Live\LiveFollowUpSpeech::afterUiLanguageSwitch($language)
                .' Then ask this next question: '.$next;
        } elseif ($offerQuestion !== '') {
            $content = \App\Live\LiveFollowUpSpeech::uiLanguageOffer($offerQuestion, $language)
                .' Then ask this next question: '.$next;
            $lastSpokenUiOffer = $offerQuestion;
        } else {
            $content = \App\Live\LiveFollowUpSpeech::commentary((string) $next, $language, $sameQuestion);
        }
        $this->sendEvent($client, [
            'type' => 'session.commentary.append',
            'event_id' => \App\Domain\IdGenerator::prefixed('evt'),
            'delegation_id' => $delegationId !== '' ? $delegationId : null,
            'content' => $content,
        ]);
        $this->log($output, 'commentary sent in '.$ms.'ms language='.$language.' question='.$this->clip($next));
        $address = $intake->document()->address;
        if (!is_array($address)) {
            $address = [];
        }
        $candidates = is_array($address['candidates'] ?? null) ? $address['candidates'] : [];
        $candidateCount = array_is_list($candidates) ? count($candidates) : (isset($candidates['candidate_id']) ? 1 : 0);
        $houseNumber = $address['house_number'] ?? null;
        $this->log($output, sprintf(
            'address question_id=%s candidate_count=%d looked_up=%s house_number_digits=%s',
            (string) ($intake->document()->nextQuestion['id'] ?? ''),
            $candidateCount,
            is_string($address['lookup_id'] ?? null) && $address['lookup_id'] !== '' ? '1' : '0',
            is_numeric($houseNumber) ? (string) strlen((string) (int) $houseNumber) : '0',
        ));
    }

    private function maybeSpeakPendingFollowUp(
        VoiceSession $session,
        WebSocketClient $client,
        OutputInterface $output,
        string &$lastSpokenFollowUp,
        float &$lastResidentAt,
        bool &$idlePrompted,
    ): void {
        $intake = $session->getIntake();
        $this->entityManager()->refresh($intake);
        $thankYou = $intake->document()->spokenFollowUp;
        if (!is_string($thankYou) || $thankYou === '' || $thankYou === $lastSpokenFollowUp) {
            return;
        }
        $lastSpokenFollowUp = $thankYou;
        $this->markResidentActivity($lastResidentAt, $idlePrompted);
        $next = (string) ($intake->document()->nextQuestion['text'] ?? '');
        $language = $intake->getConversationLanguage();
        $this->sendEvent($client, [
            'type' => 'session.commentary.append',
            'event_id' => \App\Domain\IdGenerator::prefixed('evt'),
            'delegation_id' => null,
            'content' => \App\Live\LiveFollowUpSpeech::afterAddressVerified($thankYou, $next, $language),
        ]);
        $this->log($output, 'spoken address thank-you language='.$language.' text='.$this->clip($thankYou));
    }

    private function maybeSpeakUiOffer(
        VoiceSession $session,
        WebSocketClient $client,
        OutputInterface $output,
        string &$lastSpokenUiOffer,
    ): void {
        $intake = $session->getIntake();
        $this->entityManager()->refresh($intake);
        $offer = $intake->document()->uiLanguageOffer;
        $question = is_array($offer) && is_string($offer['question'] ?? null) ? (string) $offer['question'] : '';
        if ($question === '' || $question === $lastSpokenUiOffer) {
            return;
        }
        $lastSpokenUiOffer = $question;
        $language = $intake->getConversationLanguage();
        $this->sendEvent($client, [
            'type' => 'session.commentary.append',
            'event_id' => \App\Domain\IdGenerator::prefixed('evt'),
            'delegation_id' => null,
            'content' => \App\Live\LiveFollowUpSpeech::uiLanguageOffer($question, $language),
        ]);
        $this->log($output, 'spoken UI language offer language='.$language.' text='.$this->clip($question));
    }

    private function markActivityIfIntakeChanged(
        VoiceSession $session,
        int &$lastIntakeRevision,
        float &$lastResidentAt,
        bool &$idlePrompted,
    ): void {
        $intake = $session->getIntake();
        $this->entityManager()->refresh($intake);
        $revision = $intake->getRevision();
        if ($revision !== $lastIntakeRevision) {
            $lastIntakeRevision = $revision;
            $this->markResidentActivity($lastResidentAt, $idlePrompted);
        }
    }

    private function markResidentActivity(float &$lastResidentAt, bool &$idlePrompted): void
    {
        $lastResidentAt = microtime(true);
        $idlePrompted = false;
    }

    /**
     * @param-out string $transcript
     */
    private function handleIdle(
        VoiceSession $session,
        WebSocketClient $client,
        OutputInterface $output,
        LiveIdlePolicy $policy,
        float $lastResidentAt,
        bool &$idlePrompted,
        string &$transcript,
        int &$audioChunks,
    ): bool {
        $action = $policy->action(microtime(true) - $lastResidentAt, $idlePrompted);
        if ($action === null) {
            return false;
        }
        $intake = $session->getIntake();
        $this->entityManager()->refresh($intake);
        $language = $intake->getConversationLanguage();
        if ($action === LiveIdlePolicy::PROMPT) {
            $idlePrompted = true;
            $spoken = \App\Live\LiveFollowUpSpeech::idlePromptSpoken($language);
            $this->persistIdleNotice($intake, $spoken);
            $this->sendEvent($client, [
                'type' => 'session.commentary.append',
                'event_id' => \App\Domain\IdGenerator::prefixed('evt'),
                'delegation_id' => null,
                'content' => \App\Live\LiveFollowUpSpeech::idlePrompt($language),
            ]);
            $this->log($output, 'idle prompt after '.$policy->promptAfterSeconds().'s language='.$language);

            return false;
        }

        $spoken = \App\Live\LiveFollowUpSpeech::idleClosingSpoken($language);
        $this->persistIdleNotice($intake, $spoken);
        $this->sendEvent($client, [
            'type' => 'session.commentary.append',
            'event_id' => \App\Domain\IdGenerator::prefixed('evt'),
            'delegation_id' => null,
            'content' => \App\Live\LiveFollowUpSpeech::idleClosing($language),
        ]);
        $this->log($output, 'idle close after '.$policy->closeAfterSeconds().'s language='.$language);
        $this->drainUntilOutputOrTimeout($client, $output, $transcript, $audioChunks, 6);
        $this->closeIdleSession($session, $output);

        return true;
    }

    private function persistIdleNotice(Intake $intake, string $notice): void
    {
        $this->entityManager()->refresh($intake);
        $document = $intake->document();
        $document->idleNotice = $notice;
        $intake->replaceDocument($document);
        $this->entityManager()->flush();
    }

    private function closeIdleSession(VoiceSession $session, OutputInterface $output): void
    {
        $providerId = $session->getProviderSessionId();
        if (is_string($providerId) && $providerId !== '') {
            $this->gptLiveClient->closeSession($providerId);
        }
        $session->close('idle_timeout', true);
        $this->events->publish($session->getIntake(), 'voice_session.updated', [
            'voice_session_id' => $session->getId(),
            'status' => $session->getStatus(),
            'close_reason' => 'idle_timeout',
        ]);
        $this->entityManager()->flush();
        $this->log($output, 'Closed '.$session->getId().' after idle timeout');
    }

    /**
     * @param-out string $transcript
     */
    private function drainUntilOutputOrTimeout(
        WebSocketClient $client,
        OutputInterface $output,
        string &$transcript,
        int &$audioChunks,
        float $seconds,
    ): bool {
        $deadline = microtime(true) + $seconds;
        while (microtime(true) < $deadline) {
            try {
                $message = $client->receive();
            } catch (ConnectionTimeoutException) {
                continue;
            } catch (ConnectionClosedException $exception) {
                throw $exception;
            }
            $payload = SidebandPayload::decode($message);
            if ($payload === null) {
                continue;
            }
            $type = (string) ($payload['type'] ?? '');
            if ($this->isNoisySidebandType($type)) {
                if ($type === 'session.output_audio.delta') {
                    ++$audioChunks;

                    return true;
                }
                continue;
            }
            $this->log($output, 'pre-greet event '.$type);
            if ($type === 'session.output_transcript.delta') {
                return true;
            }
            if (in_array($type, ['session.instructions.appended', 'session.commentary.appended'], true)) {
                return true;
            }
            if ($type === 'session.closed') {
                $this->log($output, 'session.closed during greeting drain');

                return true;
            }
            if ($type === 'session.input_transcript.delta') {
                $transcript .= (string) ($payload['delta'] ?? '');
            }
        }

        return false;
    }

    private function requestGreeting(WebSocketClient $client, VoiceSession $session, OutputInterface $output): void
    {
        $intake = $session->getIntake();
        $this->entityManager()->refresh($intake);
        $opening = (string) ($intake->document()->nextQuestion['text'] ?? '');
        $language = $intake->getConversationLanguage();
        $spoken = LiveGreeting::spoken($opening, $language);
        $instructionId = \App\Domain\IdGenerator::prefixed('evt');
        $this->sendEvent($client, [
            'type' => 'session.instructions.append',
            'event_id' => $instructionId,
            'delegation_id' => null,
            'content' => LiveGreeting::instructions($spoken, $language),
        ]);
        $acked = $this->awaitClientAck($client, $output, $instructionId, 'session.instructions.appended', 2);
        $this->sendEvent($client, [
            'type' => 'session.commentary.append',
            'event_id' => \App\Domain\IdGenerator::prefixed('evt'),
            'delegation_id' => null,
            'content' => LiveGreeting::commentary($spoken),
        ]);
        $this->log($output, 'Requested greeting ack='.($acked ? 'yes' : 'timeout').' text='.$this->clip($spoken));
    }

    private function awaitClientAck(
        WebSocketClient $client,
        OutputInterface $output,
        string $eventId,
        string $expectedType,
        float $seconds,
    ): bool {
        $deadline = microtime(true) + $seconds;
        $client->setTimeout(1);
        while (microtime(true) < $deadline) {
            try {
                $message = $client->receive();
            } catch (ConnectionTimeoutException) {
                continue;
            }
            $payload = SidebandPayload::decode($message);
            if ($payload === null) {
                continue;
            }
            $type = (string) ($payload['type'] ?? '');
            $clientEventId = (string) ($payload['client_event_id'] ?? $payload['error']['client_event_id'] ?? '');
            if ($type === $expectedType && $clientEventId === $eventId) {
                $this->log($output, 'Ack '.$expectedType.' for '.$eventId);

                return true;
            }
            if ($type === 'error' && $clientEventId === $eventId) {
                $this->log($output, 'Greeting rejected: '.$this->clip((string) ($payload['error']['message'] ?? json_encode($payload))));

                return false;
            }
            if ($type !== 'session.output_audio.delta' && !$this->isNoisySidebandType($type)) {
                $this->log($output, 'event '.$type.' while waiting for '.$expectedType);
            }
        }
        $this->log($output, 'No '.$expectedType.' ack for '.$eventId.'; sending commentary anyway');

        return false;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function sendEvent(WebSocketClient $client, array $payload): void
    {
        $client->text(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private function log(OutputInterface $output, string $message): void
    {
        $line = '['.gmdate('Y-m-d H:i:s').'] '.$message;
        $output->writeln($line);
        if ($output instanceof StreamOutput) {
            $stream = $output->getStream();
            if (is_resource($stream)) {
                fflush($stream);
            }
        }
    }

    private function clip(string $text): string
    {
        $text = preg_replace('/\b[1-9][0-9]{3}\s?[A-Za-z]{2}\b/', '**** **', $text) ?? $text;
        $text = preg_replace('/\s+/', ' ', trim($text)) ?? $text;

        return mb_strlen($text) > 160 ? mb_substr($text, 0, 157).'...' : $text;
    }

    private function isNoisySidebandType(string $type): bool
    {
        return in_array($type, [
            'session.input_audio.append',
            'session.output_audio.append',
            'session.input_audio.delta',
            'session.output_audio.delta',
        ], true);
    }
}
