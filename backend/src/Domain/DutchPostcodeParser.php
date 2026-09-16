<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Dutch postcodes are 4 digits + 2 letters (e.g. 3573 SJ).
 * Letters may be spoken with the Dutch spelling alphabet (Simon Johan → SJ).
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
        'the', 'and', 'my', 'number',
    ];

    /**
     * @return array{postcode: ?string, house_number: ?int, addition: ?string}
     */
    public static function parse(string $text): array
    {
        $tokens = self::tokenize($text);
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
     * @param list<string> $tokens
     * @return array{letters: string, next: int}|null
     */
    private static function consumeLetters(array $tokens, int $index): ?array
    {
        if (!isset($tokens[$index])) {
            return null;
        }
        $first = $tokens[$index];
        if (preg_match('/^[A-Za-z]{2}$/', $first) && !in_array(mb_strtolower($first), self::STOP, true)) {
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
        if (preg_match('/^[A-Za-z]$/', $token)) {
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
        if ($count === 1 && preg_match('/^[1-9][0-9]{0,4}$/', $tokens[0]) && strlen($tokens[0]) < 4) {
            return (int) $tokens[0];
        }

        return null;
    }
}
