<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\JsonBody;
use App\Service\AuthService;
use App\Service\IdempotencyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class AuthController extends AbstractController
{
    public function __construct(
        private readonly AuthService $authService,
        private readonly IdempotencyService $idempotency,
    ) {
    }

    #[Route('/api/v1/auth/activation', methods: ['POST'])]
    public function activate(Request $request): JsonResponse
    {
        $body = JsonBody::parse($request);
        $key = $this->idempotency->requireKey($request);
        $existing = $this->idempotency->find('public', 'none', 'activation', $key, $body);
        if ($existing !== null) {
            return new JsonResponse($existing->getResponseBody(), $existing->getStatusCode());
        }
        $result = $this->authService->activate((string) ($body['code'] ?? ''));
        $this->idempotency->store('public', 'none', 'activation', $key, $body, 200, $result);

        return new JsonResponse($result);
    }

    #[Route('/api/v1/me', methods: ['GET'])]
    public function me(#[CurrentUser] \App\Entity\User $user): JsonResponse
    {
        return new JsonResponse([
            'id' => $user->getId(),
            'label' => $user->getLabel(),
        ]);
    }
}
