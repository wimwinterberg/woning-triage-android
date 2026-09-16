<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\VoiceSession;
use App\Live\LiveGatewayCommandQueue;
use App\Live\SidebandPayload;
use App\Service\IntakeService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;
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
        private readonly EntityManagerInterface $entityManager,
        private readonly IntakeService $intakeService,
        private readonly ?string $apiKey,
    ) {
        parent::__construct();
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
        $idleLoggedAt = 0;
        while (true) {
            $pending = $this->queue->pending();
            if ($pending === [] && time() - $idleLoggedAt >= 30) {
                $this->log($output, 'No open live voice sessions.');
                $idleLoggedAt = time();
            }
            foreach ($pending as $voiceSessionId) {
                $session = $this->entityManager->find(VoiceSession::class, $voiceSessionId);
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
                $this->entityManager->clear();
            }
            sleep(2);
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
        $client->setTimeout(20);
        $this->log($output, 'Connecting sideband '.$session->getId().' provider='.$session->getProviderSessionId());
        $client->connect();
        $this->log($output, 'Attached sideband for '.$session->getId());
        $transcript = '';
        $audioChunks = 0;
        $greeted = false;
        while ($session->isOpen() || $session->getStatus() === VoiceSession::CLOSING) {
            try {
                $message = $client->receive();
            } catch (ConnectionTimeoutException) {
                $this->entityManager->refresh($session);
                if (!$greeted) {
                    $this->requestGreeting($client, $session, $output);
                    $greeted = true;
                }
                $this->log($output, 'Waiting on '.$session->getId().' status='.$session->getStatus().' transcript_chars='.mb_strlen($transcript));
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
            if (!$greeted && in_array($type, ['session.started', 'session.updated'], true)) {
                $this->requestGreeting($client, $session, $output);
                $greeted = true;
            }
            if ($type === 'session.output_audio.delta') {
                ++$audioChunks;
                continue;
            }
            if ($type === 'session.input_transcript.delta') {
                $delta = (string) ($payload['delta'] ?? '');
                $transcript .= $delta;
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
                $this->handleDelegation($session, $client, $output, $payload, $transcript);
                $transcript = '';
                continue;
            }
            if ($type !== '') {
                $this->log($output, 'event '.$type.' keys='.implode(',', array_keys($payload)));
            }
            $this->entityManager->refresh($session);
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
    private function handleDelegation(VoiceSession $session, WebSocketClient $client, OutputInterface $output, array $payload, string $transcript): void
    {
        $delegationId = (string) ($payload['delegation']['id'] ?? '');
        $intake = $session->getIntake();
        $this->entityManager->refresh($intake);
        $this->log($output, 'delegation '.$delegationId.' transcript_chars='.mb_strlen($transcript).' text='.$this->clip($transcript));
        $this->sendEvent($client, [
            'type' => 'session.thinking.append',
            'event_id' => \App\Domain\IdGenerator::prefixed('evt'),
            'delegation_id' => $delegationId !== '' ? $delegationId : null,
            'content' => 'De backend zoekt of werkt het dossier bij. Wacht op het resultaat voordat je verder vraagt.',
        ]);
        $started = microtime(true);
        if (trim($transcript) !== '') {
            try {
                $task = new \App\Entity\AnalysisTask(
                    \App\Domain\IdGenerator::prefixed('task'),
                    $intake,
                    'analyze',
                    $intake->getRevision(),
                );
                $this->entityManager->persist($task);
                $this->entityManager->flush();
                $this->intakeService->runAnalysis(
                    $intake,
                    $task,
                    $transcript,
                    \App\Domain\IdGenerator::prefixed('message'),
                );
                $this->entityManager->refresh($intake);
            } catch (\Throwable $exception) {
                $this->log($output, 'analysis failed: '.$exception->getMessage());
            }
        } else {
            $this->log($output, 'delegation without transcript; sending current question so GPT-Live does not wait');
        }
        $next = $intake->document()->nextQuestion['text'] ?? 'Gegevens zijn bijgewerkt.';
        $ms = (int) round((microtime(true) - $started) * 1000);
        $this->sendEvent($client, [
            'type' => 'session.commentary.append',
            'event_id' => \App\Domain\IdGenerator::prefixed('evt'),
            'delegation_id' => $delegationId !== '' ? $delegationId : null,
            'content' => 'Zeg nu hardop tegen de bewoner, in het Nederlands: '.$next,
        ]);
        $this->log($output, 'commentary sent in '.$ms.'ms question='.$this->clip($next));
    }

    private function requestGreeting(WebSocketClient $client, VoiceSession $session, OutputInterface $output): void
    {
        $intake = $session->getIntake();
        $this->entityManager->refresh($intake);
        $opening = $intake->document()->nextQuestion['text'] ?? 'Wat is er aan de hand in uw huurwoning?';
        $this->sendEvent($client, [
            'type' => 'session.instructions.append',
            'event_id' => \App\Domain\IdGenerator::prefixed('evt'),
            'delegation_id' => null,
            'content' => 'Spreek nu Nederlands. Begroet meteen, wacht niet tot de bewoner iets zegt. Dit is een huurwoning; vraag nooit of het huur of koop is. Zeg daarna deze vraag en luister: '.$opening,
        ]);
        $this->sendEvent($client, [
            'type' => 'session.commentary.append',
            'event_id' => \App\Domain\IdGenerator::prefixed('evt'),
            'delegation_id' => null,
            'content' => 'Begin het gesprek nu. Zeg de welkomstvraag hardop.',
        ]);
        $this->log($output, 'Requested greeting: '.$this->clip($opening));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function sendEvent(WebSocketClient $client, array $payload): void
    {
        $client->text(json_encode($payload, JSON_THROW_ON_ERROR));
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
        $text = preg_replace('/\s+/', ' ', trim($text)) ?? $text;

        return mb_strlen($text) > 160 ? mb_substr($text, 0, 157).'...' : $text;
    }
}
