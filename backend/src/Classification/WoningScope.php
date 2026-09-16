<?php

declare(strict_types=1);

namespace App\Classification;

/**
 * This app only classifies inside gebouwtype "Woning".
 * Other catalog roots (complex, terrein, …) are imported but ignored at search time.
 */
final class WoningScope
{
    public static function matchesGebouwtype(string $normalizedLabel): bool
    {
        $label = trim($normalizedLabel);

        return $label === 'woning' || str_ends_with($label, 'woning');
    }
}
