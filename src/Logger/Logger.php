<?php

namespace MonkeysLegion\Mail\Logger;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class Logger
{
    private string $app_env;

    public function __construct(private ?LoggerInterface $logger = null)
    {
        $this->app_env = strtolower($_ENV['APP_ENV'] ?? 'dev');
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    public function log(string $message, array $context = []): void
    {
        if ($this->logger === null) {
            $this->logger = new NullLogger();
        }
        match ($this->app_env) {
            'prod', 'production' => $this->logger->warning($message, $context),
            'test', 'testing'    => $this->logger->notice($message, $context),
            default              => $this->logger->debug($message, $context),
        };
    }
}
