<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity]
#[ORM\Table(name: 'app_user')]
class User implements UserInterface
{
    #[ORM\Id]
    #[ORM\Column(length: 64)]
    private string $id;

    #[ORM\Column(length: 120)]
    private string $label;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $id, string $label)
    {
        $this->id = $id;
        $this->label = $label;
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function eraseCredentials(): void
    {
    }

    public function getUserIdentifier(): string
    {
        return $this->id;
    }
}
