<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'intake_event')]
#[ORM\UniqueConstraint(name: 'uniq_intake_event_seq', columns: ['intake_id', 'sequence'])]
class IntakeEvent
{
    #[ORM\Id]
    #[ORM\Column(length: 64)]
    private string $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Intake $intake;

    #[ORM\Column]
    private int $sequence;

    #[ORM\Column(length: 64)]
    private string $type;

    #[ORM\Column]
    private int $revision;

    #[ORM\Column(type: Types::JSON)]
    private array $payload = [];

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(string $id, Intake $intake, int $sequence, string $type, int $revision, array $payload)
    {
        $this->id = $id;
        $this->intake = $intake;
        $this->sequence = $sequence;
        $this->type = $type;
        $this->revision = $revision;
        $this->payload = $payload;
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getSequence(): int
    {
        return $this->sequence;
    }

    public function getType(): string
    {
        return $this->type;
    }

    /**
     * @return array<string, mixed>
     */
    public function toSsePayload(): array
    {
        return array_merge([
            'sequence' => $this->sequence,
            'intake_id' => $this->intake->getId(),
            'revision' => $this->revision,
        ], $this->payload);
    }
}
