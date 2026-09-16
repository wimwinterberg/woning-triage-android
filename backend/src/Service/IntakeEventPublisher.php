<?php

declare(strict_types=1);

namespace App\Service;

use App\Doctrine\OpenEntityManager;
use App\Domain\IdGenerator;
use App\Entity\Intake;
use App\Entity\IntakeEvent;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;

final class IntakeEventPublisher
{
    private const MAX_ATTEMPTS = 8;

    public function __construct(private readonly OpenEntityManager $entityManagers)
    {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function publish(Intake $intake, string $type, array $payload = []): IntakeEvent
    {
        $conn = $this->entityManagers->get()->getConnection();
        $id = IdGenerator::prefixed('evt');
        $lastException = null;

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; ++$attempt) {
            $conn->beginTransaction();
            try {
                $conn->fetchOne('SELECT id FROM intake WHERE id = ? FOR UPDATE', [$intake->getId()]);
                $max = (int) $conn->fetchOne(
                    'SELECT COALESCE(MAX(sequence), 0) FROM intake_event WHERE intake_id = ?',
                    [$intake->getId()],
                );
                $event = new IntakeEvent(
                    $id,
                    $intake,
                    $max + 1,
                    $type,
                    $intake->getRevision(),
                    $payload,
                );
                $conn->insert('intake_event', [
                    'id' => $id,
                    'sequence' => $event->getSequence(),
                    'type' => $type,
                    'revision' => $intake->getRevision(),
                    'payload' => $payload,
                    'created_at' => $event->getCreatedAt(),
                    'intake_id' => $intake->getId(),
                ], [
                    'payload' => Types::JSON,
                    'created_at' => Types::DATETIME_IMMUTABLE,
                ]);
                $conn->commit();

                return $event;
            } catch (UniqueConstraintViolationException $exception) {
                $lastException = $exception;
                if ($conn->isTransactionActive()) {
                    $conn->rollBack();
                }
            } catch (\Throwable $exception) {
                if ($conn->isTransactionActive()) {
                    $conn->rollBack();
                }
                throw $exception;
            }
        }

        throw new \RuntimeException('Could not publish intake event after retries.', 0, $lastException);
    }

    /**
     * @return list<IntakeEvent>
     */
    public function after(Intake $intake, int $lastSequence): array
    {
        /** @var list<IntakeEvent> $events */
        $events = $this->entityManagers->get()->createQuery('SELECT e FROM App\\Entity\\IntakeEvent e WHERE e.intake = :i AND e.sequence > :s ORDER BY e.sequence ASC')
            ->setParameter('i', $intake)
            ->setParameter('s', $lastSequence)
            ->getResult();

        return $events;
    }
}
