<?php

declare(strict_types=1);

namespace MonkeysLegion\Mail;

use MonkeysLegion\Core\Contracts\FrameworkLoggerInterface;
use MonkeysLegion\Mail\Jobs\SendMailJob;
use MonkeysLegion\Mail\Queue\QueueInterface;
use MonkeysLegion\Mail\Queue\RedisQueue;
use MonkeysLegion\Mail\Service\ServiceContainer;
use MonkeysLegion\Mail\Event\MessageSent;
use MonkeysLegion\Mail\RateLimiter\RateLimiter;
use MonkeysLegion\Mail\Security\DkimSigner;

class Mailer
{
    private FrameworkLoggerInterface $logger;

    public function __construct(
        private TransportInterface $driver,
        private RateLimiter $rateLimiter,
        private ?ServiceContainer $container = null
    ) {
        $this->logger = $this->container->get(FrameworkLoggerInterface::class);
    }

    /**
     * Send an email message.
     * @param string $to The recipient's email address.
     * @param string $subject The subject of the email.
     * @param string $content The content of the email.
     * @param string $contentType The content type of the email 'text/plain' / 'text/html' / 'multipart/mixed' / 'multipart/alternative'.
     * @param array $attachments Any attachments to include with the email.
     */
    public function send(string $to, string $subject, string $content, string $contentType = 'text/html', array $attachments = []): void
    {
        $startTime = microtime(true); // Initialize at the beginning

        try {
            $allowed = $this->rateLimiter->allow();

            if (!$allowed) {
                $this->logger->smartLog("Rate limit exceeded for sending emails", [
                    'to' => $to,
                    'subject' => $subject,
                    'content_type' => $contentType,
                    'has_attachments' => !empty($attachments),
                    'attachment_count' => count($attachments),
                    'driver' => get_class($this->driver)
                ]);
                throw new \RuntimeException("Rate limit exceeded for sending emails. Please try again later.");
            }

            $this->logger->smartLog("Attempting to send email", [
                'to' => $to,
                'subject' => $subject,
                'content_type' => $contentType,
                'has_attachments' => !empty($attachments),
                'attachment_count' => count($attachments),
                'driver' => get_class($this->driver)
            ]);

            $message = new Message(
                $to,
                $subject,
                $content,
                $contentType,
                $attachments
            );

            // Set From header from driver config
            $this->setFromHeader($message);

            // Sign the message if DKIM signing is enabled
            $message = $this->sign($message);

            $this->driver->send($message);
            $duration = round((microtime(true) - $startTime) * 1000, 2); // Convert to milliseconds
            $this->logger->smartLog("Email sent successfully", [
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
            ];

            // Create event - logging is handled inside event constructor
            $messageId = uniqid('direct_', true);
            new MessageSent($messageId, $messageData, (int)$duration, $this->logger);
        } catch (\InvalidArgumentException $e) {
            throw new \InvalidArgumentException($e->getMessage(), 0, $e);
        } catch (\Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);

            $this->logger->warning("Email sending failed", [
                'to' => $to,
                'subject' => $subject,
                'duration_ms' => $duration,
                'driver' => get_class($this->driver),
                'exception' => $e,
                'error_message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw new \RuntimeException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Queue an email for background processing
     * 
     * @param string $to The recipient's email address
     * @param string $subject The subject of the email
     * @param string $content The content of the email
     * @param string $contentType The content type (default: text/html)
     * @param array $attachments File attachments
     * @param string|null $queue Queue name (optional)
     * @return mixed Job ID
     */
    public function queue(
        string $to,
        string $subject,
        string $content,
        string $contentType = 'text/html',
        array $attachments = [],
        ?string $queue = null
    ): mixed {
        $this->logger->smartLog("Queuing email for background processing", [
            'to' => $to,
            'subject' => $subject,
            'content_type' => $contentType,
            'has_attachments' => !empty($attachments),
            'attachment_count' => count($attachments),
            'queue' => $queue ?? 'default'
        ]);

        try {
            // Create message object
            $message = new Message(
                $to,
                $subject,
                $content,
                $contentType,
                $attachments
            );

            // Set From header from driver config
            $this->setFromHeader($message);

            // Sign the message if DKIM signing is enabled
            $message = $this->sign($message);

            // Get queue instance from container or create default Redis queue
            $queueInstance = $this->getQueueInstance();

            $jobId = $queueInstance->push(SendMailJob::class, $message, $queue);

            $this->logger->smartLog("Email queued successfully", [
                'job_id' => $jobId,
                'to' => $to,
                'subject' => $subject,
                'queue' => $queue ?? 'default',
                'queue_class' => get_class($queueInstance),
            ]);

            return $jobId;
        } catch (\Exception $e) {
            $this->logger->warning("Failed to queue email", [
                'to' => $to,
                'subject' => $subject,
                'queue' => $queue ?? 'default',
                'exception' => $e,
                'error_message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            error_log("Failed to queue email: " . $e->getMessage());
            throw new \RuntimeException($e->getMessage(), 0, $e);
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

        $this->logger->smartLog("Changing mail driver", [
            'old_driver' => $oldDriver,
            'new_driver' => $driverName,
            'has_custom_config' => !empty($config)
        ]);

        try {
            $mailConfig = $this->container->getConfig('mail');

            if (!empty($config)) {
                // Merge with existing config
                $driverConfig = array_replace_recursive($mailConfig['drivers'][$driverName] ?? [], $config);
            } else {
                $driverConfig = $mailConfig['drivers'][$driverName] ?? [];
            }

            $fullConfig = array_replace_recursive($mailConfig, [
                'driver' => $driverName,
                'drivers' => array_replace_recursive($mailConfig['drivers'] ?? [], [$driverName => $driverConfig])
            ]);

            $this->driver = MailerFactory::make($fullConfig, $this->logger);

            $this->logger->smartLog("Mail driver changed successfully", [
                'old_driver' => $oldDriver,
                'new_driver' => get_class($this->driver),
                'driver_name' => $driverName
            ]);
        } catch (\Exception $e) {
            $this->logger->error("Failed to change mail driver", [
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
        $this->logger->info("Switching to SMTP driver", [
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
        $this->logger->info("Switching to null driver for testing", [
            'current_driver' => get_class($this->driver)
        ]);
        $this->setDriver('null');
    }

    /**
     * Switch to sendmail driver.
     */
    public function useSendmail(): void
    {
        $this->logger->info("Switching to sendmail driver", [
            'current_driver' => get_class($this->driver)
        ]);
        $this->setDriver('sendmail');
    }

    /**
     * Get or create queue instance
     */
    private function getQueueInstance(): QueueInterface
    {
        try {
            // Try to get queue from container
            $queue = $this->container->get(QueueInterface::class);

            $this->logger->debug("Using queue from container", [
                'queue_class' => get_class($queue)
            ]);

            return $queue;
        } catch (\Exception $e) {
            $this->logger->error("Container queue not available, using fallback Redis queue", [
                'exception' => $e,
                'error_message' => $e->getMessage()
            ]);

            // Fallback to default Redis queue
            return new RedisQueue();
        }
    }

    /**
     * Set the From header on the message using driver configuration
     */
    private function setFromHeader(Message $message): void
    {
        $config = $this->container->getConfig('mail');
        $driverConfig = $config['drivers'][$config['driver']] ?? [];

        if (!empty($driverConfig['from']['address'])) {
            $fromAddress = trim($driverConfig['from']['address']);
            $fromName = trim($driverConfig['from']['name'] ?? '');

            if (!empty($fromName)) {
                // Use sprintf for better control over string formatting
                $fromHeader = sprintf('%s <%s>', $fromName, $fromAddress);
            } else {
                $fromHeader = $fromAddress;
            }

            $message->setFrom($fromHeader);

            $this->logger->debug("From header set on message", [
                'from_address' => $fromAddress,
                'from_name' => $fromName,
                'from_header' => $fromHeader
            ]);
        } else {
            $this->logger->warning("No from address configured in driver config", [
                'driver' => $config['driver'],
                'config_keys' => array_keys($driverConfig)
            ]);
            throw new \RuntimeException("No from address configured in driver config. Please set 'from' in your mail configuration.");
        }
    }

    private function sign(Message $m): Message
    {
        $config = $this->container->getConfig('mail');
        $config = $config['drivers'][$config['driver']];

        if (!DkimSigner::shouldSign(get_class($this->driver), $config)) {
            $this->logger->debug("DKIM signing skipped for local transport", [
                'driver' => get_class($this->driver)
            ]);
            return $m;
        }

        $headers = $m->getHeaders();
        $body = $m->getContent();

        $dkimSigner = new DkimSigner(
            $config['dkim_private_key'],
            $config['dkim_selector'],
            $config['dkim_domain']
        );

        $dkimSignature = $dkimSigner->sign($headers, $body);

        // Set the DKIM signature on the message
        $m->setDkimSignature($dkimSignature);

        $this->logger->debug("DKIM signature generated and attached to message", [
            'signature_length' => strlen($dkimSignature),
            'domain' => $config['dkim_domain'],
            'selector' => $config['dkim_selector']
        ]);

        return $m;
    }
}
