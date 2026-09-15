<?php

declare(strict_types=1);

namespace App\Domain;

enum LanguageMode: string
{
    case Auto = 'auto';
    case Manual = 'manual';
}
