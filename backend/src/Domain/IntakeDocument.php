<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Canonical mutable document stored as JSON on the intake row.
 */
final class IntakeDocument
{
    /**
     * @param array<string, FieldValue> $fields
     * @param list<array<string, mixed>> $answers
     * @param list<array<string, mixed>> $hypotheses
     * @param array<string, mixed> $risk
     * @param array<string, mixed>|null $nextQuestion
     * @param array<string, mixed>|null $summary
     * @param array<string, mixed>|null $address
     * @param array<string, mixed>|null $classification
     * @param list<string> $invalidatedSummaryIds
     */
    public function __construct(
        public array $fields,
        public array $answers,
        public array $hypotheses,
        public array $risk,
        public ?array $nextQuestion,
        public ?array $summary,
        public ?array $address,
        public ?array $classification,
        public int $clarificationCount = 0,
        public array $invalidatedSummaryIds = [],
        public ?string $pendingAddressQuestionId = null,
        public ?string $pendingSummaryQuestionId = null,
        public ?string $spokenFollowUp = null,
    ) {
    }

    public static function initial(?array $nextQuestion): self
    {
        return new self(
            fields: [
                FieldName::Location->value => FieldValue::missing(),
                FieldName::Element->value => FieldValue::missing(),
                FieldName::Defect->value => FieldValue::missing(),
                FieldName::Cause->value => FieldValue::missing(),
            ],
            answers: [],
            hypotheses: [],
            risk: ['state' => RiskState::Unassessed->value, 'rule_ids' => [], 'evidence_ids' => []],
            nextQuestion: $nextQuestion,
            summary: null,
            address: null,
            classification: null,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $fields = [];
        foreach (FieldName::cases() as $name) {
            $raw = $data['fields'][$name->value] ?? [];
            $fields[$name->value] = FieldValue::fromArray(is_array($raw) ? $raw : []);
        }

        return new self(
            fields: $fields,
            answers: array_values($data['answers'] ?? []),
            hypotheses: array_values($data['hypotheses'] ?? []),
            risk: $data['risk'] ?? ['state' => RiskState::Unassessed->value, 'rule_ids' => [], 'evidence_ids' => []],
            nextQuestion: $data['next_question'] ?? null,
            summary: $data['summary'] ?? null,
            address: $data['address'] ?? null,
            classification: $data['classification'] ?? null,
            clarificationCount: (int) ($data['clarification_count'] ?? 0),
            invalidatedSummaryIds: array_values($data['invalidated_summary_ids'] ?? []),
            pendingAddressQuestionId: $data['pending_address_question_id'] ?? null,
            pendingSummaryQuestionId: $data['pending_summary_question_id'] ?? null,
            spokenFollowUp: isset($data['spoken_follow_up']) && is_string($data['spoken_follow_up']) && $data['spoken_follow_up'] !== ''
                ? $data['spoken_follow_up']
                : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $fields = [];
        foreach ($this->fields as $name => $field) {
            $fields[$name] = $field->toArray();
        }

        return [
            'fields' => $fields,
            'answers' => $this->answers,
            'hypotheses' => $this->hypotheses,
            'risk' => $this->risk,
            'next_question' => $this->nextQuestion,
            'summary' => $this->summary,
            'address' => $this->address,
            'classification' => $this->classification,
            'clarification_count' => $this->clarificationCount,
            'invalidated_summary_ids' => $this->invalidatedSummaryIds,
            'pending_address_question_id' => $this->pendingAddressQuestionId,
            'pending_summary_question_id' => $this->pendingSummaryQuestionId,
            'spoken_follow_up' => $this->spokenFollowUp,
        ];
    }

    public function field(FieldName $name): FieldValue
    {
        return $this->fields[$name->value];
    }

    public function setField(FieldName $name, FieldValue $value): void
    {
        $this->fields[$name->value] = $value;
    }

    /**
     * User corrections always win. Dependent reported fields are marked for review.
     *
     * @param list<array{field: FieldName, action: string, value?: string, evidence_id: string}> $changes
     * @return list<FieldName>
     */
    public function applyUserCorrections(array $changes): array
    {
        $touched = [];
        foreach ($changes as $change) {
            $field = $change['field'];
            $action = $change['action'];
            $evidence = [$change['evidence_id']];
            $previous = $this->field($field);

            $next = match ($action) {
                'set' => FieldValue::reported((string) $change['value'], 'user_correction', $evidence),
                'mark_unknown' => FieldValue::unknown('user_correction', $evidence),
                'clear' => FieldValue::missing(),
                default => throw new \InvalidArgumentException('Unknown field action'),
            };

            $this->setField($field, $next);
            $touched[] = $field;

            if ($previous->value !== $next->value || $previous->state !== $next->state) {
                foreach ($field->dependents() as $dependent) {
                    $current = $this->field($dependent);
                    if ($current->state === FieldState::Reported || $current->state === FieldState::NeedsReview) {
                        $this->setField($dependent, $current->needsReview($evidence));
                        $touched[] = $dependent;
                    }
                }
            }
        }

        $this->invalidateSummary();

        return array_values(array_unique($touched, SORT_REGULAR));
    }

    /**
     * Model proposals may fill missing/needs_review fields. They never overwrite a newer user value,
     * and they never promote a hypothesis to a reported cause.
     *
     * @param list<array{field: string, state: string, value?: ?string, source: string, evidence_ids: list<string>}> $updates
     */
    public function applyProposalUpdates(array $updates): void
    {
        foreach ($updates as $update) {
            $name = FieldName::from($update['field']);
            $current = $this->field($name);
            $state = FieldState::from($update['state']);

            if ($state === FieldState::Reported && $name === FieldName::Cause && ($update['source'] ?? '') !== 'user_message') {
                $this->hypotheses[] = [
                    'id' => 'hyp_'.bin2hex(random_bytes(6)),
                    'text' => $update['value'] ?? '',
                    'source' => $update['source'] ?? 'model',
                    'evidence_ids' => $update['evidence_ids'] ?? [],
                ];
                continue;
            }

            if ($current->state === FieldState::Reported && $state !== FieldState::Reported) {
                continue;
            }
            if ($current->state === FieldState::Unknown && $state === FieldState::Reported) {
                continue;
            }
            if ($current->state === FieldState::Reported) {
                continue;
            }

            $value = $update['value'] ?? null;
            $source = $update['source'] ?? 'user_message';
            $evidence = $update['evidence_ids'] ?? [];

            $this->setField($name, match ($state) {
                FieldState::Reported => FieldValue::reported((string) $value, $source, $evidence),
                FieldState::Unknown => FieldValue::unknown($source, $evidence),
                FieldState::NeedsReview => $current->needsReview($evidence),
                FieldState::Missing => FieldValue::missing(),
            });
        }
    }

    public function addIndependentAnswer(string $slotId, mixed $value, string $evidenceId): void
    {
        foreach ($this->answers as $existing) {
            if (($existing['slot_id'] ?? null) === $slotId) {
                return;
            }
        }

        $this->answers[] = [
            'slot_id' => $slotId,
            'value' => $value,
            'evidence_ids' => [$evidenceId],
        ];
    }

    public function invalidateSummary(): void
    {
        if ($this->summary !== null && isset($this->summary['id'])) {
            $this->invalidatedSummaryIds[] = (string) $this->summary['id'];
        }
        $this->summary = null;
        $this->pendingSummaryQuestionId = null;
    }

    public function addressVerificationStatus(): string
    {
        return $this->address['verification_status'] ?? 'missing';
    }

    public function isAddressVerified(): bool
    {
        return $this->addressVerificationStatus() === 'verified';
    }

    public function clearUnverifiedAddress(): void
    {
        if ($this->isAddressVerified()) {
            return;
        }
        $revision = (int) ($this->address['address_revision'] ?? 0) + 1;
        $this->pendingAddressQuestionId = null;
        $this->invalidateSummary();
        $this->address = [
            'postcode' => null,
            'house_number' => null,
            'addition' => null,
            'street' => null,
            'city' => null,
            'country_code' => 'NL',
            'lookup_id' => null,
            'candidate_id' => null,
            'address_revision' => $revision,
            'verification_status' => 'missing',
            'verified_at' => null,
            'candidates' => [],
            'confirmation_channel' => null,
            'evidence_message_id' => null,
            'lookup_at' => null,
            'provider' => null,
            'source' => null,
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    public function recordAddressInput(array $input): void
    {
        $previous = $this->address;
        $revision = (int) ($previous['address_revision'] ?? 0);
        $changed = $previous === null
            || ($previous['postcode'] ?? null) !== ($input['postcode'] ?? null)
            || ($previous['house_number'] ?? null) !== ($input['house_number'] ?? null)
            || ($previous['addition'] ?? null) !== ($input['addition'] ?? null);

        if ($changed) {
            ++$revision;
            $this->invalidateSummary();
        }

        $this->address = [
            'postcode' => $input['postcode'],
            'house_number' => $input['house_number'],
            'addition' => $input['addition'] ?? null,
            'street' => null,
            'city' => null,
            'country_code' => 'NL',
            'lookup_id' => $input['lookup_id'],
            'candidate_id' => null,
            'address_revision' => $revision,
            'verification_status' => 'unverified',
            'verified_at' => null,
            'candidates' => $input['candidates'] ?? [],
            'confirmation_channel' => null,
            'evidence_message_id' => null,
            'lookup_at' => $input['lookup_at'] ?? (new \DateTimeImmutable())->format(DATE_ATOM),
            'provider' => $input['provider'] ?? null,
            'source' => $input['source'] ?? 'postcode',
        ];
    }

    /**
     * @param array<string, mixed> $candidate
     */
    public function verifyAddress(string $lookupId, string $candidateId, int $addressRevision, string $channel, ?string $evidenceMessageId): void
    {
        if ($this->address === null) {
            throw new \RuntimeException('No address to verify');
        }
        if (($this->address['lookup_id'] ?? null) !== $lookupId) {
            throw new \RuntimeException('lookup mismatch');
        }
        if ((int) ($this->address['address_revision'] ?? 0) !== $addressRevision) {
            throw new \RuntimeException('address revision mismatch');
        }

        $found = null;
        foreach ($this->address['candidates'] ?? [] as $candidate) {
            if (($candidate['candidate_id'] ?? null) === $candidateId) {
                $found = $candidate;
                break;
            }
        }
        if ($found === null) {
            throw new \RuntimeException('candidate mismatch');
        }

        $this->address = array_merge($this->address, [
            'candidate_id' => $candidateId,
            'street' => $found['street'],
            'city' => $found['city'],
            'postcode' => $found['postcode'],
            'house_number' => $found['house_number'],
            'addition' => $found['addition'] ?? null,
            'country_code' => $found['country_code'] ?? 'NL',
            'verification_status' => 'verified',
            'verified_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_ATOM),
            'confirmation_channel' => $channel,
            'evidence_message_id' => $evidenceMessageId,
        ]);
        $this->invalidateSummary();
    }

    /**
     * @param array<string, mixed> $summary
     */
    public function setSummary(array $summary): void
    {
        $this->summary = $summary;
        $this->pendingSummaryQuestionId = $summary['id'];
    }

    public function hasBlockingNeedsReview(): bool
    {
        foreach ($this->fields as $field) {
            if ($field->state === FieldState::NeedsReview) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function incompleteFieldIds(): array
    {
        $missing = [];
        foreach ([FieldName::Location, FieldName::Element, FieldName::Defect] as $name) {
            if (!$this->field($name)->state->isPresent()) {
                $missing[] = $name->value;
            }
        }
        if ($this->field(FieldName::Cause)->state === FieldState::Missing || $this->field(FieldName::Cause)->state === FieldState::NeedsReview) {
            $missing[] = FieldName::Cause->value;
        }

        return $missing;
    }

    public function ledoReadyForAddress(): bool
    {
        foreach ([FieldName::Location, FieldName::Element, FieldName::Defect] as $name) {
            if (!$this->field($name)->state->isPresent()) {
                return false;
            }
        }
        $cause = $this->field(FieldName::Cause)->state;

        return $cause === FieldState::Reported || $cause === FieldState::Unknown;
    }

    public function riskState(): RiskState
    {
        return RiskState::from((string) ($this->risk['state'] ?? RiskState::Unassessed->value));
    }

    public function markRisk(RiskState $state, array $ruleIds, array $evidenceIds): void
    {
        $this->risk = [
            'state' => $state->value,
            'rule_ids' => $ruleIds,
            'evidence_ids' => $evidenceIds,
        ];
    }
}
