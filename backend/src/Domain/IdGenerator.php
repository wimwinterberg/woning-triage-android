<?php

declare(strict_types=1);

namespace App\Domain;

final class IdGenerator
{
    public static function prefixed(string $prefix): string
    {
        return $prefix.'_'.bin2hex(random_bytes(10));
    }
}
