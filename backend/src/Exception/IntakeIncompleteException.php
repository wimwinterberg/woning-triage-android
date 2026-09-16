<?php

declare(strict_types=1);

namespace App\Exception;

final class IntakeIncompleteException extends ApiException
{
    /**
     * @param list<string> $missing
     */
    public function __construct(array $missing)
    {
        parent::__construct(
            'intake_incomplete',
            'De intake is nog niet compleet genoeg voor een samenvatting.',
            422,
            ['missing' => $missing],
        );
    }
}
