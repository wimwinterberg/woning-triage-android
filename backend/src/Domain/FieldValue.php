<?php

declare(strict_types=1);

namespace App\Domain;

final class FieldValue
{
    /**
     * @param list<string> $evidenceIds
     */
    public function __construct(
        public readonly ?string $value,
        public readonly FieldState $state,
        public readonly ?string $source,
        public readonly array $evidenceIds,
    ) {
    }

    public static function missing(): self
    {
        return new self(null, FieldState::Missing, null, []);
    }

    /**
     * @param list<string> $evidenceIds
     */
    public static function reported(string $value, string $source, array $evidenceIds): self
    {
        return new self($value, FieldState::Reported, $source, $evidenceIds);
    }

    /**
     * @param list<string> $evidenceIds
     */
    public static function unknown(string $source, array $evidenceIds): self
    {
        return new self(null, FieldState::Unknown, $source, $evidenceIds);
    }

    /**
     * @param list<string> $evidenceIds
     */
    public function needsReview(array $evidenceIds = []): self
    {
        return new self($this->value, FieldState::NeedsReview, $this->source, $evidenceIds !== [] ? $evidenceIds : $this->evidenceIds);
    }

    /**
     * @return array{value: ?string, state: string, source: ?string, evidence_ids: list<string>}
     */
    public function toArray(): array
    {
        return [
            'value' => $this->value,
            'state' => $this->state->value,
            'source' => $this->source,
            'evidence_ids' => $this->evidenceIds,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            isset($data['value']) && is_string($data['value']) && $data['value'] !== '' ? $data['value'] : null,
            FieldState::from((string) ($data['state'] ?? 'missing')),
            isset($data['source']) && is_string($data['source']) ? $data['source'] : null,
            array_values(array_filter($data['evidence_ids'] ?? [], is_string(...))),
        );
    }
}
