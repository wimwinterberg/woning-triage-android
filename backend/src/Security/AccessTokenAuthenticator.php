<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\AccessToken;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

final class AccessTokenAuthenticator extends AbstractAuthenticator
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function supports(Request $request): ?bool
    {
        return str_starts_with($request->getPathInfo(), '/api/')
            && $request->headers->has('Authorization');
    }

    public function authenticate(Request $request): Passport
    {
        $header = $request->headers->get('Authorization', '');
        if (!preg_match('/^Bearer\s+(\S+)$/', $header, $matches)) {
            throw new AuthenticationException('Missing bearer token');
        }
        $hash = hash('sha256', $matches[1]);
        $token = $this->entityManager->getRepository(AccessToken::class)->findOneBy(['tokenHash' => $hash]);
        if (!$token instanceof AccessToken || !$token->isActive()) {
            throw new AuthenticationException('Invalid token');
        }
        $user = $token->getUser();

        return new SelfValidatingPassport(new UserBadge($user->getUserIdentifier(), static fn (): \App\Entity\User => $user));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return $this->onAuthenticationFailure($request, $authException ?? new AuthenticationException('Unauthorized'));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new JsonResponse([
            'error' => [
                'code' => 'unauthorized',
                'message' => 'Toegangstoken ontbreekt of is ongeldig.',
                'request_id' => $request->attributes->get('request_id', 'unknown'),
            ],
        ], 401);
    }
}
