<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\IdGenerator;
use App\Entity\Intake;
use App\Entity\IntakeEvent;
use Doctrine\ORM\EntityManagerInterface;

final class IntakeEventPublisher
{
    /** @var array<string, int> */
    private array $lastSequence = [];

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function publish(Intake $intake, string $type, array $payload = []): IntakeEvent
    {
        $id = $intake->getId();
        if (!isset($this->lastSequence[$id])) {
            $max = (int) $this->entityManager->createQuery('SELECT MAX(e.sequence) FROM App\\Entity\\IntakeEvent e WHERE e.intake = :i')
                ->setParameter('i', $intake)
                ->getSingleScalarResult();
            $this->lastSequence[$id] = $max;
        }
        ++$this->lastSequence[$id];

        $event = new IntakeEvent(
            IdGenerator::prefixed('evt'),
            $intake,
            $this->lastSequence[$id],
            $type,
            $intake->getRevision(),
            $payload,
        );
        $this->entityManager->persist($event);

        return $event;
    }

    /**
     * @return list<IntakeEvent>
     */
    public function after(Intake $intake, int $lastSequence): array
    {
        /** @var list<IntakeEvent> $events */
        $events = $this->entityManager->createQuery('SELECT e FROM App\\Entity\\IntakeEvent e WHERE e.intake = :i AND e.sequence > :s ORDER BY e.sequence ASC')
            ->setParameter('i', $intake)
            ->setParameter('s', $lastSequence)
            ->getResult();

        return $events;
    }
}
