<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Exception\ApiException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

final class ApiExceptionSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onRequest', 256],
            KernelEvents::RESPONSE => ['onResponse', -256],
            KernelEvents::EXCEPTION => ['onException', 0],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $requestId = $request->headers->get('X-Request-Id') ?: bin2hex(random_bytes(8));
        $request->attributes->set('request_id', $requestId);
    }

    public function onResponse(ResponseEvent $event): void
    {
        $requestId = $event->getRequest()->attributes->get('request_id');
        if (is_string($requestId)) {
            $event->getResponse()->headers->set('X-Request-Id', $requestId);
        }
    }

    public function onException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        if (!str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }
        $requestId = (string) $request->attributes->get('request_id', 'unknown');
        $exception = $event->getThrowable();

        if ($exception instanceof ApiException) {
            $payload = [
                'error' => array_merge([
                    'code' => $exception->errorCode,
                    'message' => $exception->getMessage(),
                    'request_id' => $requestId,
                ], $exception->extra),
            ];
            $event->setResponse(new JsonResponse($payload, $exception->statusCode));

            return;
        }

        if ($exception instanceof AuthenticationException) {
            $event->setResponse(new JsonResponse([
                'error' => [
                    'code' => 'unauthorized',
                    'message' => 'Toegangstoken ontbreekt of is ongeldig.',
                    'request_id' => $requestId,
                ],
            ], 401));

            return;
        }

        $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500;
        $code = $status === 404 ? 'not_found' : ($status === 405 ? 'method_not_allowed' : 'internal_error');
        $message = $status >= 500
            ? 'Er ging iets mis. Probeer het later opnieuw.'
            : $exception->getMessage();
        $event->setResponse(new JsonResponse([
            'error' => [
                'code' => $code,
                'message' => $message,
                'request_id' => $requestId,
            ],
        ], $status));
    }
}
