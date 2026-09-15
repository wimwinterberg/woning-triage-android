<?php

declare(strict_types=1);

namespace App\Exception;

final class AddressLookupUnavailableException extends ApiException
{
    public function __construct()
    {
        parent::__construct(
            'address_lookup_unavailable',
            'Het adres kon nu niet worden opgezocht. Probeer het later opnieuw.',
            503,
        );
    }
}
