<?php

use TrafficOps\LaravelCloud\Jobs\CheckLaravelCloudDomainJob;
use TrafficOps\LaravelCloud\Models\LaravelCloudDomain;

return [
    'api' => [
        'base_url' => env('LARAVEL_CLOUD_API_BASE_URL', 'https://cloud.laravel.com/api'),
        'token' => env('LARAVEL_CLOUD_API_TOKEN'),
        'environment_id' => env('LARAVEL_CLOUD_ENVIRONMENT_ID'),
        'connect_timeout' => 5,
        'timeout' => 15,
        'retries' => 2,
    ],

    'models' => [
        'domain' => LaravelCloudDomain::class,
    ],

    'migrations' => true,
    'check_job' => CheckLaravelCloudDomainJob::class,
];
