<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            // serve => true registers a live GET /storage/{path} route that bypasses
            // DocumentPolicy and writes no audit row. Downloads go through
            // DocumentFileController instead. See docs/implementation/00-architecture.md §8.
            'serve' => false,
            'throw' => false,
            'report' => false,
        ],

        /*
         * Registered documents and their versions. Private, policy-gated, never
         * served directly. `throw` is on so a missing file surfaces instead of
         * returning null.
         *
         * THE DRIVER IS ENV-SWITCHABLE AND THAT IS LOAD-BEARING. `local` is
         * right for a VPS with a real disk. It is CATASTROPHIC on a
         * container platform: Laravel Cloud states plainly that "environment
         * filesystems are ephemeral ... new deployments or re-deployments will
         * reset the filesystem", and each replica has its own. A local
         * documents disk there means every registered document is destroyed by
         * the next deploy, silently, with the database rows still pointing at
         * the missing bytes.
         *
         * On Laravel Cloud set CICTO_DOCUMENTS_DRIVER=s3 and attach a PRIVATE
         * Laravel Object Storage bucket; Cloud injects the AWS_* credentials
         * itself. Do not add 'visibility' => 'public' -- the bucket is
         * Cloudflare R2, which rejects per-object ACLs with NotImplemented and
         * governs access at the bucket level instead.
         *
         * cicto:host-check reports which driver is live and warns when a local
         * disk is paired with a platform that cannot keep it.
         */
        'documents' => [
            'driver' => env('CICTO_DOCUMENTS_DRIVER', 'local'),

            // local
            'root' => storage_path('app/documents'),

            // s3 / R2. Ignored by the local driver.
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION', 'auto'),
            'bucket' => env('CICTO_DOCUMENTS_BUCKET', env('AWS_BUCKET')),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),

            'serve' => false,
            'throw' => true,
            'report' => false,
        ],

        /*
         * §22 backup archives. Private, never served, and ideally pointed at
         * off-site storage -- an archive sitting on the same disk as the
         * documents is not a backup, it is a second copy that dies with them.
         *
         * Same ephemeral-filesystem warning as above, plus one specific to
         * backups: a container's scratch disk is sized at roughly 512MB per 1GB
         * of RAM, so a growing archive can fill it and take the instance down.
         * Point this at object storage on any container platform.
         */
        'backups' => [
            'driver' => env('CICTO_BACKUP_DISK_DRIVER', 'local'),

            // local
            'root' => storage_path('app/backups'),

            // s3 / R2. Ignored by the local driver.
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION', 'auto'),
            'bucket' => env('CICTO_BACKUP_BUCKET', env('AWS_BUCKET')),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),

            'serve' => false,
            'throw' => true,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim((string) env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
