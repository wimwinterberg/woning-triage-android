<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'intake_message')]
#[ORM\Index(columns: ['intake_id', 'sequence'])]
class IntakeMessage
{
    #[ORM\Id]
    #[ORM\Column(length: 64)]
    private string $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Intake $intake;

    #[ORM\Column]
    private int $sequence;

    #[ORM\Column(length: 16)]
    private string $speaker;

    #[ORM\Column(length: 32)]
    private string $language;

    #[ORM\Column(type: Types::TEXT)]
    private string $text;

    #[ORM\Column(length: 32)]
    private string $origin;

    #[ORM\Column]
    private bool $provisional = false;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $id,
        Intake $intake,
        int $sequence,
        string $speaker,
        string $language,
        string $text,
        string $origin,
        bool $provisional = false,
    ) {
        $this->id = $id;
        $this->intake = $intake;
        $this->sequence = $sequence;
        $this->speaker = $speaker;
        $this->language = $language;
        $this->text = $text;
        $this->origin = $origin;
        $this->provisional = $provisional;
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): string
    {
        return $this->id;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'sequence' => $this->sequence,
            'speaker' => $this->speaker,
            'language' => $this->language,
            'text' => $this->text,
            'origin' => $this->origin,
            'provisional' => $this->provisional,
            'created_at' => $this->createdAt->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM),
        ];
    }
}
