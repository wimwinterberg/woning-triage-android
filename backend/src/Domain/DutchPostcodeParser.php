<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Dutch postcodes are 4 digits + 2 letters (e.g. 3573 SJ).
 * Letters may be spoken with the Dutch spelling alphabet (Simon Johan → SJ).
 * Digits may be spoken as words (drie vijf zeven drie, vijfendertig drieënzeventig).
 */
final class DutchPostcodeParser
{
    private const LETTER_NAMES = [
        'anton' => 'A', 'anna' => 'A', 'alpha' => 'A',
        'bernard' => 'B', 'bernhard' => 'B', 'bravo' => 'B',
        'cornelis' => 'C', 'charlie' => 'C',
        'dirk' => 'D', 'delta' => 'D',
        'eduard' => 'E', 'echo' => 'E',
        'ferdinand' => 'F', 'foxtrot' => 'F',
        'gerard' => 'G', 'golf' => 'G',
        'hendrik' => 'H', 'hotel' => 'H',
        'izaak' => 'I', 'isaac' => 'I', 'isaak' => 'I', 'india' => 'I',
        'johan' => 'J', 'julius' => 'J', 'juliett' => 'J', 'juliet' => 'J',
        'karel' => 'K', 'kilo' => 'K',
        'lodewijk' => 'L', 'lima' => 'L',
        'marie' => 'M', 'maria' => 'M', 'mike' => 'M',
        'nico' => 'N', 'november' => 'N',
        'otto' => 'O', 'oscar' => 'O',
        'pieter' => 'P', 'peter' => 'P', 'papa' => 'P',
        'quebec' => 'Q', 'quotiënt' => 'Q', 'quotient' => 'Q',
        'richard' => 'R', 'romeo' => 'R', 'rudolf' => 'R',
        'simon' => 'S', 'sierra' => 'S', 'ess' => 'S',
        'theodoor' => 'T', 'theodore' => 'T', 'tango' => 'T', 'teunis' => 'T',
        'utrecht' => 'U', 'uniform' => 'U',
        'victor' => 'V',
        'willem' => 'W', 'whiskey' => 'W', 'whisky' => 'W',
        'xantippe' => 'X', 'xavier' => 'X', 'xray' => 'X',
        'ypsilon' => 'Y', 'yankee' => 'Y', 'yvonne' => 'Y',
        'zaandam' => 'Z', 'zulu' => 'Z', 'zacharias' => 'Z',
    ];

    private const STOP = [
        'de', 'het', 'een', 'van', 'en', 'in', 'op', 'te', 'is', 'ik', 'mijn',
        'huisnummer', 'postcode', 'straat', 'woning', 'nummer', 'toevoeging',
        'the', 'and', 'my', 'number', 'stad', 'plaats',
    ];

    private const UNITS = [
        'nul' => 0,
        'een' => 1, 'één' => 1, 'eén' => 1,
        'twee' => 2,
        'drie' => 3,
        'vier' => 4,
        'vijf' => 5,
        'zes' => 6,
        'zeven' => 7,
        'acht' => 8,
        'negen' => 9,
    ];

    private const TEENS = [
        'tien' => 10, 'elf' => 11, 'twaalf' => 12, 'dertien' => 13, 'veertien' => 14,
        'vijftien' => 15, 'zestien' => 16, 'zeventien' => 17, 'achttien' => 18, 'negentien' => 19,
    ];

    private const TENS = [
        'twintig' => 20, 'dertig' => 30, 'veertig' => 40, 'vijftig' => 50,
        'zestig' => 60, 'zeventig' => 70, 'tachtig' => 80, 'negentig' => 90,
    ];

