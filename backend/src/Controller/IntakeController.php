<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Exception\AddressLookupUnavailableException;
use App\Exception\ApiException;
use App\Exception\BadRequestException;
use App\Http\JsonBody;
use App\Http\OperationalLog;
use App\Service\IdempotencyService;
use App\Service\IntakeEventPublisher;
use App\Service\IntakeService;
use App\Service\LiveSessionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/v1/intakes')]
final class IntakeController extends AbstractController
{
    public function __construct(
        private readonly IntakeService $intakeService,
        private readonly LiveSessionService $liveSessionService,
        private readonly IdempotencyService $idempotency,
        private readonly IntakeEventPublisher $events,
        private readonly KernelInterface $kernel,
    ) {
    }

    #[Route('', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $body = JsonBody::parse($request);
        $key = $this->idempotency->requireKey($request);
        $existing = $this->idempotency->find($user->getId(), 'none', 'create_intake', $key, $body);
        if ($existing !== null) {
            return new JsonResponse($existing->getResponseBody(), $existing->getStatusCode());
        }
        $intake = $this->intakeService->create($user, (string) ($body['input_mode'] ?? 'text'));
        $payload = $this->intakeService->present($intake);
        $this->idempotency->store($user->getId(), 'none', 'create_intake', $key, $body, 201, $payload);

