<?php

namespace App\Enums;

enum BackupStatus: string
{
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Running => 'Running',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Running => 'amber',
            self::Completed => 'emerald',
            self::Failed => 'red',
        };
    }
}
