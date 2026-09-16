<?php

declare(strict_types=1);

namespace App\Entity;

use App\Domain\IntakeDocument;
use App\Domain\IntakeStatus;
use App\Domain\LanguageMode;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'intake')]
#[ORM\Index(columns: ['owner_id'])]
class Intake
{
    #[ORM\Id]
    #[ORM\Column(length: 64)]
    private string $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    #[ORM\Column]
    private int $revision = 0;

    #[ORM\Column(length: 32)]
    private string $status = 'collecting';

    #[ORM\Column(length: 64)]
    private string $treeVersion;

    #[ORM\Column(length: 64)]
    private string $promptVersion;

    #[ORM\Column(length: 32)]
    private string $conversationLanguage = 'nl-NL';

    #[ORM\Column(length: 16)]
    private string $languageMode = 'auto';

    #[ORM\Column(type: Types::JSON)]
    private array $document = [];

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $reportId = null;

    #[ORM\Column]
    private bool $demo = true;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $confirmedAt = null;

    public function __construct(string $id, User $owner, string $treeVersion, string $promptVersion, IntakeDocument $document)
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->id = $id;
        $this->owner = $owner;
        $this->treeVersion = $treeVersion;
        $this->promptVersion = $promptVersion;
        $this->document = $document->toArray();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function getRevision(): int
    {
        return $this->revision;
    }

    public function getStatus(): IntakeStatus
    {
        return IntakeStatus::from($this->status);
    }

    public function getTreeVersion(): string
    {
        return $this->treeVersion;
    }

    public function getPromptVersion(): string
    {
        return $this->promptVersion;
    }

    public function getConversationLanguage(): string
    {
        return $this->conversationLanguage;
    }

    public function getLanguageMode(): LanguageMode
    {
        return LanguageMode::from($this->languageMode);
    }

    public function isDemo(): bool
    {
        return $this->demo;
    }

    public function getReportId(): ?string
    {
        return $this->reportId;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getConfirmedAt(): ?\DateTimeImmutable
    {
        return $this->confirmedAt;
    }

    public function document(): IntakeDocument
    {
        return IntakeDocument::fromArray($this->document);
    }

    public function replaceDocument(IntakeDocument $document): void
    {
        $this->document = $document->toArray();
        $this->touch();
    }

    public function bumpRevision(): int
    {
        ++$this->revision;
        $this->touch();

        return $this->revision;
    }

    public function assertExpectedRevision(int $expected): void
    {
        if ($this->revision !== $expected) {
            throw new \App\Exception\RevisionConflictException($this->revision);
        }
    }

    public function setStatus(IntakeStatus $status): void
    {
        $this->status = $status->value;
        $this->touch();
    }

    public function setConversationLanguage(string $language): void
    {
        $this->conversationLanguage = $language;
        $this->touch();
    }

    public function setLanguageMode(LanguageMode $mode): void
    {
        $this->languageMode = $mode->value;
        $this->touch();
    }

    public function confirm(string $reportId): void
    {
        $this->status = IntakeStatus::Confirmed->value;
        $this->reportId = $reportId;
        $this->confirmedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->bumpRevision();
    }

    public function belongsTo(User $user): bool
    {
        return $this->owner->getId() === $user->getId();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
