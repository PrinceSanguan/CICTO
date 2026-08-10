<?php

namespace App\Enums;

/**
 * offices.type. A self-referencing parent_id covers "department" versus
 * "office" without a second table.
 */
enum OfficeType: string
{
    case Office = 'office';
    case Department = 'department';
    case Division = 'division';

    public function label(): string
    {
        return match ($this) {
            self::Office => 'Office',
            self::Department => 'Department',
            self::Division => 'Division',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
