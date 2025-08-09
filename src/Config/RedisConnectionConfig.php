<?php

declare(strict_types=1);

namespace MonkeysLegion\Mail\Config;

final class RedisConnectionConfig
{
    public string $host;
    public int $port;
    public ?string $password;
    public int $database;
    public int $timeout;

    public function __construct(string $host, int $port, ?string $password, int $database, int $timeout)
    {
        if ($port <= 0 || $port > 65535) {
            throw new \InvalidArgumentException('Port must be between 1 and 65535');
        }
        if ($database < 0) {
            throw new \InvalidArgumentException('Database must be non-negative');
        }
        if ($timeout <= 0) {
            throw new \InvalidArgumentException('Timeout must be positive');
        }

        $this->host = $host;
        $this->port = $port;
        $this->password = $password;
        $this->database = $database;
        $this->timeout = $timeout;
    }
}
