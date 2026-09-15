<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'voice_session')]
#[ORM\Index(columns: ['intake_id', 'status'])]
class VoiceSession
{
    public const CONNECTING = 'connecting';
    public const ACTIVE = 'active';
    public const CLOSING = 'closing';
    public const CLOSED = 'closed';
    public const FAILED = 'failed';

    #[ORM\Id]
    #[ORM\Column(length: 64)]
    private string $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Intake $intake;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $providerSessionId = null;

    #[ORM\Column(length: 32)]
    private string $status = self::CONNECTING;

    #[ORM\Column(length: 32)]
    private string $transport = 'webrtc';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $sdpAnswer = null;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $closeReason = null;

    #[ORM\Column]
    private bool $usageFinalized = false;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $id, Intake $intake, ?string $sdpAnswer, \DateTimeImmutable $expiresAt, ?string $providerSessionId = null)
    {
        $this->id = $id;
        $this->intake = $intake;
        $this->sdpAnswer = $sdpAnswer;
        $this->expiresAt = $expiresAt;
        $this->providerSessionId = $providerSessionId;
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

    public function getProviderSessionId(): ?string
    {
        return $this->providerSessionId;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getSdpAnswer(): ?string
    {
        return $this->sdpAnswer;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::CONNECTING, self::ACTIVE], true);
    }

    public function markActive(): void
    {
        $this->status = self::ACTIVE;
    }

    public function requestStop(): void
    {
        if ($this->isOpen()) {
            $this->status = self::CLOSING;
        }
    }

    public function close(string $reason, bool $usageFinalized): void
    {
        $this->status = self::CLOSED;
        $this->closeReason = $reason;
        $this->usageFinalized = $usageFinalized;
        $this->sdpAnswer = null;
    }

    public function fail(string $reason): void
    {
        $this->status = self::FAILED;
        $this->closeReason = $reason;
        $this->sdpAnswer = null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toCreateArray(): array
    {
        return [
            'id' => $this->id,
            'intake_id' => $this->intake->getId(),
            'status' => $this->status,
            'transport' => $this->transport,
            'sdp_answer' => $this->sdpAnswer,
            'expires_at' => $this->expiresAt->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toStatusArray(): array
    {
        return [
            'id' => $this->id,
            'intake_id' => $this->intake->getId(),
            'status' => $this->status,
            'transport' => $this->transport,
            'expires_at' => $this->expiresAt->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM),
            'close_reason' => $this->closeReason,
            'usage_finalized' => $this->usageFinalized,
        ];
    }
}
