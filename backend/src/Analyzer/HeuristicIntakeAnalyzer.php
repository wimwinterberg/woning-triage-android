<?php

declare(strict_types=1);

namespace App\Analyzer;

use App\Domain\DutchPostcodeParser;
use App\Entity\Intake;

/**
 * Deterministic extractor used for CI, demo and as a fallback when no LLM is configured.
 * It never invents a technical cause: a reported cause is only stored from the resident,
 * typically while the tree is asking for the cause.
 */
final class HeuristicIntakeAnalyzer implements IntakeAnalyzer
{
    /**
     * @param array<string, list<string>> $locations
     * @param array<string, list<string>> $elements
     * @param array<string, list<string>> $defects
     */
    public function __construct(
        private readonly array $locations = [
            'Keuken' => ['keuken', 'kitchen'],
            'Badkamer' => ['badkamer', 'bathroom', 'douche', 'shower'],
            'Toilet' => ['toilet', 'wc'],
            'Woonkamer' => ['woonkamer', 'living room'],
            'Slaapkamer' => ['slaapkamer', 'bedroom'],
            'Gang' => ['gang', 'hal', 'hallway', 'overloop'],
            'Zolder' => ['zolder', 'attic'],
            'Kelder' => ['kelder', 'basement'],
            'Tuin' => ['tuin', 'garden'],
            'Balkon' => ['balkon', 'balcony'],
            'Meterkast' => ['meterkast', 'meter cupboard'],
            'Berging' => ['berging', 'schuur', 'shed'],
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
            'Werkt niet' => ['werkt niet', 'does not work', "doesn't work", 'kapot', 'broken', 'doet het niet'],
            'Verstopt' => ['verstopt', 'clog', 'blocked'],
            'Maakt geluid' => ['geluid', 'noise', 'tikt', 'piept'],
            'Stank' => ['stank', 'smell', 'geur', 'stinkt'],
            'Scheur' => ['scheur', 'crack'],
            'Nat' => ['nat', 'vocht', 'damp'],
        ],
    ) {
    }

    public function analyze(Intake $intake, string $text, string $messageId, int $baseRevision): AnalysisProposal
    {
        $lower = mb_strtolower($text);
        $target = $intake->document()->nextQuestion['target'] ?? null;
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
        if ($causeUnknown && $this->shouldRecordUnknownCause($target, $lower)) {
            $updates[] = [
                'field' => 'cause',
                'state' => 'unknown',
                'value' => null,
                'source' => 'user_message',
                'evidence_ids' => [$messageId],
            ];
        }

        $this->captureAskedField($target, $text, $lower, $messageId, $causeUnknown, $updates);

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
            suggestedLanguage: null,
            languageExplicit: false,
            addressHint: $this->extractAddress($text),
            riskSignals: [],
            independentTime: $this->extractTime($text),
            causeUnknown: $causeUnknown && $this->shouldRecordUnknownCause($target, $lower),
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
        return $this->isUnknownAnswer($lower)
            || (bool) preg_match('/oorzaak onbekend|geen oorzaak/u', $lower);
    }

    private function isUnknownAnswer(string $lower): bool
    {
        $trimmed = trim($lower);

        return (bool) preg_match('/(ik weet (het )?niet|weet ik niet|\bonbekend\b|geen idee|geen flauw idee|niet bekend|i don\'t know|i do not know|no idea|\bunknown\b)/u', $trimmed);
    }

    private function shouldRecordUnknownCause(mixed $target, string $lower): bool
    {
        return $target === 'cause' || str_contains($lower, 'oorzaak');
    }

    private function looksLikeBareYes(string $lower): bool
    {
        $trimmed = trim($lower);
        $trimmed = preg_replace('/^[^\p{L}]+/u', '', $trimmed) ?? $trimmed;
        $trimmed = preg_replace('/[\s.!?]+$/u', '', $trimmed) ?? $trimmed;

        return (bool) preg_match('/^(ja|yes|ok|okay|oké|oke|klopt|evet|hai)$/u', $trimmed);
    }

    /**
     * @return array{postcode: ?string, house_number: ?int, addition: ?string, street: ?string, unique_claim: bool}|null
     */
    private function extractAddress(string $text): ?array
    {
        $parsed = DutchPostcodeParser::parse($text);
        $unique = DutchPostcodeParser::claimsSingleAddress($text);
        if ($parsed['postcode'] === null && $parsed['house_number'] === null && $parsed['street'] === null && !$unique) {
            return null;
        }
        $parsed['unique_claim'] = $unique;

        return $parsed;
    }

    /**
     * When the tree is asking for a specific LEDO field, store the resident's
     * utterance even if it does not match the keyword dictionaries.
     *
     * @param list<array{field: string, state: string, value?: ?string, source: string, evidence_ids: list<string>}> $updates
     */
    private function captureAskedField(mixed $target, string $text, string $lower, string $messageId, bool $causeUnknown, array &$updates): void
    {
        if (!is_string($target) || !in_array($target, ['location', 'element', 'defect', 'cause'], true)) {
            return;
        }
        foreach ($updates as $update) {
            if (($update['field'] ?? '') === $target) {
                return;
            }
        }
        if ($this->looksLikeBareYes($lower)) {
            return;
        }
        $parsed = DutchPostcodeParser::parse($text);
        if ($parsed['postcode'] !== null) {
            return;
        }
        if ($this->isUnknownAnswer($lower)) {
            if (in_array($target, ['location', 'element', 'cause'], true)) {
                $updates[] = [
                    'field' => $target,
                    'state' => 'unknown',
                    'value' => null,
                    'source' => 'user_message',
                    'evidence_ids' => [$messageId],
                ];
            }

            return;
        }
        if ($target === 'cause' && $causeUnknown) {
            return;
        }
        $cleaned = $this->cleanUtterance($text);
        if ($cleaned === '') {
            return;
        }
        $updates[] = $this->reported($target, $cleaned, $messageId);
    }

    private function cleanUtterance(string $text): string
    {
        $cleaned = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        $cleaned = trim($cleaned, " \t\n\r\0\x0B.,!?");
        if (grapheme_strlen($cleaned) < 2) {
            return '';
        }
        if (grapheme_strlen($cleaned) > 200) {
            $cleaned = grapheme_substr($cleaned, 0, 200) ?: $cleaned;
        }

        return $cleaned;
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
