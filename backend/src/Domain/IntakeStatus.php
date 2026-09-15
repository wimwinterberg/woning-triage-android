<?php

declare(strict_types=1);

namespace App\Domain;

enum IntakeStatus: string
{
    case Collecting = 'collecting';
    case ReviewRequired = 'review_required';
    case ReadyForConfirmation = 'ready_for_confirmation';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';

    public function isLocked(): bool
    {
        return $this === self::Confirmed || $this === self::Cancelled;
    }
}
