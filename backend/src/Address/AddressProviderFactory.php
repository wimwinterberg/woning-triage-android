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
        string $wcsApiKey = '',
    ): AddressProvider {
        $selected = strtolower(trim((string) $name));
        if ($selected === 'fake') {
            return $fake;
        }
        // A leftover ADDRESS_PROVIDER=pdok must not hide a configured WCS key.
        if ($selected === 'pdok' && trim($wcsApiKey) === '') {
            return $pdok;
        }

        return $wcs;
    }
}
