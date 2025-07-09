<?php

declare(strict_types=1);

namespace MonkeysLegion\Mail;

use MonkeysLegion\Mail\Logger\Logger;
use MonkeysLegion\Mail\Service\ServiceContainer;
use MonkeysLegion\Mail\Transport\NullTransport;
use MonkeysLegion\Mail\Transport\SendmailTransport;
use MonkeysLegion\Mail\Transport\SmtpTransport;

class MailerFactory
{
    private Logger $logger;

    public function __construct(private ServiceContainer $container)
    {
        $this->logger = $this->container->get(Logger::class);
    }

    /**
     * Create a Mailer instance based on the provided configuration.
     *
     * @param array $config Configuration for the mailer.
     * @return TransportInterface
     * @throws \InvalidArgumentException If the driver is unknown.
     */
    public static function make(array $config = [], ?Logger $logger = null): TransportInterface
    {
        $driver = $config['driver'] ?? 'null';

        return match ($driver) {
            'smtp' => new SmtpTransport($config, $logger),
            'sendmail' => new SendmailTransport(),
            'null' => new NullTransport($logger),
            default => throw new \InvalidArgumentException("Unknown driver: $driver"),
        };
    }

    /**
     * Create a transport with custom configuration at runtime.
     *
     * @param string $driver The driver name
     * @param array $config The driver configuration
     * @return TransportInterface
     */
    public static function createTransport($driver, $config, ?Logger $logger = null)
    {
        return match ($driver) {
            'smtp' => new SmtpTransport($config, $logger),
            'sendmail' => new SendmailTransport(),
            'null' => new NullTransport($logger),
            default => throw new \InvalidArgumentException("Unknown driver: $driver"),
        };
    }

    public function setDriver(string $driver): void
    {
        $this->container->set(TransportInterface::class, function () use ($driver) {
            return self::make(['driver' => $driver], $this->logger);
        });
    }
}
