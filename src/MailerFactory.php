<?php

declare(strict_types=1);

namespace MonkeysLegion\Mail;

use MonkeysLegion\Mail\Service\ServiceContainer;
use MonkeysLegion\Mail\Transport\NullTransport;
use MonkeysLegion\Mail\Transport\SendmailTransport;
use MonkeysLegion\Mail\Transport\SmtpTransport;

class MailerFactory
{
    private ServiceContainer $container;

    public function __construct(ServiceContainer $container)
    {
        $this->container = $container;
    }

    /**
     * Create a Mailer instance based on the provided configuration.
     *
     * @param array $config Configuration for the mailer.
     * @return TransportInterface
     * @throws \InvalidArgumentException If the driver is unknown.
     */
    public static function make(array $config = []): TransportInterface
    {
        $driver = $config['driver'] ?? 'null';

        return match ($driver) {
            'smtp' => new SmtpTransport($config['drivers'][$driver]),
            'sendmail' => new SendmailTransport(),
            'null' => new NullTransport(),
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
    public static function createTransport(string $driver, array $config): TransportInterface
    {
        return match ($driver) {
            'smtp' => new SmtpTransport($config),
            'sendmail' => new SendmailTransport(),
            'null' => new NullTransport(),
            default => throw new \InvalidArgumentException("Unknown driver: $driver"),
        };
    }

    /**
     * Get available drivers.
     *
     * @return array List of available driver names
     */
    public static function getAvailableDrivers(): array
    {
        return ['smtp', 'sendmail', 'null'];
    }

    public function setDriver(string $driver): void
    {
        $this->container->set(TransportInterface::class, function () use ($driver) {
            return self::make(['driver' => $driver]);
        });
    }
}
