<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\VoiceSession;
use App\Live\LiveGatewayCommandQueue;
use App\Service\IntakeService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use WebSocket\Client as WebSocketClient;
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
        if ($this->apiKey() === '') {
            $output->writeln('OPENAI_API_KEY is not set. Gateway idle; text intake still works.');
            $output->writeln('Set OPENAI_API_KEY in backend/.env and restart: docker compose --profile live up --force-recreate');
            while (true) {
                sleep(30);
            }
        }

        $output->writeln('Live gateway waiting for voice sessions. Ctrl+C to stop.');
        while (true) {
            foreach ($this->queue->pending() as $voiceSessionId) {
                $session = $this->entityManager->find(VoiceSession::class, $voiceSessionId);
                if (!$session instanceof VoiceSession || $session->getProviderSessionId() === null) {
                    $this->queue->ack($voiceSessionId);
                    continue;
                }
                try {
                    $this->attach($session, $output);
                } catch (\Throwable $exception) {
                    $output->writeln('Gateway error: '.$exception->getMessage());
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
        $client->connect();
        $output->writeln('Attached sideband for '.$session->getId());
        $transcript = '';
        while ($session->isOpen() || $session->getStatus() === VoiceSession::CLOSING) {
            $message = $client->receive();
            $payload = json_decode((string) $message, true);
            if (!is_array($payload)) {
                continue;
            }
            $type = $payload['type'] ?? '';
            if ($type === 'session.input_transcript.delta') {
                $transcript .= (string) ($payload['delta'] ?? '');
            }
            if ($type === 'session.delegation.created' && ($payload['delegation']['target'] ?? '') === 'client') {
                $delegationId = (string) ($payload['delegation']['id'] ?? '');
                $intake = $session->getIntake();
                $this->entityManager->refresh($intake);
                if ($transcript !== '') {
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
                    $next = $intake->document()->nextQuestion['text'] ?? 'Gegevens zijn bijgewerkt.';
                    $client->text(json_encode([
                        'type' => 'session.commentary.append',
                        'event_id' => \App\Domain\IdGenerator::prefixed('evt'),
                        'delegation_id' => $delegationId,
                        'content' => mb_substr($next, 0, 1500),
                    ], JSON_THROW_ON_ERROR));
                    $transcript = '';
                }
            }
            $this->entityManager->refresh($session);
            if (in_array($session->getStatus(), [VoiceSession::CLOSED, VoiceSession::FAILED], true)) {
                break;
            }
        }
        $client->close();
    }
}
