<?php

declare(strict_types=1);

namespace App\Domain;

final class LanguagePolicy
{
    private const LOANWORDS = ['okay', 'ok', 'oké', 'oke', 'wifi', 'app'];

    public function detectFromResidentText(string $text, string $currentLanguage, LanguageMode $mode): LanguageDecision
    {
        if ($mode === LanguageMode::Manual) {
            return new LanguageDecision($currentLanguage, false, 'manual_mode');
        }

        $normalized = mb_strtolower(trim($text));
        if ($normalized === '') {
            return new LanguageDecision($currentLanguage, false, 'empty');
        }

        $tokens = preg_split('/\s+/u', $normalized) ?: [];
        if (count($tokens) === 1 && in_array($tokens[0], self::LOANWORDS, true)) {
            return new LanguageDecision($currentLanguage, false, 'loanword');
        }

        $englishScore = $this->score($normalized, self::englishMarkers());
        $dutchScore = $this->score($normalized, self::dutchMarkers());

        if ($englishScore >= 2 && $englishScore > $dutchScore) {
            if (str_starts_with($currentLanguage, 'en')) {
                return new LanguageDecision($currentLanguage, false, 'already_english');
            }

            return new LanguageDecision('en-GB', true, 'clear_english');
        }

        if ($dutchScore >= 2 && $dutchScore > $englishScore && !str_starts_with($currentLanguage, 'nl')) {
            return new LanguageDecision('nl-NL', true, 'clear_dutch');
        }

        if ($englishScore === 1 && $dutchScore === 0 && count($tokens) >= 5) {
            return new LanguageDecision($currentLanguage, false, 'uncertain');
        }

        return new LanguageDecision($currentLanguage, false, 'keep');
    }

    public function isExplicitLanguageRequest(string $text): ?string
    {
        $normalized = mb_strtolower($text);
        if (preg_match('/(spreek|praat|switch).*(engels|english)|in english|speak english/u', $normalized)) {
            return 'en-GB';
        }
        if (preg_match('/(spreek|praat).*(nederlands)|in dutch|in het nederlands/u', $normalized)) {
            return 'nl-NL';
        }

        return null;
    }

    /**
     * @param list<string> $markers
     */
    private function score(string $text, array $markers): int
    {
        $score = 0;
        foreach ($markers as $marker) {
            if (preg_match('/\b'.preg_quote($marker, '/').'\b/u', $text)) {
                ++$score;
            }
        }

        return $score;
    }

    /**
     * @return list<string>
     */
    private static function englishMarkers(): array
    {
        return ['the', 'is', 'my', 'kitchen', 'bathroom', 'leaking', 'since', 'yesterday', 'please', 'what', 'because', 'broken', 'tap'];
    }

    /**
     * @return list<string>
     */
    private static function dutchMarkers(): array
    {
        return ['de', 'het', 'een', 'mijn', 'keuken', 'badkamer', 'lekt', 'sinds', 'gisteren', 'alsjeblieft', 'want', 'kapot', 'kraan'];
    }
}

final class LanguageDecision
{
    public function __construct(
        public readonly string $language,
        public readonly bool $changed,
        public readonly string $reason,
    ) {
    }
}
