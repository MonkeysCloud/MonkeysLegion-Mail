<?php

declare(strict_types=1);

namespace MonkeysLegion\Mail;

use MonkeysLegion\Mail\Service\ServiceContainer;

class Mailer
{
    protected TransportInterface $driver;
    private ServiceContainer $container;

    public function __construct(TransportInterface $driver, ?ServiceContainer $container = null)
    {
        $this->driver = $driver;
        $this->container = $container ?? ServiceContainer::getInstance();
    }

    /**
     * Send an email message.
     * @param string $to The recipient's email address.
     * @param string $subject The subject of the email.
     * @param string $content The content of the email.
     * @param string $contentType The content type of the email 'text/plain' / 'text/html' / 'multipart/mixed' / 'multipart/alternative'.
     * @param array $attachments Any attachments to include with the email.
     * @param array $inlineImages Any inline images to include with the email.
     */
    public function send(string $to, string $subject, string $content, string $contentType = 'text/html', array $attachments = [], array $inlineImages = []): void
    {
        try {
            $message = new Message(
                $to,
                $subject,
                $content,
                $contentType,
                $attachments,
                $inlineImages
            );

            $this->driver->send($message);
        } catch (\Exception $e) {
            error_log("Mail sending failed: " . $e->getMessage());
            throw new \RuntimeException("Failed to send email to $to: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Change the mail driver at runtime.
     *
     * @param string $driverName The name of the driver ('smtp', 'sendmail', 'null', etc.)
     * @param array $config Optional configuration override for the driver
     */
    public function setDriver(string $driverName, array $config = []): void
    {
        $mailConfig = $this->container->getConfig('mail');

        if (!empty($config)) {
            // Merge with existing config
            $driverConfig = array_merge($mailConfig['drivers'][$driverName] ?? [], $config);
        } else {
            $driverConfig = $mailConfig['drivers'][$driverName] ?? [];
        }

        $fullConfig = array_merge($mailConfig, [
            'driver' => $driverName,
            'drivers' => array_merge($mailConfig['drivers'] ?? [], [$driverName => $driverConfig])
        ]);

        $this->driver = MailerFactory::make($fullConfig);
    }

    /**
     * Get the current driver name.
     *
     * @return string The current driver class name
     */
    public function getCurrentDriver(): string
    {
        return get_class($this->driver);
    }

    /**
     * Switch to SMTP driver with optional configuration.
     *
     * @param array $config Optional SMTP configuration
     */
    public function useSmtp(array $config = []): void
    {
        $this->setDriver('smtp', $config);
    }

    /**
     * Switch to null driver (for testing).
     */
    public function useNull(): void
    {
        $this->setDriver('null');
    }

    /**
     * Switch to sendmail driver.
     */
    public function useSendmail(): void
    {
        $this->setDriver('sendmail');
    }
}
