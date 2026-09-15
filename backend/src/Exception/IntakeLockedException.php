<?php

declare(strict_types=1);

namespace App\Exception;

final class IntakeLockedException extends ApiException
{
    public function __construct()
    {
        parent::__construct(
            'intake_locked',
            'Dit dossier is afgerond of geannuleerd en kan niet meer worden gewijzigd.',
            409,
        );
    }
}
