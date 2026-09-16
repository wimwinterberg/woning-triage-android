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

    public function normalizeHouseNumber(int|float|string $number): int
    {
        if (is_float($number)) {
            if (!is_finite($number) || $number !== floor($number)) {
                throw new \InvalidArgumentException('Ongeldig huisnummer.');
            }
            $number = (int) $number;
        } elseif (is_string($number)) {
            $number = (int) $number;
        }
        if ($number < 1 || $number > 99999) {
            throw new \InvalidArgumentException('Ongeldig huisnummer.');
        }

        return $number;
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

    public function normalizeLatitude(mixed $value): float
    {
        return $this->normalizeCoordinate($value, -90.0, 90.0, 'Ongeldige breedtegraad.');
    }

    public function normalizeLongitude(mixed $value): float
    {
        return $this->normalizeCoordinate($value, -180.0, 180.0, 'Ongeldige lengtegraad.');
    }

    public function assertInTheNetherlands(float $latitude, float $longitude): void
    {
        if ($latitude < 50.75 || $latitude > 53.58 || $longitude < 3.20 || $longitude > 7.23) {
            throw new \InvalidArgumentException('De locatie ligt niet in Nederland.');
        }
    }

    private function normalizeCoordinate(mixed $value, float $min, float $max, string $message): float
    {
        if (!is_int($value) && !is_float($value) && !is_string($value)) {
            throw new \InvalidArgumentException($message);
        }
        if (is_string($value) && !is_numeric($value)) {
            throw new \InvalidArgumentException($message);
        }
        $float = (float) $value;
        if (!is_finite($float) || $float < $min || $float > $max) {
            throw new \InvalidArgumentException($message);
        }

        return $float;
    }
}
