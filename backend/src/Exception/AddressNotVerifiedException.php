<?php

declare(strict_types=1);

namespace App\Exception;

final class AddressNotVerifiedException extends ApiException
{
    public function __construct()
    {
        parent::__construct(
            'address_not_verified',
            'Bevestig eerst het opgezochte adres voordat de melding kan worden afgerond.',
            422,
        );
    }
}
