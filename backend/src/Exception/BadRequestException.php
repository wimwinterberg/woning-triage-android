<?php

declare(strict_types=1);

namespace App\Exception;

final class BadRequestException extends ApiException
{
    public function __construct(string $message, string $code = 'invalid_json')
    {
        parent::__construct($code, $message, 400);
    }
}
