<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'report')]
#[ORM\UniqueConstraint(name: 'uniq_report_intake', columns: ['intake_id'])]
class Report
{
    #[ORM\Id]
    #[ORM\Column(length: 64)]
    private string $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Intake $intake;

    #[ORM\Column]
    private int $sourceRevision;

    #[ORM\Column(type: Types::JSON)]
    private array $payload;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(string $id, Intake $intake, int $sourceRevision, array $payload)
    {
        $this->id = $id;
        $this->intake = $intake;
        $this->sourceRevision = $sourceRevision;
        $this->payload = $payload;
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): string
    {
        return $this->id;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayload(): array
    {
        return $this->payload;
    }
}