    /**
     * @return array{postcode: ?string, house_number: ?int, addition: ?string}
     */
    public static function parse(string $text): array
    {
        $tokens = self::rewriteSpokenNumbers(self::tokenize($text));
        $postcode = null;
        $houseNumber = null;
        $addition = null;
        $count = count($tokens);

        for ($i = 0; $i < $count; ++$i) {
            if (preg_match('/^([1-9][0-9]{3})([A-Za-z]{2})([1-9][0-9]{0,4})([A-Za-z][A-Za-z0-9]{0,5})?$/', $tokens[$i], $glued)) {
                $postcode = $glued[1].' '.strtoupper($glued[2]);
                $houseNumber = (int) $glued[3];
                $addition = isset($glued[4]) && $glued[4] !== '' ? strtoupper($glued[4]) : null;
                break;
            }
            if (preg_match('/^([1-9][0-9]{3})([A-Za-z]{2})$/', $tokens[$i], $compact)) {
                $postcode = $compact[1].' '.strtoupper($compact[2]);
                $cursor = $i + 1;
            } elseif (preg_match('/^[1-9][0-9]{3}$/', $tokens[$i])) {
                $letters = self::consumeLetters($tokens, $i + 1);
                if ($letters === null) {
                    continue;
                }
                $postcode = $tokens[$i].' '.$letters['letters'];
                $cursor = $letters['next'];
            } else {
                continue;
            }
            $cursor = self::skipStop($tokens, $cursor);
            if ($cursor < $count && preg_match('/^[1-9][0-9]{0,4}$/', $tokens[$cursor])) {
                $houseNumber = (int) $tokens[$cursor];
                ++$cursor;
            }
            $cursor = self::skipStop($tokens, $cursor);
            if ($cursor < $count && self::isAddition($tokens[$cursor])) {
                $addition = strtoupper($tokens[$cursor]);
            }
            break;
        }

        if ($houseNumber === null) {
            $houseNumber = self::standaloneHouseNumber($tokens);
        }

        return [
            'postcode' => $postcode,
            'house_number' => $houseNumber,
            'addition' => $addition,
        ];
    }

