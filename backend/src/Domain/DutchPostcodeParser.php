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
     * @return array{postcode: ?string, house_number: ?int, addition: ?string, street: ?string}
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
            $house = self::consumeDigitHouseNumber($tokens, $cursor);
            if ($house !== null) {
                $houseNumber = $house['value'];
                $cursor = $house['next'];
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
            'street' => self::extractStreet($text),
        ];
    }

    public static function claimsSingleAddress(string $text): bool
    {
        $lower = mb_strtolower($text);

        return (bool) preg_match('/er is maar (één|een|1) adres|slechts (één|een|1) adres/u', $lower);
    }

    private static function extractStreet(string $text): ?string
    {
        if (preg_match('/\b([A-Za-zÀ-ÿ]+(?:straat|laan|weg|plein|gracht|kade|singel|hof|dreef|pad|steeg|dijk|baan))\b/u', $text, $match) !== 1) {
            return null;
        }

        $street = $match[1];
        if (in_array(mb_strtolower($street), self::STOP, true)) {
            return null;
        }

        return $street;
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
     * Turns spoken Dutch numbers into digit tokens, packing a 4-digit postcode
     * when possible. "drie vijf zeventig" is treated as 3573 because STT often
     * hears "zeven drie" as "zeventig".
     *
     * @param list<string> $tokens
     * @return list<string>
     */
    private static function rewriteSpokenNumbers(array $tokens): array
    {
        $out = [];
        $run = [];
        $count = count($tokens);
        $i = 0;
        $flush = static function () use (&$out, &$run): void {
            if ($run === []) {
                return;
            }
            foreach (self::packNumberRun($run) as $part) {
                $out[] = $part;
            }
            $run = [];
        };
        while ($i < $count) {
            $spoken = self::consumeSpokenNumber($tokens, $i);
            if ($spoken !== null) {
                $run[] = $spoken['value'];
                $i = $spoken['next'];
                continue;
            }
            $flush();
            $out[] = $tokens[$i];
            ++$i;
        }
        $flush();

        return $out;
    }

    /**
     * @param list<int> $nums
     * @return list<string>
     */
    private static function packNumberRun(array $nums): array
    {
        $packed = self::takePostcode($nums);
        if ($packed === null) {
            $house = self::combineHouseNumber($nums);

            return $house !== null ? [(string) $house] : array_map(static fn (int $n): string => (string) $n, $nums);
        }
        [$code, $used] = $packed;
        $out = [$code];
        $house = self::combineHouseNumber(array_slice($nums, $used));
        if ($house !== null) {
            $out[] = (string) $house;
        }

        return $out;
    }

    /**
     * @param list<int> $nums
     * @return array{0: string, 1: int}|null
     */
    private static function takePostcode(array $nums): ?array
    {
        $n = count($nums);
        if ($n === 0) {
            return null;
        }
        if ($nums[0] >= 1000 && $nums[0] <= 9999 && preg_match('/^[1-9][0-9]{3}$/', (string) $nums[0]) === 1) {
            return [(string) $nums[0], 1];
        }
        if ($n >= 4 && self::allUnits(array_slice($nums, 0, 4)) && $nums[0] >= 1) {
            return [implode('', array_map(static fn (int $digit): string => (string) $digit, array_slice($nums, 0, 4))), 4];
        }
        if ($n >= 2 && self::isTwoDigit($nums[0]) && self::isTwoDigit($nums[1])) {
            $code = sprintf('%02d%02d', $nums[0], $nums[1]);
            if (preg_match('/^[1-9][0-9]{3}$/', $code) === 1) {
                return [$code, 2];
            }
        }
        if ($n >= 3 && self::isUnit($nums[0]) && self::isTensValue($nums[1]) && self::isTwoDigit($nums[2])) {
            $code = sprintf('%02d%02d', $nums[0] + $nums[1], $nums[2]);
            if (preg_match('/^[1-9][0-9]{3}$/', $code) === 1) {
                return [$code, 3];
            }
        }
        if ($n >= 3 && self::isUnit($nums[0]) && self::isUnit($nums[1]) && $nums[2] === 70) {
            $code = $nums[0].$nums[1].'73';
            if (preg_match('/^[1-9][0-9]{3}$/', $code) === 1) {
                return [$code, 3];
            }
        }
        if ($n >= 3 && self::isUnit($nums[0]) && self::isUnit($nums[1]) && self::isTensValue($nums[2])) {
            $code = $nums[0].$nums[1].sprintf('%02d', $nums[2]);
            if (preg_match('/^[1-9][0-9]{3}$/', $code) === 1) {
                return [$code, 3];
            }
        }

        return null;
    }

    /**
     * @param list<int> $nums
     */
    private static function combineHouseNumber(array $nums): ?int
    {
        if ($nums === []) {
            return null;
        }
        if (count($nums) === 1) {
            return $nums[0] >= 1 && $nums[0] <= 99999 ? $nums[0] : null;
        }
        if ($nums[0] >= 100 && $nums[0] % 100 === 0) {
            $rest = self::combineHouseNumber(array_slice($nums, 1));

            return $nums[0] + ($rest ?? 0);
        }
        $joined = (int) implode('', array_map(static fn (int $digit): string => (string) $digit, $nums));

        return $joined >= 1 && $joined <= 99999 ? $joined : $nums[0];
    }

    /**
     * @param list<int> $nums
     */
    private static function allUnits(array $nums): bool
    {
        foreach ($nums as $num) {
            if (!self::isUnit($num)) {
                return false;
            }
        }

        return true;
    }

    private static function isUnit(int $value): bool
    {
        return $value >= 0 && $value <= 9;
    }

    private static function isTwoDigit(int $value): bool
    {
        return $value >= 10 && $value <= 99;
    }

    private static function isTensValue(int $value): bool
    {
        return in_array($value, [20, 30, 40, 50, 60, 70, 80, 90], true);
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

        $hundreds = self::consumeHundreds($tokens, $index);
        if ($hundreds !== null) {
            return $hundreds;
        }

        $compound = self::compoundFromWord($token);
        if ($compound !== null) {
            return ['value' => $compound, 'next' => $index + 1];
        }

        $units = self::lookupMap($token, self::UNITS);
        $nextIndex = $index + 1;
        $hasEn = isset($tokens[$nextIndex]) && in_array(self::fold($tokens[$nextIndex]), ['en', 'ën'], true);
        if ($units !== null && $hasEn) {
            ++$nextIndex;
            $tens = isset($tokens[$nextIndex]) ? self::lookupMap($tokens[$nextIndex], self::TENS) : null;
            if ($tens !== null && $units >= 1 && $units <= 9) {
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

    /**
     * @param list<string> $tokens
     * @return array{value: int, next: int}|null
     */
    private static function consumeHundreds(array $tokens, int $index): ?array
    {
        $folded = self::fold($tokens[$index]);
        $hundreds = null;
        $next = $index + 1;
        $remainder = '';
        if (preg_match('/^(een|twee|drie|vier|vijf|zes|zeven|acht|negen)honderd(.*)$/u', $folded, $match) === 1) {
            $hundreds = self::UNITS[$match[1]] * 100;
            $remainder = $match[2];
        } elseif (preg_match('/^honderd(.*)$/u', $folded, $match) === 1) {
            $hundreds = 100;
            $remainder = $match[1];
        } else {
            $units = self::lookupMap($tokens[$index], self::UNITS);
            if ($units !== null && $units >= 1 && $units <= 9 && isset($tokens[$next]) && self::fold($tokens[$next]) === 'honderd') {
                $hundreds = $units * 100;
                ++$next;
            }
        }
        if ($hundreds === null) {
            return null;
        }
        if ($remainder !== '') {
            $extra = self::valueFromSpokenFragment($remainder);

            return $extra === null ? null : ['value' => $hundreds + $extra, 'next' => $next];
        }
        $extra = self::consumeSmallNumber($tokens, $next);

        return ['value' => $hundreds + $extra['value'], 'next' => $extra['next']];
    }

    private static function valueFromSpokenFragment(string $fragment): ?int
    {
        $folded = self::fold($fragment);
        if (in_array($folded, ['en', 'n'], true)) {
            return 0;
        }
        if (str_starts_with($folded, 'en')) {
            $stripped = substr($folded, 2);
            if ($stripped !== '') {
                $folded = $stripped;
            }
        }
        $compound = self::compoundFromWord($folded);
        if ($compound !== null) {
            return $compound;
        }
        foreach ([self::TEENS, self::TENS, self::UNITS] as $map) {
            if (isset($map[$folded])) {
                return $map[$folded];
            }
        }

        return null;
    }

    /**
     * @param list<string> $tokens
     * @return array{value: int, next: int}
     */
    private static function consumeSmallNumber(array $tokens, int $index): array
    {
        if (!isset($tokens[$index])) {
            return ['value' => 0, 'next' => $index];
        }
        $compound = self::compoundFromWord($tokens[$index]);
        if ($compound !== null) {
            return ['value' => $compound, 'next' => $index + 1];
        }
        $units = self::lookupMap($tokens[$index], self::UNITS);
        $next = $index + 1;
        if ($units !== null && isset($tokens[$next]) && in_array(self::fold($tokens[$next]), ['en', 'ën'], true)) {
            ++$next;
            $tens = isset($tokens[$next]) ? self::lookupMap($tokens[$next], self::TENS) : null;
            if ($tens !== null) {
                return ['value' => $units + $tens, 'next' => $next + 1];
            }
        }
        foreach ([self::TEENS, self::TENS, self::UNITS] as $map) {
            $value = self::lookupMap($tokens[$index], $map);
            if ($value !== null) {
                return ['value' => $value, 'next' => $index + 1];
            }
        }

        return ['value' => 0, 'next' => $index];
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
            if (in_array($keyword, ['huisnummer', 'nummer', 'number'], true)) {
                $house = self::consumeDigitHouseNumber($tokens, $i + 1);
                if ($house !== null) {
                    return $house['value'];
                }
            }
        }
        for ($i = 0; $i < $count; ++$i) {
            if (!self::isStreetToken($tokens[$i])) {
                continue;
            }
            $house = self::consumeDigitHouseNumber($tokens, $i + 1);
            if ($house !== null) {
                return $house['value'];
            }
        }
        if ($count === 1 && preg_match('/^[1-9][0-9]{0,4}$/', $tokens[0]) === 1 && strlen($tokens[0]) < 4) {
            return (int) $tokens[0];
        }

        return null;
    }

    /**
     * @param list<string> $tokens
     * @return array{value: int, next: int}|null
     */
    private static function consumeDigitHouseNumber(array $tokens, int $index): ?array
    {
        $digits = [];
        $cursor = $index;
        while (isset($tokens[$cursor]) && preg_match('/^[0-9]{1,5}$/', $tokens[$cursor]) === 1) {
            $digits[] = $tokens[$cursor];
            ++$cursor;
            if (strlen(implode('', $digits)) >= 5) {
                break;
            }
        }
        if ($digits === []) {
            return null;
        }
        $joined = (int) implode('', $digits);
        if ($joined < 1 || $joined > 99999) {
            return null;
        }

        return ['value' => $joined, 'next' => $cursor];
    }

    private static function isStreetToken(string $token): bool
    {
        if (in_array(mb_strtolower($token), self::STOP, true)) {
            return false;
        }

        return (bool) preg_match('/(straat|laan|weg|plein|gracht|kade|singel|hof|dreef|pad|steeg|dijk|baan)$/u', self::fold($token));
    }
}
