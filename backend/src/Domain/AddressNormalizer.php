<?php

declare(strict_types=1);

namespace App\Domain;

final class AddressNormalizer
{
    public function normalizePostcode(string $postcode): string
    {
        $display = self::displayPostcode($postcode);
        if ($display === null) {
            throw new \InvalidArgumentException('Ongeldige postcode.');
        }

        return $display;
    }

    public static function compactPostcode(string $postcode): string
    {
        $compact = preg_replace('/\s+/u', '', $postcode) ?? '';

        return strtoupper($compact);
    }

    public static function samePostcode(string $left, string $right): bool
    {
        $a = self::compactPostcode($left);
        $b = self::compactPostcode($right);

        return $a !== '' && $a === $b;
    }

    public static function displayPostcode(string $postcode): ?string
    {
        $compact = self::compactPostcode($postcode);
        if (preg_match('/^[1-9][0-9]{3}[A-Z]{2}$/', $compact) !== 1) {
            return null;
        }

        return substr($compact, 0, 4).' '.substr($compact, 4, 2);
    }

    public function normalizeHouseNumber(int|string $number): int
    {
        $value = is_int($number) ? $number : (int) $number;
        if ($value < 1 || $value > 99999) {
            throw new \InvalidArgumentException('Ongeldig huisnummer.');
        }

        return $value;
    }

    public function normalizeAddition(?string $addition): ?string
    {
        if ($addition === null) {
            return null;
        }
        $trimmed = trim($addition);
        if ($trimmed === '') {
            return null;
        }
        if (mb_strlen($trimmed) > 12) {
            throw new \InvalidArgumentException('Toevoeging is te lang.');
        }

        return $trimmed;
    }
}