        return new JsonResponse($payload, 201);
    }

    #[Route('/{id}', methods: ['GET'])]
    public function show(string $id, #[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse($this->intakeService->present($this->intakeService->getOwned($id, $user)));
    }

    #[Route('/{id}/messages', methods: ['POST'])]
    public function message(string $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $intake = $this->intakeService->getOwned($id, $user);
        $body = JsonBody::parse($request);
        $key = $this->idempotency->requireKey($request);
        $existing = $this->idempotency->find($user->getId(), $id, 'message', $key, $body);
        if ($existing !== null) {
            return new JsonResponse($existing->getResponseBody(), $existing->getStatusCode());
        }
        $result = $this->intakeService->addMessage(
            $intake,
            $this->intValue($body, 'expected_revision'),
            (string) ($body['client_message_id'] ?? ''),
            (string) ($body['text'] ?? ''),
        );
        $this->idempotency->store($user->getId(), $id, 'message', $key, $body, 202, $result);

        return new JsonResponse($result, 202);
    }

    #[Route('/{id}/fields', methods: ['PATCH'])]
    public function fields(string $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $intake = $this->intakeService->getOwned($id, $user);
        $body = JsonBody::parse($request);
        $key = $this->idempotency->requireKey($request);
        $existing = $this->idempotency->find($user->getId(), $id, 'fields', $key, $body);
        if ($existing !== null) {
            return new JsonResponse($existing->getResponseBody(), $existing->getStatusCode());
        }
        $changes = $body['changes'] ?? [];
        if (!is_array($changes)) {
            throw new BadRequestException('changes ontbreekt.');
        }
        $intake = $this->intakeService->correctFields($intake, $this->intValue($body, 'expected_revision'), $changes);
        $payload = $this->intakeService->present($intake);
        $this->idempotency->store($user->getId(), $id, 'fields', $key, $body, 200, $payload);

        return new JsonResponse($payload);
    }

    #[Route('/{id}/language', methods: ['PATCH'])]
    public function language(string $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $intake = $this->intakeService->getOwned($id, $user);
        $body = JsonBody::parse($request);
        $key = $this->idempotency->requireKey($request);
        $existing = $this->idempotency->find($user->getId(), $id, 'language', $key, $body);
        if ($existing !== null) {
            return new JsonResponse($existing->getResponseBody(), $existing->getStatusCode());
        }
        $intake = $this->intakeService->changeLanguage(
            $intake,
            $this->intValue($body, 'expected_revision'),
            (string) ($body['mode'] ?? ''),
            isset($body['language']) ? (string) $body['language'] : null,
        );
        $payload = $this->intakeService->present($intake);
        $this->idempotency->store($user->getId(), $id, 'language', $key, $body, 200, $payload);

        return new JsonResponse($payload);
    }

    #[Route('/{id}/summaries', methods: ['POST'])]
    public function summaries(string $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $intake = $this->intakeService->getOwned($id, $user);
        $body = JsonBody::parse($request);
        $key = $this->idempotency->requireKey($request);
        $existing = $this->idempotency->find($user->getId(), $id, 'summary', $key, $body);
        if ($existing !== null) {
            return new JsonResponse($existing->getResponseBody(), $existing->getStatusCode());
        }
        $result = $this->intakeService->requestSummary($intake, $this->intValue($body, 'expected_revision'));
        $this->idempotency->store($user->getId(), $id, 'summary', $key, $body, 202, $result);

        return new JsonResponse($result, 202);
    }

    #[Route('/{id}/confirmations', methods: ['POST'])]
    public function confirm(string $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $intake = $this->intakeService->getOwned($id, $user);
        $body = JsonBody::parse($request);
        $key = $this->idempotency->requireKey($request);
        $existing = $this->idempotency->find($user->getId(), $id, 'confirm', $key, $body);
        if ($existing !== null) {
            return new JsonResponse($existing->getResponseBody(), $existing->getStatusCode());
        }
        $intake = $this->intakeService->confirm(
            $intake,
            $this->intValue($body, 'expected_revision'),
            (string) ($body['summary_id'] ?? ''),
            (string) ($body['confirmation_channel'] ?? 'ui'),
            isset($body['evidence_message_id']) ? (string) $body['evidence_message_id'] : null,
        );
        $payload = $this->intakeService->present($intake);
        $this->idempotency->store($user->getId(), $id, 'confirm', $key, $body, 200, $payload);

        return new JsonResponse($payload);
    }

    #[Route('/{id}/address-lookups', methods: ['POST'])]
    public function addressLookup(string $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $body = [];
        try {
            $intake = $this->intakeService->getOwned($id, $user);
            $body = JsonBody::parse($request);
            OperationalLog::write(sprintf(
                'address-lookup start intake=%s gps=%d nearby=%d revision=%d expected=%s',
                $id,
                array_key_exists('latitude', $body) || array_key_exists('longitude', $body) ? 1 : 0,
                is_array($body['nearby'] ?? null) ? count($body['nearby']) : 0,
                $intake->getRevision(),
                is_numeric($body['expected_revision'] ?? null) ? (string) (int) $body['expected_revision'] : 'missing',
            ));
            $key = $this->idempotency->requireKey($request);
            $existing = $this->idempotency->find($user->getId(), $id, 'address_lookup', $key, $body);
            if ($existing !== null) {
                return new JsonResponse($existing->getResponseBody(), $existing->getStatusCode());
            }
            $result = $this->intakeService->lookupAddress(
                $intake,
                $this->intValue($body, 'expected_revision'),
                $body,
            );
            $this->idempotency->store($user->getId(), $id, 'address_lookup', $key, $body, 200, $result);

            return new JsonResponse($result);
        } catch (ApiException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            OperationalLog::write(sprintf(
                'address-lookup crash exception=%s file=%s line=%d gps=%d',
                $exception::class,
                $exception->getFile(),
                $exception->getLine(),
                array_key_exists('latitude', $body) || array_key_exists('longitude', $body) ? 1 : 0,
            ));
            throw new AddressLookupUnavailableException();
        }
    }

    #[Route('/{id}/address-verifications', methods: ['POST'])]
    public function addressVerify(string $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $intake = $this->intakeService->getOwned($id, $user);
        $body = JsonBody::parse($request);
        $key = $this->idempotency->requireKey($request);
        $existing = $this->idempotency->find($user->getId(), $id, 'address_verify', $key, $body);
        if ($existing !== null) {
            return new JsonResponse($existing->getResponseBody(), $existing->getStatusCode());
        }
        $intake = $this->intakeService->verifyAddress(
            $intake,
            $this->intValue($body, 'expected_revision'),
            (string) ($body['lookup_id'] ?? ''),
            (string) ($body['candidate_id'] ?? ''),
            $this->intValue($body, 'address_revision'),
            (string) ($body['confirmation_channel'] ?? 'ui'),
            isset($body['evidence_message_id']) ? (string) $body['evidence_message_id'] : null,
        );
        $payload = $this->intakeService->present($intake);
        $this->idempotency->store($user->getId(), $id, 'address_verify', $key, $body, 200, $payload);

        return new JsonResponse($payload);
    }

    #[Route('/{id}/cancel', methods: ['POST'])]
    public function cancel(string $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $intake = $this->intakeService->getOwned($id, $user);
        $body = JsonBody::parse($request);
        $key = $this->idempotency->requireKey($request);
        $existing = $this->idempotency->find($user->getId(), $id, 'cancel', $key, $body);
        if ($existing !== null) {
            return new JsonResponse($existing->getResponseBody(), $existing->getStatusCode());
        }
        $intake = $this->intakeService->cancel($intake, $this->intValue($body, 'expected_revision'));
        $payload = $this->intakeService->present($intake);
        $this->idempotency->store($user->getId(), $id, 'cancel', $key, $body, 200, $payload);

        return new JsonResponse($payload);
    }

    #[Route('/{id}/voice-sessions', methods: ['POST'])]
    public function startVoice(string $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $intake = $this->intakeService->getOwned($id, $user);
        $body = JsonBody::parse($request);
        $key = $this->idempotency->requireKey($request);
        $existing = $this->idempotency->find($user->getId(), $id, 'voice_start', $key, $body);
        if ($existing !== null) {
            return new JsonResponse($existing->getResponseBody(), $existing->getStatusCode());
        }
        $session = $this->liveSessionService->start($intake, (string) ($body['sdp_offer'] ?? ''));
        $payload = $session->toCreateArray();
        $this->idempotency->store($user->getId(), $id, 'voice_start', $key, $body, 201, $payload);

        return new JsonResponse($payload, 201);
    }

    #[Route('/{id}/voice-sessions/{sessionId}', methods: ['GET'])]
    public function voiceStatus(string $id, string $sessionId, #[CurrentUser] User $user): JsonResponse
    {
        $intake = $this->intakeService->getOwned($id, $user);
        $session = $this->liveSessionService->getOwned($intake, $sessionId);

        return new JsonResponse($session->toStatusArray());
    }

    #[Route('/{id}/voice-sessions/{sessionId}/stop', methods: ['POST'])]
    public function stopVoice(string $id, string $sessionId, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $intake = $this->intakeService->getOwned($id, $user);
        $body = JsonBody::parse($request);
        $key = $this->idempotency->requireKey($request);
        $existing = $this->idempotency->find($user->getId(), $id, 'voice_stop', $key, $body);
        if ($existing !== null) {
            return new JsonResponse($existing->getResponseBody(), $existing->getStatusCode());
        }
        $session = $this->liveSessionService->stop($this->liveSessionService->getOwned($intake, $sessionId));
        $payload = $session->toStatusArray();
        $this->idempotency->store($user->getId(), $id, 'voice_stop', $key, $body, 202, $payload);

        return new JsonResponse($payload, 202);
    }

    #[Route('/{id}/tasks/{taskId}', methods: ['GET'])]
    public function task(string $id, string $taskId, #[CurrentUser] User $user): JsonResponse
    {
        $intake = $this->intakeService->getOwned($id, $user);

        return new JsonResponse($this->intakeService->getTask($intake, $taskId)->toArray());
    }

    #[Route('/{id}/events', methods: ['GET'])]
    public function events(string $id, Request $request, #[CurrentUser] User $user): StreamedResponse
    {
        $intake = $this->intakeService->getOwned($id, $user);
        $last = (int) ($request->headers->get('Last-Event-ID') ?: 0);
        $isTest = $this->kernel->getEnvironment() === 'test';

        $response = new StreamedResponse(function () use ($intake, $last, $isTest): void {
            $cursor = $last;
            $events = $this->events->after($intake, $cursor);
            if ($events === [] && $last > 0 && $this->events->after($intake, 0) !== [] && $last > 10_000) {
                echo "event: session.reset_required\ndata: ".json_encode(['intake_id' => $intake->getId()], JSON_THROW_ON_ERROR)."\n\n";
                flush();

                return;
            }
            foreach ($events as $event) {
                echo 'id: '.$event->getSequence()."\n";
                echo 'event: '.$event->getType()."\n";
                echo 'data: '.json_encode($event->toSsePayload(), JSON_THROW_ON_ERROR)."\n\n";
                $cursor = $event->getSequence();
            }
            if ($isTest) {
                return;
            }
            $loops = 0;
            while ($loops++ < 25) {
                sleep(1);
                foreach ($this->events->after($intake, $cursor) as $event) {
                    echo 'id: '.$event->getSequence()."\n";
                    echo 'event: '.$event->getType()."\n";
                    echo 'data: '.json_encode($event->toSsePayload(), JSON_THROW_ON_ERROR)."\n\n";
                    $cursor = $event->getSequence();
                }
                echo ": keepalive\n\n";
                flush();
            }
        });
        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function intValue(array $body, string $key): int
    {
        if (!array_key_exists($key, $body) || !is_numeric($body[$key])) {
            throw new BadRequestException($key.' ontbreekt of is ongeldig.', 'invalid_value');
        }

        return (int) $body[$key];
    }
}
