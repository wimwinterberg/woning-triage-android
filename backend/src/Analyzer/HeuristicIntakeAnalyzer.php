<?php

declare(strict_types=1);

namespace App\Analyzer;

use App\Domain\LanguageMode;
use App\Domain\LanguagePolicy;
use App\Entity\Intake;

/**
 * Deterministic extractor used for CI, demo and as a fallback when no LLM is configured.
 * It never invents a technical cause.
 */
final class HeuristicIntakeAnalyzer implements IntakeAnalyzer
{
    /**
     * @param array<string, list<string>> $locations
     * @param array<string, list<string>> $elements
     * @param array<string, list<string>> $defects
     */
    public function __construct(
        private readonly LanguagePolicy $languagePolicy = new LanguagePolicy(),
        private readonly array $locations = [
            'Keuken' => ['keuken', 'kitchen'],
            'Badkamer' => ['badkamer', 'bathroom'],
            'Toilet' => ['toilet', 'wc'],
            'Woonkamer' => ['woonkamer', 'living room'],
            'Slaapkamer' => ['slaapkamer', 'bedroom'],
            'Gang' => ['gang', 'hal', 'hallway'],
            'Zolder' => ['zolder', 'attic'],
            'Kelder' => ['kelder', 'basement'],
            'Tuin' => ['tuin', 'garden'],
            'Balkon' => ['balkon', 'balcony'],
            'Meterkast' => ['meterkast', 'meter cupboard'],
        ],
        private readonly array $elements = [
            'Kraan' => ['kraan', 'tap', 'faucet'],
            'Radiator' => ['radiator'],
            'Leiding' => ['leiding', 'pipe'],
            'Toilet' => ['toiletpot', 'toilet'],
            'Ramen' => ['raam', 'window'],
            'Deur' => ['deur', 'door'],
            'Stopcontact' => ['stopcontact', 'socket'],
            'Lamp' => ['lamp', 'verlichting', 'light'],
            'Dak' => ['dak', 'roof', 'goot', 'gutter'],
            'CV-ketel' => ['cv-ketel', 'cv ketel', 'boiler'],
        ],
        private readonly array $defects = [
            'Druppelt' => ['druppel', 'drip'],
            'Lekt' => ['lekt', 'lek', 'leak'],
            'Werkt niet' => ['werkt niet', 'does not work', "doesn't work", 'kapot', 'broken'],
            'Verstopt' => ['verstopt', 'clog', 'blocked'],
            'Maakt geluid' => ['geluid', 'noise', 'tikt'],
            'Stank' => ['stank', 'smell', 'geur'],
            'Scheur' => ['scheur', 'crack'],
        ],
    ) {
    }

    public function analyze(Intake $intake, string $text, string $messageId, int $baseRevision): AnalysisProposal
    {
        $explicitLanguage = $this->languagePolicy->isExplicitLanguageRequest($text);
        $decision = $this->languagePolicy->detectFromResidentText(
            $text,
            $explicitLanguage ?? $intake->getConversationLanguage(),
            $explicitLanguage ? LanguageMode::Auto : $intake->getLanguageMode(),
        );

        $lower = mb_strtolower($text);
        $updates = [];
        $location = $this->matchLabel($lower, $this->locations, ['keukenkraan' => 'Keuken']);
        $element = $this->matchLabel($lower, $this->elements, ['keukenkraan' => 'Kraan']);
        $defectStem = $this->matchLabel($lower, $this->defects);

        if ($location !== null) {
            $updates[] = $this->reported('location', $location, $messageId);
        }
        if ($element !== null) {
            $updates[] = $this->reported('element', $element, $messageId);
        }
        if ($defectStem !== null) {
            $time = $this->extractTime($text);
            $value = $time !== null ? $defectStem.' '.$time : $defectStem;
            $updates[] = $this->reported('defect', $value, $messageId);
        }

        $causeUnknown = $this->isUnknownCause($lower);
        if ($causeUnknown) {
            $updates[] = [
                'field' => 'cause',
                'state' => 'unknown',
                'value' => null,
                'source' => 'user_message',
                'evidence_ids' => [$messageId],
            ];
        }

        $hypotheses = [];
        if (preg_match('/(ik denk|i think|misschien|maybe).{0,80}/iu', $text, $match)) {
            $hypotheses[] = [
                'text' => trim($match[0]),
                'source' => 'resident_hypothesis',
                'evidence_ids' => [$messageId],
            ];
        }

        return new AnalysisProposal(
            baseRevision: $baseRevision,
            fieldUpdates: $updates,
            hypotheses: $hypotheses,
            suggestedLanguage: $explicitLanguage ?? ($decision->changed ? $decision->language : null),
            languageExplicit: $explicitLanguage !== null,
            addressHint: $this->extractAddress($text),
            riskSignals: [],
            independentTime: $this->extractTime($text),
            causeUnknown: $causeUnknown,
            explicitConfirmationAttempt: $this->looksLikeBareYes($lower),
        );
    }

    /**
     * @param array<string, list<string>> $dictionary
     * @param array<string, string> $compounds
     */
    private function matchLabel(string $lower, array $dictionary, array $compounds = []): ?string
    {
        foreach ($compounds as $needle => $label) {
            if (str_contains($lower, $needle)) {
                return $label;
            }
        }
        foreach ($dictionary as $label => $needles) {
            foreach ($needles as $needle) {
                if (preg_match('/(?:\b|^)'.preg_quote($needle, '/').'/u', $lower)) {
                    return $label;
                }
            }
        }

        return null;
    }

    private function extractTime(string $text): ?string
    {
        if (preg_match('/sinds\s+[^\s,.]+(?:\s+[^\s,.]+)?/iu', $text, $match)) {
            return trim($match[0]);
        }
        if (preg_match('/since\s+[^\s,.]+(?:\s+[^\s,.]+)?/iu', $text, $match)) {
            return trim($match[0]);
        }

        return null;
    }

    private function isUnknownCause(string $lower): bool
    {
        return (bool) preg_match('/(ik weet (het )?niet|weet ik niet|oorzaak onbekend|i don\'t know|i do not know|no idea)/u', $lower);
    }

    private function looksLikeBareYes(string $lower): bool
    {
        return (bool) preg_match('/^(ja|yes|ok|okay|oké)\.?$/u', trim($lower));
    }

    /**
     * @return array{postcode: string, house_number: int, addition: ?string}|null
     */
    private function extractAddress(string $text): ?array
    {
        if (!preg_match('/\b([1-9][0-9]{3}\s?[A-Za-z]{2})\b(?:[^\d]{0,12})(\d{1,5})(?:\s*([A-Za-z0-9]{1,6}))?/u', $text, $match)) {
            return null;
        }

        return [
            'postcode' => $match[1],
            'house_number' => (int) $match[2],
            'addition' => $match[3] ?? null,
        ];
    }

    /**
     * @return array{field: string, state: string, value: string, source: string, evidence_ids: list<string>}
     */
    private function reported(string $field, string $value, string $messageId): array
    {
        return [
            'field' => $field,
            'state' => 'reported',
            'value' => $value,
            'source' => 'user_message',
            'evidence_ids' => [$messageId],
        ];
    }
}
