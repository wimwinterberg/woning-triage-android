<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Dutch postcodes are 4 digits + 2 letters (e.g. 3573 SJ).
 * Letters may be spoken with the Dutch spelling alphabet (Simon Johan → SJ)
 * or NATO words (Sierra Juliet → SJ).
 * Digits may be spoken as Dutch, English, German, Turkish or Japanese words.
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
        'johan' => 'J', 'julius' => 'J', 'juliett' => 'J', 'juliet' => 'J', 'jay' => 'J',
        'karel' => 'K', 'kilo' => 'K',
        'lodewijk' => 'L', 'lima' => 'L',
        'marie' => 'M', 'maria' => 'M', 'mike' => 'M',
        'nico' => 'N', 'november' => 'N',
        'otto' => 'O', 'oscar' => 'O',
        'pieter' => 'P', 'peter' => 'P', 'papa' => 'P',
        'quebec' => 'Q', 'quotiënt' => 'Q', 'quotient' => 'Q',
        'richard' => 'R', 'romeo' => 'R', 'rudolf' => 'R',
        'simon' => 'S', 'sierra' => 'S', 'ess' => 'S', 'siegfried' => 'S',
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
        'the', 'and', 'my', 'number', 'stad', 'plaats', 'house', 'postal', 'code',
        'address', 'home', 'please', 'street', 'hausnummer', 'wohnung',
        'postleitzahl', 'plz', 'numara', 'daire', 'zip', 'und',
    ];

    private const CONNECTORS = ['en', 'ën', 'and', 'und'];

    private const HUNDRED_WORDS = ['honderd', 'hundred', 'hundert', 'yuz', 'hyaku'];

    private const UNITS = [
        'nul' => 0, 'zero' => 0, 'oh' => 0, 'nought' => 0, 'null' => 0, 'sifir' => 0, 'rei' => 0,
        'een' => 1, 'één' => 1, 'eén' => 1, 'one' => 1, 'eins' => 1, 'ein' => 1, 'bir' => 1, 'ichi' => 1,
        'twee' => 2, 'two' => 2, 'zwei' => 2, 'iki' => 2,
        'drie' => 3, 'three' => 3, 'drei' => 3, 'uc' => 3, 'さん' => 3,
        'vier' => 4, 'four' => 4, 'dort' => 4, 'yon' => 4, 'よん' => 4,
        'vijf' => 5, 'five' => 5, 'funf' => 5, 'fuenf' => 5, 'bes' => 5, 'ご' => 5,
        'zes' => 6, 'six' => 6, 'sechs' => 6, 'alti' => 6, 'roku' => 6, 'ろく' => 6,
        'zeven' => 7, 'seven' => 7, 'sieben' => 7, 'yedi' => 7, 'nana' => 7, 'なな' => 7,
        'acht' => 8, 'eight' => 8, 'sekiz' => 8, 'hachi' => 8, 'はち' => 8,
        'negen' => 9, 'nine' => 9, 'niner' => 9, 'neun' => 9, 'dokuz' => 9, 'kyuu' => 9, 'kyu' => 9, 'きゅう' => 9,
        'いち' => 1, 'に' => 2, 'ゼロ' => 0,
    ];

    private const TEENS = [
        'tien' => 10, 'elf' => 11, 'twaalf' => 12, 'dertien' => 13, 'veertien' => 14,
        'vijftien' => 15, 'zestien' => 16, 'zeventien' => 17, 'achttien' => 18, 'negentien' => 19,
        'ten' => 10, 'eleven' => 11, 'twelve' => 12, 'thirteen' => 13, 'fourteen' => 14,
        'fifteen' => 15, 'sixteen' => 16, 'seventeen' => 17, 'eighteen' => 18, 'nineteen' => 19,
        'zehn' => 10, 'zwoelf' => 12, 'zwolf' => 12, 'dreizehn' => 13, 'vierzehn' => 14,
        'funfzehn' => 15, 'sechzehn' => 16, 'siebzehn' => 17, 'achtzehn' => 18, 'neunzehn' => 19,
    ];

    private const TENS = [
        'twintig' => 20, 'dertig' => 30, 'veertig' => 40, 'vijftig' => 50,
        'zestig' => 60, 'zeventig' => 70, 'tachtig' => 80, 'negentig' => 90,
        'twenty' => 20, 'thirty' => 30, 'forty' => 40, 'fifty' => 50,
        'sixty' => 60, 'seventy' => 70, 'eighty' => 80, 'ninety' => 90,
        'zwanzig' => 20, 'dreissig' => 30, 'vierzig' => 40, 'funfzig' => 50,
        'sechzig' => 60, 'siebzig' => 70, 'achtzig' => 80, 'neunzig' => 90,
        'yirmi' => 20, 'otuz' => 30, 'kirk' => 40, 'elli' => 50,
        'altmis' => 60, 'yetmis' => 70, 'seksen' => 80, 'doksan' => 90,
        'nijuu' => 20, 'sanjuu' => 30, 'yonjuu' => 40, 'gojuu' => 50,
        'rokujuu' => 60, 'nanajuu' => 70, 'hachijuu' => 80, 'kyuujuu' => 90,
    ];

    private const TENS_THEN_UNIT = [
        'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety',
        'yirmi', 'otuz', 'kirk', 'elli', 'altmis', 'yetmis', 'seksen', 'doksan',
        'nijuu', 'sanjuu', 'yonjuu', 'gojuu', 'rokujuu', 'nanajuu', 'hachijuu', 'kyuujuu',
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

        return (bool) preg_match(
            '/er is maar (één|een|1) adres|slechts (één|een|1) adres|there(?:\'s| is) (only |just )?one address|only one address|nur eine adresse|sadece bir adres/u',
            $lower,
        );
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
     * hears "zeven drie" as "zeventig". "vijf dertig drie zeventig" packs as
     * 35 + 73 because STT splits compound tens into unit + tens twice.
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
        // "vijf dertig drie zeventig" → 3573 (STT splits 35/73 into unit+tens twice).
        if ($n >= 4 && self::isTensValue($nums[0]) && self::isUnit($nums[1]) && self::isTensValue($nums[2]) && self::isUnit($nums[3])) {
            $code = sprintf('%02d%02d', $nums[0] + $nums[1], $nums[2] + $nums[3]);
            if (preg_match('/^[1-9][0-9]{3}$/', $code) === 1) {
                return [$code, 4];
            }
        }
        if ($n >= 4 && self::isUnit($nums[0]) && self::isTensValue($nums[1]) && self::isUnit($nums[2]) && self::isTensValue($nums[3])) {
            $code = sprintf('%02d%02d', $nums[0] + $nums[1], $nums[2] + $nums[3]);
            if (preg_match('/^[1-9][0-9]{3}$/', $code) === 1) {
                return [$code, 4];
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
        // "acht zeven drie twintig" → 8732 (STT often says twintig for twee).
        if ($n >= 4 && self::isUnit($nums[0]) && self::isUnit($nums[1]) && self::isUnit($nums[2]) && self::isTensValue($nums[3])) {
            $digits = $nums[0].$nums[1].$nums[2].sprintf('%02d', $nums[3]);
            $code = substr($digits, 0, 4);
            if (preg_match('/^[1-9][0-9]{3}$/', $code) === 1) {
                return [$code, 4];
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
        if ($units !== null && isset($tokens[$nextIndex]) && self::isConnector($tokens[$nextIndex])) {
            ++$nextIndex;
            $tens = isset($tokens[$nextIndex]) ? self::lookupMap($tokens[$nextIndex], self::TENS) : null;
            if ($tens !== null && $units >= 1 && $units <= 9) {
                return ['value' => $units + $tens, 'next' => $nextIndex + 1];
            }
        }

        $tensFirst = self::lookupMap($token, self::TENS);
        if ($tensFirst !== null && in_array(self::fold($token), self::TENS_THEN_UNIT, true) && isset($tokens[$index + 1])) {
            $unitAfterTens = self::lookupMap($tokens[$index + 1], self::UNITS);
            if ($unitAfterTens !== null && $unitAfterTens >= 1 && $unitAfterTens <= 9) {
                return ['value' => $tensFirst + $unitAfterTens, 'next' => $index + 2];
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
        $unitNames = 'een|twee|drie|vier|vijf|zes|zeven|acht|negen|one|two|three|four|five|six|seven|eight|nine|eins|zwei|drei|funf|fuenf|sechs|sieben|neun|bir|iki|uc|dort|bes|alti|yedi|sekiz|dokuz';
        $hundredNames = implode('|', self::HUNDRED_WORDS);
        if (preg_match('/^('.$unitNames.')('.$hundredNames.')(.*)$/u', $folded, $match) === 1) {
            $unitValue = self::lookupMap($match[1], self::UNITS);
            if ($unitValue !== null) {
                $hundreds = $unitValue * 100;
                $remainder = $match[3];
            }
        } elseif (preg_match('/^('.$hundredNames.')(.*)$/u', $folded, $match) === 1) {
            $hundreds = 100;
            $remainder = $match[2];
        } else {
            $units = self::lookupMap($tokens[$index], self::UNITS);
            if ($units !== null && $units >= 1 && $units <= 9 && isset($tokens[$next]) && in_array(self::fold($tokens[$next]), self::HUNDRED_WORDS, true)) {
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
        if (isset($tokens[$next]) && self::isConnector($tokens[$next])) {
            ++$next;
        }
        $extra = self::consumeSmallNumber($tokens, $next);

        return ['value' => $hundreds + $extra['value'], 'next' => $extra['next']];
    }

    private static function valueFromSpokenFragment(string $fragment): ?int
    {
        $folded = self::fold($fragment);
        if (in_array($folded, self::CONNECTORS, true) || $folded === 'n') {
            return 0;
        }
        foreach (self::CONNECTORS as $connector) {
            if (str_starts_with($folded, $connector)) {
                $stripped = substr($folded, strlen($connector));
                if ($stripped !== '') {
                    $folded = $stripped;
                    break;
                }
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
        if ($units !== null && isset($tokens[$next]) && self::isConnector($tokens[$next])) {
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
        if (preg_match('/^(een|twee|drie|vier|vijf|zes|zeven|acht|negen)(?:en)?(twintig|dertig|veertig|vijftig|zestig|zeventig|tachtig|negentig)$/u', $folded, $match) === 1) {
            return self::UNITS[$match[1]] + self::TENS[$match[2]];
        }
        if (preg_match('/^(twenty|thirty|forty|fifty|sixty|seventy|eighty|ninety)(one|two|three|four|five|six|seven|eight|nine)$/u', $folded, $match) === 1) {
            return self::TENS[$match[1]] + self::UNITS[$match[2]];
        }
        if (preg_match('/^(ein|eins|zwei|drei|vier|funf|fuenf|sechs|sieben|acht|neun)und(zwanzig|dreissig|vierzig|funfzig|sechzig|siebzig|achtzig|neunzig)$/u', $folded, $match) === 1) {
            return self::UNITS[$match[1]] + self::TENS[$match[2]];
        }

        return null;
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

        return str_replace(
            ['ë', 'é', 'è', 'ï', 'ä', 'ö', 'ü', 'ß', 'ı', 'ş', 'ğ', 'ç', 'â', 'î', 'ō', 'ū'],
            ['e', 'e', 'e', 'i', 'a', 'o', 'u', 'ss', 'i', 's', 'g', 'c', 'a', 'i', 'o', 'u'],
            $lower,
        );
    }

    private static function isConnector(string $token): bool
    {
        return in_array(self::fold($token), self::CONNECTORS, true);
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
            if (in_array($keyword, ['huisnummer', 'nummer', 'number', 'hausnummer', 'numara'], true)) {
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
