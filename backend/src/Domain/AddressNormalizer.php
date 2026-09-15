<?php

declare(strict_types=1);

namespace App\Domain;

final class AddressNormalizer
{
    public function normalizePostcode(string $postcode): string
    {
        $compact = strtoupper(preg_replace('/\s+/', '', $postcode) ?? '');
        if (!preg_match('/^[1-9][0-9]{3}[A-Z]{2}$/', $compact)) {
            throw new \InvalidArgumentException('Ongeldige postcode.');
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
