<?php

declare(strict_types=1);

namespace MonkeysLegion\Mail\Config;

final class RedisQueueConfig
{
    public string $connection;
    public string $defaultQueue;
    public string $keyPrefix;
    public string $failedJobsKey;
    public RedisQueueWorkerConfig $worker;

    public function __construct(
        string $connection,
        string $defaultQueue,
        string $keyPrefix,
        string $failedJobsKey,
        RedisQueueWorkerConfig $worker
    ) {
        $this->connection = $connection;
        $this->defaultQueue = $defaultQueue;
        $this->keyPrefix = $keyPrefix;
        $this->failedJobsKey = $failedJobsKey;
        $this->worker = $worker;
    }
}
