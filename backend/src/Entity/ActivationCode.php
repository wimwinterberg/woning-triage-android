<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'activation_code')]
class ActivationCode
{
    #[ORM\Id]
    #[ORM\Column(length: 64)]
    private string $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 64, unique: true)]
    private string $codeHash;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $usedAt = null;

    public function __construct(string $id, User $user, string $codeHash, \DateTimeImmutable $expiresAt)
    {
        $this->id = $id;
        $this->user = $user;
        $this->codeHash = $codeHash;
        $this->expiresAt = $expiresAt;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function isUsable(): bool
    {
        return $this->usedAt === null && $this->expiresAt > new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function markUsed(): void
    {
        $this->usedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
