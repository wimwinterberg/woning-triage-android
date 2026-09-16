<?php

declare(strict_types=1);

namespace App\Live;

final class LiveSessionResult
{
    public function __construct(
        public readonly string $providerSessionId,
        public readonly string $sdpAnswer,
        public readonly bool $fake,
    ) {
    }
}
