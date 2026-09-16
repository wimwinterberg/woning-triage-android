<?php

declare(strict_types=1);

namespace App\Address;

use App\Exception\AddressLookupUnavailableException;

/**
 * In-memory provider for tests. Never presents these values as live BAG results.
 */
final class FakeAddressProvider implements AddressProvider
{
    public bool $fail = false;

    public static bool $failNext = false;

    /**
     * @param list<AddressCandidate> $candidates
     */
    public function __construct(private array $candidates = [])
    {
        if ($this->candidates === []) {
            $this->candidates = [
                new AddressCandidate('candidate_demo_12', '1234 AB', 12, null, 'Voorbeeldstraat', 'Amsterdam', 'NL', 'fake:1234AB-12'),
                new AddressCandidate('candidate_demo_12a', '1234 AB', 12, 'A', 'Voorbeeldstraat', 'Amsterdam', 'NL', 'fake:1234AB-12A'),
                new AddressCandidate('candidate_demo_12b', '1234 AB', 12, 'B', 'Voorbeeldstraat', 'Amsterdam', 'NL', 'fake:1234AB-12B'),
            ];
        }
    }

    public function lookup(string $postcode, int $houseNumber, ?string $addition): array
    {
        if ($this->fail || self::$failNext || $postcode === '1111 AA') {
            self::$failNext = false;
            throw new AddressLookupUnavailableException();
        }

        $matches = [];
        foreach ($this->candidates as $candidate) {
            if ($candidate->postcode !== $postcode || $candidate->houseNumber !== $houseNumber) {
                continue;
            }
            if ($addition !== null && $addition !== '' && strcasecmp((string) $candidate->addition, $addition) !== 0) {
                continue;
            }
            $matches[] = $candidate;
        }

        return $matches;
    }
}
