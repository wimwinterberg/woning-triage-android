<?php

declare(strict_types=1);

namespace App\Exception;

final class IdempotencyConflictException extends ApiException
{
    public function __construct()
    {
        parent::__construct(
            'idempotency_conflict',
            'Deze verzoek-sleutel is al gebruikt met andere gegevens.',
            409,
        );
    }
}
