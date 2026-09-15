<?php

declare(strict_types=1);

namespace App\Exception;

final class ValidationFailedException extends ApiException
{
    /**
     * @param list<string> $details
     */
    public function __construct(string $message, public readonly array $details = [])
    {
        parent::__construct(
            'invalid_value',
            $message,
            422,
            $details !== [] ? ['details' => $details] : [],
        );
    }
}
