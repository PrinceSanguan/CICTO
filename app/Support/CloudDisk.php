<?php

namespace App\Support;

/**
 * Resolves an s3 disk from the bucket Laravel Cloud attached.
 *
 * WHY THIS EXISTS. Cloud does not set AWS_BUCKET, or any other AWS_* variable.
 * It sets one JSON document describing every attached bucket, and its own
 * bootstrap uses that to register a disk under the name in each entry's "disk"
 * key -- typically `public`. The `documents` and `backups` disks are this
 * application's own definitions, so nothing ever filled their `bucket` in. The
 * S3 adapter then fails construction with
 *
 *     AwsS3V3Adapter::__construct(): Argument #2 ($bucket) must be of type
 *     string, null given
 *
 * which reads as a missing credential when it is really two systems that never
 * agreed on a name. Copying the four values out of the JSON into AWS_* by hand
 * fixes it until Cloud rotates the keys, at which point the copies are silently
 * stale. Reading the JSON is the version that keeps working.
 *
 * EVERY VALUE IS PASSED IN, none is read here. env() outside config/ returns
 * null once the configuration is cached, which on a deployed host is always --
 * so this takes the raw values from config/filesystems.php and stays a pure
 * function of them. It is also what makes the whole thing testable without
 * touching the environment.
 */
class CloudDisk
{
    /**
     * The s3 keys for a disk: each override if it says anything, else the
     * attached bucket's own value.
     *
     * @param  mixed  $cloudConfig  The raw LARAVEL_CLOUD_DISK_CONFIG JSON.
     * @param  mixed  $disk  Which attached bucket to draw from, when several are.
     * @param  array<string, mixed>  $overrides  Explicit configuration, which always wins.
     * @return array{key: string|null, secret: string|null, region: string, bucket: string|null, endpoint: string|null, use_path_style_endpoint: bool}
     */
    public static function s3(mixed $cloudConfig, mixed $disk, array $overrides = []): array
    {
        $entry = self::entry(self::entries($cloudConfig), self::string($disk));

        return [
            'key' => self::string($overrides['key'] ?? null)
                ?? self::string($entry['access_key_id'] ?? null),
            'secret' => self::string($overrides['secret'] ?? null)
                ?? self::string($entry['access_key_secret'] ?? null),
            'region' => self::string($overrides['region'] ?? null)
                ?? self::string($entry['default_region'] ?? null)
                ?? 'auto',
            'bucket' => self::string($overrides['bucket'] ?? null)
                ?? self::string($entry['bucket'] ?? null),
            'endpoint' => self::string($overrides['endpoint'] ?? null)
                ?? self::string($entry['endpoint'] ?? null),
            'use_path_style_endpoint' => self::bool($overrides['use_path_style_endpoint'] ?? null)
                ?? (bool) ($entry['use_path_style_endpoint'] ?? false),
        ];
    }

    /**
     * The attached bucket to draw from, or null when that cannot be decided.
     *
     * Naming a disk that is not attached returns nothing rather than falling
     * back to another bucket: an operator who wrote CICTO_DOCUMENTS_CLOUD_DISK
     * meant that one, and quietly using a different bucket is how documents end
     * up somewhere nobody expects.
     *
     * The same reasoning covers the unnamed case. One attached bucket is
     * unambiguous. Several is not -- and guessing wrong would put private,
     * policy-gated documents in the bucket Cloud serves over a public URL, so
     * this returns nothing and lets cicto:host-check report the disk as
     * unwritable until a name is given.
     *
     * @param  list<array<string, mixed>>  $entries
     * @return array<string, mixed>|null
     */
    private static function entry(array $entries, ?string $disk): ?array
    {
        if ($entries === []) {
            return null;
        }

        if ($disk !== null) {
            foreach ($entries as $entry) {
                if (($entry['disk'] ?? null) === $disk) {
                    return $entry;
                }
            }

            return null;
        }

        return count($entries) === 1 ? $entries[0] : null;
    }

    /**
     * Every bucket Cloud has attached, or an empty list off Cloud.
     *
     * Malformed JSON is treated as no buckets rather than thrown: this runs
     * while the configuration is being built, and an exception here takes down
     * artisan itself -- including cicto:host-check, the one command that would
     * have explained the problem.
     *
     * @return list<array<string, mixed>>
     */
    private static function entries(mixed $raw): array
    {
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            return [];
        }

        return array_values(array_filter($decoded, is_array(...)));
    }

    /**
     * Narrow a value to a non-empty string, because the S3 adapter rejects
     * everything else -- and because blank must mean absent.
     *
     * That second part is the difference between this working and not.
     * .env.example ships `AWS_BUCKET=` and `AWS_ACCESS_KEY_ID=` as empty
     * placeholders, and env() returns '' for those, not null. A plain ?? chain
     * would stop dead on the placeholder and never reach the attached bucket,
     * leaving the disk reporting no bucket on a host that has one.
     */
    private static function string(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }

    /** As above for a flag: null when unset or blank, so the attached bucket's own value can win. */
    private static function bool(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL);
    }
}
