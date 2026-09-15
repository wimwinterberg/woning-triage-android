<?php

declare(strict_types=1);

namespace App\Exception;

final class SummaryStaleException extends ApiException
{
    public function __construct()
    {
        parent::__construct(
            'summary_stale',
            'Deze samenvatting is niet meer geldig. Vraag een nieuwe samenvatting aan.',
            409,
        );
    }
}
