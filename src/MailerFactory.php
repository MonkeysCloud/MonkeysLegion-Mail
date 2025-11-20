<?php

declare(strict_types=1);

namespace MonkeysLegion\Mail;

use MonkeysLegion\Logger\Contracts\MonkeysLoggerInterface;
use MonkeysLegion\Mail\Enums\MailDriverName;
use MonkeysLegion\Mail\Service\ServiceContainer;
use MonkeysLegion\Mail\Transport\MailgunTransport;
use MonkeysLegion\Mail\Transport\NullTransport;
use MonkeysLegion\Mail\Transport\SendmailTransport;
use MonkeysLegion\Mail\Transport\SmtpTransport;

class MailerFactory
{
    private ?MonkeysLoggerInterface $logger;

    public function __construct(private ServiceContainer $container)
    {
        try {
            /** @var MonkeysLoggerInterface $logger */
            $logger = $this->container->get(MonkeysLoggerInterface::class);
            $this->logger = $logger;
        } catch (\Exception $e) {
            $this->logger?->error($e->getMessage());
            $this->logger = null;
        }
    }

    /**
     * Create a Mailer instance based on the provided configuration.
     *
     * @param array<string, mixed> $config Configuration for the mailer.
     * @return TransportInterface
     * @throws \InvalidArgumentException If the driver is unknown.
     */
    public static function make(array $config = [], ?MonkeysLoggerInterface $logger = null): TransportInterface
    {
        $driver = safeString($config['driver'], 'null');
        return self::getTransport($driver, $config, $logger);
    }

    /**
     * Create a transport with custom configuration at runtime.
     *
     * @param string $driver The driver name
     * @param array<string, mixed> $config The driver configuration
     * @return TransportInterface
     */
    public static function createTransport($driver, $config, ?MonkeysLoggerInterface $logger = null)
    {
        $driver = safeString($driver, 'null');
        return self::getTransport($driver, $config, $logger);
    }

    /**
     * Set the mail driver.
     *
     * @param string $driver The driver name
     * @return TransportInterface
     * @throws \InvalidArgumentException If the driver is unknown.
     */
    public function setDriver(string $driver): TransportInterface
    {
        try {
            $config = $this->container->getConfig('mail');
            $driver = $this->validateDriver($driver);
            $config = array_merge(['driver' => $driver], $config);

            $this->container->set(TransportInterface::class, function () use ($config) {
                return self::make($config, $this->logger);
            });

            /** @var TransportInterface $transport */
            $transport = $this->container->get(TransportInterface::class);
            return $transport;
        } catch (\InvalidArgumentException $e) {
            $this->logger?->error($e->getMessage());
            throw $e;
        }
    }

    /**
     * Validate the mail driver.
     *
     * @param string $driver
     * @return string
     * @throws \InvalidArgumentException
     */
    private static function validateDriver(string $driver): string
    {
        $driverString = strtolower(safeString($driver, 'null'));
        $driverEnum = MailDriverName::tryFrom($driverString);
        if (!$driverEnum) throw new \InvalidArgumentException("Unknown driver: $driverString");
        return $driverEnum->value;
    }

    /**
     * Get the configuration for a specific driver.
     *
     * @param string $driver
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     * @throws \InvalidArgumentException
     */
    private static function getDriverConfig(string $driver, array $config): array
    {
        if (!isset($config['drivers'])) {
            throw new \InvalidArgumentException("No drivers configured");
        }
        /** @var array<string, mixed> $config */
        $config = $config['drivers'];
        /** @var array<string, mixed> $config */
        $config = match ($driver) {
            MailDriverName::SMTP->value => $config[MailDriverName::SMTP->value],
            MailDriverName::SENDMAIL->value => $config[MailDriverName::SENDMAIL->value],
            MailDriverName::MAILGUN->value => $config[MailDriverName::MAILGUN->value],
            MailDriverName::NULL->value => $config[MailDriverName::NULL->value],
            default => throw new \InvalidArgumentException("Unknown driver: $driver"),
        };

        return $config;
    }

    /**
     * Get the transport instance for the specified driver.
     *
     * @param string $driver
     * @param array<string, mixed> $config
     * @return TransportInterface
     * @throws \InvalidArgumentException If the driver is unknown.
     */
    private static function getTransport(string $driver, array $config, ?MonkeysLoggerInterface $logger): TransportInterface
    {
        $driver = self::validateDriver($driver);
        $config = self::getDriverConfig($driver, $config);
        return match ($driver) {
            MailDriverName::SMTP->value => new SmtpTransport($config, $logger),
            MailDriverName::SENDMAIL->value => new SendmailTransport($config, $logger),
            MailDriverName::MAILGUN->value => new MailgunTransport($config, $logger),
            MailDriverName::NULL->value => new NullTransport($config, $logger),
            default => throw new \InvalidArgumentException("Unknown driver: $driver"),
        };
    }
}
