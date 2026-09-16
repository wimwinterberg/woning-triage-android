<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Writes API timing for non-GET work. Voice analysis is logged by live-gateway.
 * Successful GET polls are skipped so docker logs stay readable.
 */
final class ApiRequestLogSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly KernelInterface $kernel)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onRequest', 1024],
            KernelEvents::TERMINATE => 'onTerminate',
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || $this->kernel->getEnvironment() === 'test') {
            return;
        }
        $event->getRequest()->attributes->set('_wt_started', microtime(true));
    }

    public function onTerminate(TerminateEvent $event): void
    {
        if ($this->kernel->getEnvironment() === 'test') {
            return;
        }
        $request = $event->getRequest();
        $path = $request->getPathInfo();
        if (in_array($path, ['/health', '/api/v1/health', '/'], true)) {
            return;
        }
        if ($request->getMethod() === 'GET' && $event->getResponse()->getStatusCode() < 400) {
            return;
        }
        $started = $request->attributes->get('_wt_started');
        $ms = is_numeric($started) ? (int) round((microtime(true) - (float) $started) * 1000) : 0;
        \App\Http\OperationalLog::write(sprintf(
            'api timing %s %s %d %dms',
            $request->getMethod(),
            $path,
            $event->getResponse()->getStatusCode(),
            $ms,
        ));
    }
}
