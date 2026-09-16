<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'idempotency_record')]
#[ORM\UniqueConstraint(name: 'uniq_idempotency', columns: ['actor_id', 'intake_id', 'operation', 'idempotency_key'])]
class IdempotencyRecord
{
    #[ORM\Id]
    #[ORM\Column(length: 64)]
    private string $id;

    #[ORM\Column(length: 64)]
    private string $actorId;

    #[ORM\Column(length: 64)]
    private string $intakeId;

    #[ORM\Column(length: 64)]
    private string $operation;

    #[ORM\Column(length: 128)]
    private string $idempotencyKey;

    #[ORM\Column(length: 64)]
    private string $payloadHash;

    #[ORM\Column]
    private int $statusCode;

    #[ORM\Column(type: Types::JSON)]
    private array $responseBody;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /**
     * @param array<string, mixed> $responseBody
     */
    public function __construct(
        string $id,
        string $actorId,
        string $intakeId,
        string $operation,
        string $idempotencyKey,
        string $payloadHash,
        int $statusCode,
        array $responseBody,
    ) {
        $this->id = $id;
        $this->actorId = $actorId;
        $this->intakeId = $intakeId;
        $this->operation = $operation;
        $this->idempotencyKey = $idempotencyKey;
        $this->payloadHash = $payloadHash;
        $this->statusCode = $statusCode;
        $this->responseBody = $responseBody;
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getPayloadHash(): string
    {
        return $this->payloadHash;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * @return array<string, mixed>
     */
    public function getResponseBody(): array
    {
        return $this->responseBody;
    }
}
