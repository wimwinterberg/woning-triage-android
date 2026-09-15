<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\IdGenerator;
use App\Entity\IdempotencyRecord;
use App\Exception\IdempotencyConflictException;
use App\Exception\ValidationFailedException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

final class IdempotencyService
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function requireKey(Request $request): string
    {
        $key = $request->headers->get('Idempotency-Key');
        if ($key === null || $key === '') {
            throw new ValidationFailedException('Idempotency-Key ontbreekt.');
        }
        if (strlen($key) > 128) {
            throw new ValidationFailedException('Idempotency-Key is te lang.');
        }

        return $key;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function find(string $actorId, string $intakeId, string $operation, string $key, array $payload): ?IdempotencyRecord
    {
        $record = $this->entityManager->getRepository(IdempotencyRecord::class)->findOneBy([
            'actorId' => $actorId,
            'intakeId' => $intakeId,
            'operation' => $operation,
            'idempotencyKey' => $key,
        ]);
        if ($record === null) {
            return null;
        }
        if ($record->getPayloadHash() !== $this->hash($payload)) {
            throw new IdempotencyConflictException();
        }

        return $record;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $response
     */
    public function store(string $actorId, string $intakeId, string $operation, string $key, array $payload, int $status, array $response): void
    {
        $record = new IdempotencyRecord(
            IdGenerator::prefixed('idem'),
            $actorId,
            $intakeId,
            $operation,
            $key,
            $this->hash($payload),
            $status,
            $response,
        );
        $this->entityManager->persist($record);
        $this->entityManager->flush();
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function hash(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
}
