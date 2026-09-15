<?php

declare(strict_types=1);

namespace App\Classification;

final class LabelNormalizer
{
    public function normalize(string $label): string
    {
        $folded = mb_strtolower(trim($label));
        $folded = strtr($folded, ['á' => 'a', 'ä' => 'a', 'é' => 'e', 'ë' => 'e', 'í' => 'i', 'ó' => 'o', 'ö' => 'o', 'ú' => 'u', 'ü' => 'u']);
        $folded = preg_replace('/\s+/u', ' ', $folded) ?? $folded;

        return $folded;
    }
}
