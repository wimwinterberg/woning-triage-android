<?php

declare(strict_types=1);

namespace App\Analyzer;

final class AnalysisProposal
{
    /**
     * @param list<array{field: string, state: string, value?: ?string, source: string, evidence_ids: list<string>}> $fieldUpdates
     * @param list<array{text: string, source: string, evidence_ids: list<string>}> $hypotheses
     * @param array{postcode: ?string, house_number: ?int, addition: ?string, street?: ?string, unique_claim?: bool}|null $addressHint
     * @param list<string> $riskSignals
     */
    public function __construct(
        public readonly int $baseRevision,
        public readonly array $fieldUpdates,
        public readonly array $hypotheses,
        public readonly ?string $suggestedLanguage,
        public readonly bool $languageExplicit,
        public readonly ?array $addressHint,
        public readonly array $riskSignals,
        public readonly ?string $independentTime,
        public readonly bool $causeUnknown,
        public readonly bool $explicitConfirmationAttempt,
    ) {
    }
}
