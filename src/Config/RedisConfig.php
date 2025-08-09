<?php

declare(strict_types=1);

namespace MonkeysLegion\Mail\Config;

final class RedisConfig
{
    public string $default;
    /** @var array<string, RedisConnectionConfig> */
    public array $connections;
    public RedisQueueConfig $queue;

    /**
     * @param array<string, RedisConnectionConfig> $connections
     */
    public function __construct(string $default, array $connections, RedisQueueConfig $queue)
    {
        if (empty($connections)) {
            throw new \InvalidArgumentException('Connections array cannot be empty');
        }
        if (!isset($connections[$default])) {
            throw new \InvalidArgumentException("Default connection '{$default}' not found in connections");
        }

        $this->default = $default;
        $this->connections = $connections;
        $this->queue = $queue;
    }
}
