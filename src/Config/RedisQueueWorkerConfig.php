<?php

declare(strict_types=1);

namespace MonkeysLegion\Mail\Config;

final class RedisQueueWorkerConfig
{
    public int $sleep;
    public int $maxTries;
    public int $memory;
    public int $timeout;

    public function __construct(
        int $sleep,
        int $maxTries,
        int $memory,
        int $timeout
    ) {
        if ($sleep < 0) {
            throw new \InvalidArgumentException('Sleep must be non-negative');
        }
        if ($maxTries <= 0) {
            throw new \InvalidArgumentException('Max tries must be positive');
        }
        if ($memory <= 0) {
            throw new \InvalidArgumentException('Memory must be positive');
        }
        if ($timeout <= 0) {
            throw new \InvalidArgumentException('Timeout must be positive');
        }

        $this->sleep = $sleep;
        $this->maxTries = $maxTries;
        $this->memory = $memory;
        $this->timeout = $timeout;
    }
}
