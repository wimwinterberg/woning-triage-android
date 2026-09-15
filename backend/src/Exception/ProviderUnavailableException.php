<?php

declare(strict_types=1);

namespace App\Exception;

final class ProviderUnavailableException extends ApiException
{
    public function __construct(string $message = 'De spraakdienst is nu niet beschikbaar.')
    {
        parent::__construct('provider_unavailable', $message, 503);
    }
}
