<?php

use MonkeysLegion\Mail\Enums\MailDefaults;

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

    'default' => $_ENV['REDIS_CONNECTION'] ?? MailDefaults::QUEUE_CONNECTION,

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
            'host' => $_ENV['REDIS_HOST'] ?? MailDefaults::REDIS_HOST,
            'port' => (int)($_ENV['REDIS_PORT'] ?? MailDefaults::REDIS_PORT),
            'password' => $_ENV['REDIS_PASSWORD'] ?? MailDefaults::REDIS_PASSWORD,
            'database' => (int)($_ENV['REDIS_DB'] ?? MailDefaults::REDIS_DB),
            'timeout' => (int)($_ENV['REDIS_TIMEOUT'] ?? MailDefaults::REDIS_TIMEOUT),
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
        'connection' => $_ENV['QUEUE_REDIS_CONNECTION'] ?? MailDefaults::QUEUE_CONNECTION,
        'default_queue' => $_ENV['QUEUE_DEFAULT'] ?? MailDefaults::QUEUE_NAME,
        'key_prefix' => $_ENV['QUEUE_PREFIX'] ?? MailDefaults::QUEUE_PREFIX,
        'failed_jobs_key' => $_ENV['QUEUE_FAILED_KEY'] ?? MailDefaults::QUEUE_FAILED_KEY,

        // Worker configuration
        'worker' => [
            'sleep' => (int)($_ENV['QUEUE_SLEEP'] ?? MailDefaults::QUEUE_WORKER_SLEEP),
            'max_tries' => (int)($_ENV['QUEUE_MAX_TRIES'] ?? MailDefaults::QUEUE_WORKER_MAX_TRIES),
            'memory' => (int)($_ENV['QUEUE_MEMORY'] ?? MailDefaults::QUEUE_WORKER_MEMORY), // MB
            'timeout' => (int)($_ENV['QUEUE_TIMEOUT'] ?? MailDefaults::QUEUE_WORKER_TIMEOUT), // seconds
        ],
    ],
];
