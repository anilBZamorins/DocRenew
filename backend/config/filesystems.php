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
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
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

        /*
         * T21 — document attachment storage (FSD 10 "Encryption at rest" /
         * "Attachment access"). Phase 1 production target is a
         * DigitalOcean Spaces (S3-compatible) bucket — switch the driver
         * to 's3' with ATTACHMENTS_* env vars once Spaces credentials
         * exist; the 'local' driver below is for dev/test. Either way the
         * disk is NEVER public: files are encrypted application-side
         * before being written (AttachmentEncryptor) and are only ever
         * served through a short-lived signed URL
         * (AttachmentController::stream), never a direct disk/bucket URL.
         */
        'attachments' => [
            'driver' => env('ATTACHMENTS_DRIVER', 'local'),
            'root' => storage_path('app/attachments'),
            'key' => env('ATTACHMENTS_KEY', env('AWS_ACCESS_KEY_ID')),
            'secret' => env('ATTACHMENTS_SECRET', env('AWS_SECRET_ACCESS_KEY')),
            'region' => env('ATTACHMENTS_REGION', env('AWS_DEFAULT_REGION')),
            'bucket' => env('ATTACHMENTS_BUCKET'),
            'endpoint' => env('ATTACHMENTS_ENDPOINT'),
            'use_path_style_endpoint' => env('ATTACHMENTS_USE_PATH_STYLE_ENDPOINT', false),
            'visibility' => 'private',
            'throw' => true,
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
