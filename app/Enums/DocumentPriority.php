<?php

namespace App\Enums;

/**
 * Spec §5 (form field) and §6 (one of the three classification axes).
 *
 * Priority is a sort key and a badge. It deliberately does NOT shorten due_at:
 * that would give one number two sources of truth. If the client wants priority
 * to affect the SLA it becomes an agreed config delta, not an implicit rule.
 */
enum DocumentPriority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case Urgent = 'urgent';

    public function label(): string
    {
        return match ($this) {
            self::Low => 'Low',
            self::Normal => 'Normal',
            self::High => 'High',
            self::Urgent => 'Urgent',
        };
    }

    /** Higher sorts first in queues. */
    public function weight(): int
    {
        return match ($this) {
            self::Low => 0,
            self::Normal => 1,
            self::High => 2,
            self::Urgent => 3,
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Low => 'slate',
            self::Normal => 'sky',
            self::High => 'amber',
            self::Urgent => 'red',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
