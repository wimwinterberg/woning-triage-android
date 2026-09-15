<?php

declare(strict_types=1);

namespace App\Exception;

use RuntimeException;

class ApiException extends RuntimeException
{
    /**
     * @param array<string, mixed> $extra
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $statusCode,
        public readonly array $extra = [],
    ) {
        parent::__construct($message);
    }
}
