<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * §2 system settings. Written only by Super Admins.
 *
 * setting_value is cast `encrypted` UNCONDITIONALLY. A per-row "is this one
 * secret" flag is a flag somebody forgets to set on the row that mattered, and
 * this table is where SMTP credentials and the backup passphrase live.
 *
 * @property string $setting_key
 * @property string|null $setting_value
 * @property string $value_type
 * @property string $group_name
 * @property bool $is_secret
 * @property string|null $label
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AppSetting extends Model
{
    protected $primaryKey = 'setting_key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'setting_value' => 'encrypted',
            'is_secret' => 'boolean',
        ];
    }

    /**
     * Per-request memo only. Deliberately NOT a persistent cache.
     *
     * An earlier version cached the decrypted values with
     * Cache::rememberForever. CACHE_STORE is `database`, so that wrote the
     * plaintext SMTP password straight into the `cache` table -- defeating the
     * `encrypted` cast entirely and making the §21 claim false. Anything that
     * survives the request must stay encrypted, so the memo lives in memory and
     * dies with the process.
     *
     * The table holds a handful of rows and is read rarely (mail, backups), so
     * one query is not worth risking that.
     *
     * @var array<string, mixed>|null
     */
    private static ?array $memo = null;

    protected static function booted(): void
    {
        static::saved(fn () => self::$memo = null);
        static::deleted(fn () => self::$memo = null);
    }

    /**
     * @return array<string, mixed>
     */
    public static function all_cached(): array
    {
        return self::$memo ??= static::query()
            ->get()
            ->mapWithKeys(fn (self $s) => [$s->setting_key => $s->typedValue()])
            ->all();
    }

    /** Tests and long-running processes need to be able to drop the memo. */
    public static function flushMemo(): void
    {
        self::$memo = null;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return static::all_cached()[$key] ?? $default;
    }

    public static function put(string $key, mixed $value, string $type = 'string', string $group = 'general', bool $secret = false, ?string $label = null): self
    {
        $setting = static::query()->firstOrNew(['setting_key' => $key]);

        $setting->forceFill([
            'setting_value' => $value === null ? null : (string) (is_array($value) ? json_encode($value) : $value),
            'value_type' => $type,
            'group_name' => $group,
            'is_secret' => $secret,
            'label' => $label ?? $setting->label,
        ])->save();

        return $setting;
    }

    public function typedValue(): mixed
    {
        $raw = $this->setting_value;

        if ($raw === null) {
            return null;
        }

        return match ($this->value_type) {
            'int' => (int) $raw,
            'bool' => filter_var($raw, FILTER_VALIDATE_BOOLEAN),
            'json' => json_decode($raw, true),
            default => $raw,
        };
    }

    /** What a Super Admin is allowed to see in the UI. */
    public function displayValue(): ?string
    {
        if ($this->setting_value === null) {
            return null;
        }

        return $this->is_secret ? str_repeat('•', 12) : (string) $this->setting_value;
    }
}
