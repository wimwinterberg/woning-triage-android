<?php

declare(strict_types=1);

namespace App\Exception;

final class IntakeBusyException extends ApiException
{
    public function __construct()
    {
        parent::__construct(
            'intake_busy',
            'Er wordt nog een antwoord verwerkt. Wacht tot dat klaar is of corrigeer het veld.',
            409,
        );
    }
}
