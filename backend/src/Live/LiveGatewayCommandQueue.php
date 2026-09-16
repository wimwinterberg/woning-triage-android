<?php

declare(strict_types=1);

namespace App\Live;

use App\Doctrine\OpenEntityManager;
use App\Entity\VoiceSession;

/**
 * Voice-session IDs that a long-running worker should attach to.
 *
 * Uses PostgreSQL instead of a local directory so the App Platform web
 * service and live-gateway worker (separate filesystems) stay in sync.
 */
final class LiveGatewayCommandQueue
{
    public function __construct(private readonly OpenEntityManager $entityManagers)
    {
    }

    public function enqueue(string $voiceSessionId): void
    {
        // The VoiceSession row is already flushed; pending() reads PostgreSQL.
        unset($voiceSessionId);
    }

    /**
     * @return list<string>
     */
    public function pending(): array
    {
        /** @var list<string> $ids */
        $ids = $this->entityManagers->get()->createQuery(
            'SELECT v.id FROM App\\Entity\\VoiceSession v
             WHERE v.status IN (:statuses)
               AND v.providerSessionId IS NOT NULL
               AND v.providerSessionId NOT LIKE :fakePrefix
               AND v.expiresAt > :now
             ORDER BY v.createdAt DESC'
        )
            ->setParameter('statuses', [VoiceSession::CONNECTING, VoiceSession::ACTIVE])
            ->setParameter('fakePrefix', 'prov_fake_%')
            ->setParameter('now', new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->getSingleColumnResult();

        return $ids;
    }

    public function ack(string $voiceSessionId): void
    {
        // Closed/failed sessions drop out of pending(); nothing to delete.
        unset($voiceSessionId);
    }
}