    /**
     * @return list<string>
     */
    private static function tokenize(string $text): array
    {
        $parts = preg_split('/[^\p{L}\p{N}]+/u', trim($text)) ?: [];

        return array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));
    }

    /**
     * Turns spoken Dutch numbers into digits and joins adjacent digit runs.
     * "vijf dertig" (STT-split vijfendertig) + "drieënzeventig" → 3573.
     *
     * @param list<string> $tokens
     * @return list<string>
     */
    private static function rewriteSpokenNumbers(array $tokens): array
    {
        $items = [];
        $count = count($tokens);
        $i = 0;
        while ($i < $count) {
            $spoken = self::consumeSpokenNumber($tokens, $i);
            if ($spoken !== null) {
                $items[] = ['text' => (string) $spoken['value'], 'digits' => true];
                $i = $spoken['next'];
                continue;
            }
            $items[] = ['text' => $tokens[$i], 'digits' => false];
            ++$i;
        }

        $out = [];
        $buffer = '';
        $flush = static function () use (&$out, &$buffer): void {
            if ($buffer === '') {
                return;
            }
            while (strlen($buffer) > 4 && preg_match('/^[1-9][0-9]{3}/', $buffer) === 1) {
                $out[] = substr($buffer, 0, 4);
                $buffer = substr($buffer, 4);
            }
            if ($buffer !== '') {
                $out[] = $buffer;
                $buffer = '';
            }
        };
        foreach ($items as $item) {
            if ($item['digits']) {
                $buffer .= $item['text'];
                continue;
            }
            $flush();
            $out[] = $item['text'];
        }
        $flush();

        return $out;
    }

    /**
     * @param list<string> $tokens
     * @return array{value: int, next: int}|null
     */
    private static function consumeSpokenNumber(array $tokens, int $index): ?array
    {
        if (!isset($tokens[$index])) {
            return null;
        }
        $token = $tokens[$index];
        if (preg_match('/^[0-9]+$/', $token) === 1) {
            return ['value' => (int) $token, 'next' => $index + 1];
        }

        $compound = self::compoundFromWord($token);
        if ($compound !== null) {
            return ['value' => $compound, 'next' => $index + 1];
        }

        $units = self::lookupMap($token, self::UNITS);
        $nextIndex = $index + 1;
        if ($units !== null && isset($tokens[$nextIndex]) && in_array(self::fold($tokens[$nextIndex]), ['en', 'ën'], true)) {
            ++$nextIndex;
        }
        if ($units !== null && $units >= 1 && $units <= 9 && isset($tokens[$nextIndex])) {
            $tens = self::lookupMap($tokens[$nextIndex], self::TENS);
            if ($tens !== null) {
                return ['value' => $units + $tens, 'next' => $nextIndex + 1];
            }
        }

        foreach ([self::TEENS, self::TENS, self::UNITS] as $map) {
            $value = self::lookupMap($token, $map);
            if ($value !== null) {
                return ['value' => $value, 'next' => $index + 1];
            }
        }

        return null;
    }

    private static function compoundFromWord(string $token): ?int
    {
        $folded = self::fold($token);
        if (preg_match('/^(een|twee|drie|vier|vijf|zes|zeven|acht|negen)(?:en)?(twintig|dertig|veertig|vijftig|zestig|zeventig|tachtig|negentig)$/u', $folded, $match) !== 1) {
            return null;
        }

        return self::UNITS[$match[1]] + self::TENS[$match[2]];
    }

    /**
     * @param array<string, int> $map
     */
    private static function lookupMap(string $token, array $map): ?int
    {
        $folded = self::fold($token);

        return $map[$folded] ?? $map[$token] ?? null;
    }

    private static function fold(string $token): string
    {
        $lower = mb_strtolower($token);

        return str_replace(['ë', 'é', 'è', 'ï'], ['e', 'e', 'e', 'i'], $lower);
    }

    /**
     * @param list<string> $tokens
     * @return array{letters: string, next: int}|null
     */
    private static function consumeLetters(array $tokens, int $index): ?array
    {
        if (!isset($tokens[$index])) {
            return null;
        }
        $first = $tokens[$index];
        if (preg_match('/^[A-Za-z]{2}$/', $first) === 1 && !in_array(mb_strtolower($first), self::STOP, true)) {
            return ['letters' => strtoupper($first), 'next' => $index + 1];
        }
        $one = self::tokenToLetter($first);
        $two = isset($tokens[$index + 1]) ? self::tokenToLetter($tokens[$index + 1]) : null;
        if ($one !== null && $two !== null) {
            return ['letters' => $one.$two, 'next' => $index + 2];
        }

        return null;
    }

    private static function tokenToLetter(string $token): ?string
    {
        if (preg_match('/^[A-Za-z]$/', $token) === 1) {
            return strtoupper($token);
        }
        $key = mb_strtolower($token);

        return self::LETTER_NAMES[$key] ?? null;
    }

    private static function isAddition(string $token): bool
    {
        $lower = mb_strtolower($token);
        if (in_array($lower, self::STOP, true) || isset(self::LETTER_NAMES[$lower])) {
            return false;
        }

        return (bool) preg_match('/^[A-Za-z][A-Za-z0-9]{0,5}$/', $token);
    }

    /**
     * @param list<string> $tokens
     */
    private static function skipStop(array $tokens, int $index): int
    {
        $count = count($tokens);
        while ($index < $count && in_array(mb_strtolower($tokens[$index]), self::STOP, true)) {
            ++$index;
        }

        return $index;
    }

    /**
     * @param list<string> $tokens
     */
    private static function standaloneHouseNumber(array $tokens): ?int
    {
        $count = count($tokens);
        for ($i = 0; $i < $count; ++$i) {
            $keyword = mb_strtolower($tokens[$i]);
            if (in_array($keyword, ['huisnummer', 'nummer', 'number'], true)
                && isset($tokens[$i + 1])
                && preg_match('/^[1-9][0-9]{0,4}$/', $tokens[$i + 1])
            ) {
                return (int) $tokens[$i + 1];
            }
        }
        if ($count === 1 && preg_match('/^[1-9][0-9]{0,4}$/', $tokens[0]) === 1 && strlen($tokens[0]) < 4) {
            return (int) $tokens[0];
        }

        return null;
    }
}
