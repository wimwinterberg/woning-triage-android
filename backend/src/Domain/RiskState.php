<?php

declare(strict_types=1);

namespace App\Domain;

enum RiskState: string
{
    case Unassessed = 'unassessed';
    case NoSignalDetected = 'no_signal_detected';
    case ReviewRequired = 'review_required';
    case UrgentReview = 'urgent_review';

    public function blocksNormalCompletion(): bool
    {
        return $this === self::ReviewRequired || $this === self::UrgentReview;
    }
}
