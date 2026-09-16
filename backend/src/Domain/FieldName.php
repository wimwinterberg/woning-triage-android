<?php

declare(strict_types=1);

namespace App\Domain;

enum FieldName: string
{
    case Location = 'location';
    case Element = 'element';
    case Defect = 'defect';
    case Cause = 'cause';

    /**
     * @return list<self>
     */
    public function dependents(): array
    {
        return match ($this) {
            self::Location => [self::Element, self::Defect],
            self::Element => [self::Defect],
            self::Defect, self::Cause => [],
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $field): string => $field->value, self::cases());
    }
}
