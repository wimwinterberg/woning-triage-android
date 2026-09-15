<?php

declare(strict_types=1);

namespace App\Exception;

final class AddressLookupStaleException extends ApiException
{
    public function __construct()
    {
        parent::__construct(
            'address_lookup_stale',
            'Dit zoekresultaat hoort niet bij de huidige adresgegevens.',
            409,
        );
    }
}
