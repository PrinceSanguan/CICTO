<?php

namespace App\Models;

use App\Enums\BackupStatus;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * §22 backup history.
 *
 * @property int $id
 * @property string $kind
 * @property BackupStatus $status
 * @property string|null $driver
 * @property string|null $disk
 * @property string|null $path
 * @property int|null $size_bytes
 * @property string|null $checksum_sha256
 * @property bool $is_encrypted
 * @property string|null $last_migration
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $restored_at
 * @property string|null $restore_notes
 * @property string|null $error
 * @property int|null $triggered_by_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class BackupRun extends Model
{
    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => BackupStatus::class,
            'is_encrypted' => 'boolean',
            'size_bytes' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'restored_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by_id');
    }

    /** @param  Builder<self>  $query */
    #[Scope]
    protected function successful(Builder $query): void
    {
        $query->where('backup_runs.status', BackupStatus::Completed->value);
    }

    public function durationSeconds(): ?int
    {
        if ($this->finished_at === null) {
            return null;
        }

        return (int) $this->started_at->diffInSeconds($this->finished_at);
    }

    public function exists_on_disk(): bool
    {
        return $this->disk !== null
            && $this->path !== null
            && Storage::disk($this->disk)->exists($this->path);
    }

    public function humanSize(): ?string
    {
        if ($this->size_bytes === null) {
            return null;
        }

        return match (true) {
            $this->size_bytes >= 1_073_741_824 => round($this->size_bytes / 1_073_741_824, 2).' GB',
            $this->size_bytes >= 1_048_576 => round($this->size_bytes / 1_048_576, 1).' MB',
            $this->size_bytes >= 1024 => round($this->size_bytes / 1024).' KB',
            default => $this->size_bytes.' B',
        };
    }

    /**
     * §22 says Backup AND Recovery. A backup nobody has ever restored is a
     * hypothesis, so the UI keeps saying so until a drill is recorded.
     */
    public static function hasEverBeenRestored(): bool
    {
        return static::query()->whereNotNull('restored_at')->exists();
    }

    public static function lastSuccessful(): ?self
    {
        return static::query()->successful()->latest('started_at')->first();
    }
}
