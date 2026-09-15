<?php

declare(strict_types=1);

namespace App\Exception;

final class NotFoundException extends ApiException
{
    public function __construct(string $message = 'Niet gevonden.')
    {
        parent::__construct('not_found', $message, 404);
    }
}
