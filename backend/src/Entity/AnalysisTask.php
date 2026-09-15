<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'analysis_task')]
#[ORM\Index(columns: ['intake_id', 'status'])]
class AnalysisTask
{
    public const PENDING = 'pending';
    public const RUNNING = 'running';
    public const SUCCEEDED = 'succeeded';
    public const FAILED = 'failed';
    public const SUPERSEDED = 'superseded';
    public const CANCELLED = 'cancelled';

    #[ORM\Id]
    #[ORM\Column(length: 64)]
    private string $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Intake $intake;

    #[ORM\Column(length: 32)]
    private string $type;

    #[ORM\Column(length: 32)]
    private string $status = self::PENDING;

    #[ORM\Column]
    private int $baseRevision;

    #[ORM\Column(nullable: true)]
    private ?int $resultRevision = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $error = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    public function __construct(string $id, Intake $intake, string $type, int $baseRevision)
    {
        $this->id = $id;
        $this->intake = $intake;
        $this->type = $type;
        $this->baseRevision = $baseRevision;
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getIntake(): Intake
    {
        return $this->intake;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getBaseRevision(): int
    {
        return $this->baseRevision;
    }

    public function getResultRevision(): ?int
    {
        return $this->resultRevision;
    }

    public function markRunning(): void
    {
        $this->status = self::RUNNING;
    }

    public function succeed(int $resultRevision): void
    {
        $this->status = self::SUCCEEDED;
        $this->resultRevision = $resultRevision;
        $this->finishedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function fail(string $code, string $message): void
    {
        $this->status = self::FAILED;
        $this->error = ['code' => $code, 'message' => $message];
        $this->finishedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function supersede(): void
    {
        if (in_array($this->status, [self::PENDING, self::RUNNING], true)) {
            $this->status = self::SUPERSEDED;
            $this->finishedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        }
    }

    public function cancel(): void
    {
        if (in_array($this->status, [self::PENDING, self::RUNNING], true)) {
            $this->status = self::CANCELLED;
            $this->finishedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        }
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::PENDING, self::RUNNING], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'task_id' => $this->id,
            'type' => $this->type,
            'status' => $this->status,
            'base_revision' => $this->baseRevision,
            'result_revision' => $this->resultRevision,
            'error' => $this->error,
        ];
    }
}
