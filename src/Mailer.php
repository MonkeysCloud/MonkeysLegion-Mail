<?php

declare(strict_types=1);

namespace MonkeysLegion\Mail;

use MonkeysLegion\Mail\Jobs\SendMailJob;
use MonkeysLegion\Mail\Queue\QueueInterface;
use MonkeysLegion\Mail\Queue\RedisQueue;
use MonkeysLegion\Mail\Service\ServiceContainer;
use MonkeysLegion\Mail\Event\MessageSent;
use MonkeysLegion\Mail\Logger\Logger;

class Mailer
{
    private Logger $logger;

    public function __construct(
        private TransportInterface $driver,
        private ?ServiceContainer $container = null
    ) {
        $this->logger = $this->container->get(Logger::class);
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
        $this->logger->log("Attempting to send email", [
            'to' => $to,
            'subject' => $subject,
            'content_type' => $contentType,
            'has_attachments' => !empty($attachments),
            'attachment_count' => count($attachments),
            'has_inline_images' => !empty($inlineImages),
            'inline_image_count' => count($inlineImages),
            'driver' => get_class($this->driver)
        ]);
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
            $this->logger->log("Email sent successfully", [
                'to' => $to,
                'subject' => $subject,
                'duration_ms' => $duration,
                'driver' => get_class($this->driver)
            ]);
            // Create message data for event
            $messageData = [
                'to' => $to,
                'subject' => $subject,
                'content' => $content,
                'contentType' => $contentType,
                'attachments' => $attachments,
                'inlineImages' => $inlineImages
            ];

            // Create event - logging is handled inside event constructor
            $messageId = uniqid('direct_', true);
            $sentEvent = new MessageSent($messageId, $messageData, (int)$duration, $this->logger);
        } catch (\Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);

            $this->logger->log("Email sending failed", [
                'to' => $to,
                'subject' => $subject,
                'duration_ms' => $duration,
                'driver' => get_class($this->driver),
                'exception' => $e,
                'error_message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

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
        $oldDriver = get_class($this->driver);

        $this->logger->log("Changing mail driver", [
            'old_driver' => $oldDriver,
            'new_driver' => $driverName,
            'has_custom_config' => !empty($config)
        ]);

        try {
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

            $this->driver = MailerFactory::make($fullConfig, $this->logger);

            $this->logger->log("Mail driver changed successfully", [
                'old_driver' => $oldDriver,
                'new_driver' => get_class($this->driver),
                'driver_name' => $driverName
            ]);
        } catch (\Exception $e) {
            $this->logger->log("Failed to change mail driver", [
                'old_driver' => $oldDriver,
                'attempted_driver' => $driverName,
                'exception' => $e,
                'error_message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
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
        $this->logger->log("Switching to SMTP driver", [
            'current_driver' => get_class($this->driver),
            'has_custom_config' => !empty($config)
        ]);
        $this->setDriver('smtp', $config);
    }

    /**
     * Switch to null driver (for testing).
     */
    public function useNull(): void
    {
        $this->logger->log("Switching to null driver for testing", [
            'current_driver' => get_class($this->driver)
        ]);
        $this->setDriver('null');
    }

    /**
     * Switch to sendmail driver.
     */
    public function useSendmail(): void
    {
        $this->logger->log("Switching to sendmail driver", [
            'current_driver' => get_class($this->driver)
        ]);
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
        $this->logger->log("Queuing email for background processing", [
            'to' => $to,
            'subject' => $subject,
            'content_type' => $contentType,
            'has_attachments' => !empty($attachments),
            'attachment_count' => count($attachments),
            'has_inline_images' => !empty($inlineImages),
            'inline_image_count' => count($inlineImages),
            'queue' => $queue ?? 'default'
        ]);

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
            $jobId = $queueInstance->push(SendMailJob::class, $jobData, $queue);

            $this->logger->log("Email queued successfully", [
                'job_id' => $jobId,
                'to' => $to,
                'subject' => $subject,
                'queue' => $queue ?? 'default',
                'queue_class' => get_class($queueInstance)
            ]);

            return $jobId;
        } catch (\Exception $e) {
            $this->logger->log("Failed to queue email", [
                'to' => $to,
                'subject' => $subject,
                'queue' => $queue ?? 'default',
                'exception' => $e,
                'error_message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

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
            $queue = $this->container->get(QueueInterface::class);

            $this->logger->log("Using queue from container", [
                'queue_class' => get_class($queue)
            ]);

            return $queue;
        } catch (\Exception $e) {
            $this->logger->log("Container queue not available, using fallback Redis queue", [
                'exception' => $e,
                'error_message' => $e->getMessage()
            ]);

            // Fallback to default Redis queue
            return new RedisQueue();
        }
    }
}
