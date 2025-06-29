<?php

namespace MonkeysLegion\Mail\Logger;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class Logger
{
    private LoggerInterface $logger;
    private string $app_env;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
        $this->app_env = strtolower($_ENV['APP_ENV'] ?? 'dev');
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    public function log(string $message, array $context = []): void
    {
        match ($this->app_env) {
            'prod', 'production' => $this->logger->warning($message, $context),
            'test', 'testing'    => $this->logger->notice($message, $context),
            default              => $this->logger->debug($message, $context),
        };
    }
}
