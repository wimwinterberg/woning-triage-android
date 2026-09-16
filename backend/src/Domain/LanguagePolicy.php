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

        if ($this->isJapanese($text)) {
            return $this->decide($currentLanguage, 'ja-JP', 'clear_japanese');
        }

        $scores = [
            'en-GB' => $this->score($normalized, self::englishMarkers()),
            'nl-NL' => $this->score($normalized, self::dutchMarkers()),
            'de-DE' => $this->score($normalized, self::germanMarkers()),
            'tr-TR' => $this->scoreContains($normalized, self::turkishMarkers()) + ($this->hasTurkishLetters($text) ? 2 : 0),
        ];
        arsort($scores);
        $ranked = array_keys($scores);
        $winner = $ranked[0];
        $top = $scores[$winner];
        $second = $scores[$ranked[1]];

        if ($top >= 2 && $top > $second) {
            return $this->decide($currentLanguage, $winner, 'clear_'.$this->reasonSuffix($winner));
        }

        if (($scores['en-GB'] ?? 0) === 1 && ($scores['nl-NL'] ?? 0) === 0 && count($tokens) >= 5) {
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
        if (preg_match('/(spreek|praat).*(duits|deutsch)|auf deutsch|in german|speak german/u', $normalized)) {
            return 'de-DE';
        }
        if (preg_match('/(spreek|praat).*turk|t[uü]rk[cç]e|in turkish|speak turkish/u', $normalized)) {
            return 'tr-TR';
        }
        if (preg_match('/(spreek|praat).*japans|in japanese|speak japanese|nihongo|日本語/u', $normalized)) {
            return 'ja-JP';
        }

        return null;
    }

    private function decide(string $currentLanguage, string $detected, string $reason): LanguageDecision
    {
        if (str_starts_with($currentLanguage, substr($detected, 0, 2))) {
            return new LanguageDecision($currentLanguage, false, 'already_'.$this->reasonSuffix($detected));
        }

        return new LanguageDecision($detected, true, $reason);
    }

    private function reasonSuffix(string $language): string
    {
        return match (substr($language, 0, 2)) {
            'en' => 'english',
            'nl' => 'dutch',
            'de' => 'german',
            'tr' => 'turkish',
            'ja' => 'japanese',
            default => 'other',
        };
    }

    /**
     * @param list<string> $markers
     */
    private function score(string $text, array $markers): int
    {
        $score = 0;
        foreach ($markers as $marker) {
            $quoted = preg_quote($marker, '/');
            if (preg_match('/(?<![\p{L}\p{N}])'.$quoted.'(?![\p{L}\p{N}])/u', $text) === 1) {
                ++$score;
            }
        }

        return $score;
    }

    /**
     * @param list<string> $markers
     */
    private function scoreContains(string $text, array $markers): int
    {
        $score = 0;
        foreach ($markers as $marker) {
            if (str_contains($text, $marker)) {
                ++$score;
            }
        }

        return $score;
    }

    private function isJapanese(string $text): bool
    {
        return preg_match('/[\p{Hiragana}\p{Katakana}]/u', $text) === 1
            && grapheme_strlen(trim($text)) >= 4;
    }

    private function hasTurkishLetters(string $text): bool
    {
        return preg_match('/[ğüşıöçĞÜŞİÖÇ]/u', $text) === 1;
    }

    /**
     * @return list<string>
     */
    private static function englishMarkers(): array
    {
        return ['the', 'is', 'my', 'kitchen', 'bathroom', 'leaking', 'since', 'yesterday', 'please', 'what', 'because', 'broken', 'tap', 'have', 'this', 'radiator', 'heating', 'room', 'window', 'water'];
    }

    /**
     * @return list<string>
     */
    private static function dutchMarkers(): array
    {
        return ['de', 'het', 'een', 'mijn', 'keuken', 'badkamer', 'lekt', 'sinds', 'gisteren', 'alsjeblieft', 'want', 'kapot', 'kraan'];
    }

    /**
     * @return list<string>
     */
    private static function germanMarkers(): array
    {
        return ['die', 'der', 'das', 'und', 'nicht', 'ist', 'küche', 'kuche', 'wasserhahn', 'tropft', 'kaputt', 'bitte', 'zimmer', 'seit', 'gestern', 'weil', 'heizung', 'badezimmer', 'undicht'];
    }

    /**
     * @return list<string>
     */
    private static function turkishMarkers(): array
    {
        return ['mutfak', 'musluk', 'bozuk', 'sızıyor', 'siziyor', 'lütfen', 'lutfen', 'çünkü', 'cunku', 'merhaba', 'evet', 'oda', 'damlıyor', 'damliyor'];
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
