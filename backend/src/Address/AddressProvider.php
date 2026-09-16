<?php

declare(strict_types=1);

namespace App\Address;

interface AddressProvider
{
    /**
     * @return list<AddressCandidate>
     */
    public function lookup(string $postcode, int $houseNumber, ?string $addition): array;
}
