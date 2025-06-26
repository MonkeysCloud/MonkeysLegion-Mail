<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Redis Connection
    |--------------------------------------------------------------------------
    |
    | This option controls the default Redis connection that will be used
    | for queue operations and other Redis-based services.
    |
    */

    'default' => $_ENV['REDIS_CONNECTION'] ?? 'default',

    /*
    |--------------------------------------------------------------------------
    | Redis Connections
    |--------------------------------------------------------------------------
    |
    | Here you may configure the connection parameters for each Redis server
    | that is used by your application.
    |
    */

    'connections' => [

        'default' => [
            'host' => $_ENV['REDIS_HOST'] ?? '127.0.0.1',
            'port' => (int)($_ENV['REDIS_PORT'] ?? 6379),
            'password' => $_ENV['REDIS_PASSWORD'] ?? null,
            'database' => (int)($_ENV['REDIS_DB'] ?? 0),
            'timeout' => (int)($_ENV['REDIS_TIMEOUT'] ?? 30),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration specific to queue operations using Redis.
    |
    */

    'queue' => [
        'connection' => $_ENV['QUEUE_REDIS_CONNECTION'] ?? 'default',
        'default_queue' => $_ENV['QUEUE_DEFAULT'] ?? 'emails',
        'key_prefix' => $_ENV['QUEUE_PREFIX'] ?? 'queue:',
        'failed_jobs_key' => $_ENV['QUEUE_FAILED_KEY'] ?? 'queue:failed',

        // Worker configuration
        'worker' => [
            'sleep' => (int)($_ENV['QUEUE_SLEEP'] ?? 3),
            'max_tries' => (int)($_ENV['QUEUE_MAX_TRIES'] ?? 3),
            'memory' => (int)($_ENV['QUEUE_MEMORY'] ?? 128), // MB
            'timeout' => (int)($_ENV['QUEUE_TIMEOUT'] ?? 60), // seconds
        ],
    ],
];
