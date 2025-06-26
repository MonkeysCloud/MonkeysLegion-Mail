<?php

declare(strict_types=1);

namespace MonkeysLegion\Mail;

use MonkeysLegion\Mail\Jobs\SendMailJob;
use MonkeysLegion\Mail\Queue\QueueInterface;
use MonkeysLegion\Mail\Queue\RedisQueue;
use MonkeysLegion\Mail\Service\ServiceContainer;
use MonkeysLegion\Mail\Event\MessageSent;

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
        $startTime = microtime(true);

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

            $duration = round((microtime(true) - $startTime) * 1000, 2); // Convert to milliseconds

            // Create message data for event
            $messageData = [
                'to' => $to,
                'subject' => $subject,
                'content' => $content,
                'contentType' => $contentType,
                'attachments' => $attachments,
                'inlineImages' => $inlineImages
            ];

            // Create and log success event
            $messageId = uniqid('direct_', true);
            $sentEvent = new MessageSent($messageId, $messageData, (int)$duration);
            error_log("MessageSent: Direct email to {$to} sent successfully in {$duration}ms");
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

    /**
     * Queue an email for background processing
     * 
     * @param string $to The recipient's email address
     * @param string $subject The subject of the email
     * @param string $content The content of the email
     * @param string $contentType The content type (default: text/html)
     * @param array $attachments File attachments
     * @param array $inlineImages Inline images
     * @param string|null $queue Queue name (optional)
     * @return mixed Job ID
     */
    public function queue(
        string $to,
        string $subject,
        string $content,
        string $contentType = 'text/html',
        array $attachments = [],
        array $inlineImages = [],
        ?string $queue = null
    ): mixed {
        try {
            // Get queue instance from container or create default Redis queue
            $queueInstance = $this->getQueueInstance();

            // Prepare job data
            $jobData = [
                'to' => $to,
                'subject' => $subject,
                'content' => $content,
                'contentType' => $contentType,
                'attachments' => $attachments,
                'inlineImages' => $inlineImages
            ];

            // Push job to queue
            return $queueInstance->push(SendMailJob::class, $jobData, $queue);
        } catch (\Exception $e) {
            error_log("Failed to queue email: " . $e->getMessage());
            throw new \RuntimeException("Failed to queue email to $to: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Get or create queue instance
     */
    private function getQueueInstance(): QueueInterface
    {
        try {
            // Try to get queue from container
            return $this->container->get(QueueInterface::class);
        } catch (\Exception $e) {
            // Fallback to default Redis queue
            return new RedisQueue();
        }
    }
}
