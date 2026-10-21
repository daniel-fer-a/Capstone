<?php

$privateDisk = env('ADMISSIONS_STORAGE_DRIVER', 'local') === 's3' ? [
    'driver' => 's3', 'key' => env('AWS_ACCESS_KEY_ID'), 'secret' => env('AWS_SECRET_ACCESS_KEY'),
    'region' => env('AWS_DEFAULT_REGION'), 'bucket' => env('AWS_BUCKET'),
    'endpoint' => env('AWS_ENDPOINT'), 'use_path_style_endpoint' => (bool) env('AWS_USE_PATH_STYLE_ENDPOINT', false),
    'root' => env('ADMISSIONS_S3_PREFIX', 'admissions'), 'visibility' => 'private', 'throw' => true,
] : [
    'driver' => 'local', 'root' => storage_path('app/private/admissions'),
    'serve' => false, 'visibility' => 'private', 'throw' => true,
    'permissions' => ['file' => ['private' => 0600], 'dir' => ['private' => 0700]],
];

return [
    'default' => 'local',
    'disks' => [
        'local' => [
            'driver' => 'local', 'root' => storage_path('app/private'),
            'serve' => false, 'visibility' => 'private', 'throw' => true,
        ],
        'admissions-private' => $privateDisk,
    ],
    'links' => [],
];
