<?php

declare(strict_types=1);

namespace App\Address;

final class AddressProviderFactory
{
    public static function create(
        WcsAddressProvider $wcs,
        PdokAddressProvider $pdok,
        FakeAddressProvider $fake,
        ?string $name,
    ): AddressProvider {
        return match (strtolower(trim((string) $name))) {
            'fake' => $fake,
            'pdok' => $pdok,
            default => $wcs,
        };
    }
}
