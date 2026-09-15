<?php

declare(strict_types=1);

namespace App\Domain;

enum FieldState: string
{
    case Missing = 'missing';
    case Reported = 'reported';
    case Unknown = 'unknown';
    case NeedsReview = 'needs_review';

    public function isPresent(): bool
    {
        return $this === self::Reported || $this === self::Unknown;
    }
}
