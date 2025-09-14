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
    private ?FrameworkLoggerInterface $logger = null;

    public function __construct(
        private TransportInterface $driver,
        private RateLimiter $rateLimiter,
        private ?ServiceContainer $container = null
    ) {

        /** @var FrameworkLoggerInterface $logger */
        $logger = $this->container?->get(FrameworkLoggerInterface::class);
        $this->logger = $logger;
    }

    /**
     * Send an email message.
     * @param string $to The recipient's email address.
     * @param string $subject The subject of the email.
     * @param string $content The content of the email.
     * @param string $contentType The content type of the email 'text/plain' / 'text/html' / 'multipart/mixed' / 'multipart/alternative'.
     * @param array<string|int, mixed> $attachments Any attachments to include with the email.
     */
    public function send(string $to, string $subject, string $content, string $contentType = 'text/html', array $attachments = []): void
    {
        $startTime = microtime(true); // Initialize at the beginning

        try {
            $allowed = $this->rateLimiter->allow();

            if (!$allowed) {
                $this->logger?->smartLog("Rate limit exceeded for sending emails", [
                    'to' => $to,
                    'subject' => $subject,
                    'content_type' => $contentType,
                    'has_attachments' => !empty($attachments),
                    'attachment_count' => count($attachments),
                    'driver' => get_class($this->driver)
                ]);
                throw new \RuntimeException("Rate limit exceeded for sending emails. Please try again later.");
            }

            $this->logger?->smartLog("Attempting to send email", [
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
            $this->logger?->smartLog("Email sent successfully", [
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

            $this->logger?->warning("Email sending failed", [
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
     * @param array<string|int, mixed> $attachments File attachments
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
        $this->logger?->smartLog("Queuing email for background processing", [
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

            $this->logger?->smartLog("Email queued successfully", [
                'job_id' => $jobId,
                'to' => $to,
                'subject' => $subject,
                'queue' => $queue ?? 'default',
                'queue_class' => get_class($queueInstance),
            ]);

            return $jobId;
        } catch (\Exception $e) {
            $this->logger?->warning("Failed to queue email", [
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
     * @param array<string, mixed> $config Optional configuration override for the driver
     */
    public function setDriver(string $driverName, array $config = []): void
    {
        $oldDriver = get_class($this->driver);

        $this->logger?->smartLog("Changing mail driver", [
            'old_driver' => $oldDriver,
            'new_driver' => $driverName,
            'has_custom_config' => !empty($config)
        ]);

        if ($this->container === null) {
            $this->logger?->warning("Service container is not available, cannot change mail driver.");
            throw new \RuntimeException("Service container is not available.");
        }

        try {
            $mailConfigRaw = $this->container->getConfig('mail');

            /** @var array<string, mixed> $mailConfig */
            $mailConfig = $mailConfigRaw;

            if (!isset($mailConfig['drivers']) || !is_array($mailConfig['drivers'])) {
                throw new \RuntimeException('Mail config "drivers" key must be an array.');
            }

            $drivers = $mailConfig['drivers'];

            $existingDriverConfig = $drivers[$driverName] ?? [];

            if (!is_array($existingDriverConfig)) {
                $existingDriverConfig = [];
            }

            if (!empty($config)) {
                $driverConfig = array_replace_recursive($existingDriverConfig, $config);
            } else {
                $driverConfig = $existingDriverConfig;
            }

            /** @var array<string, mixed> $fullConfig */
            $fullConfig = array_replace_recursive($mailConfig, [
                'driver' => $driverName,
                'drivers' => array_replace_recursive($drivers, [$driverName => $driverConfig])
            ]);

            $this->driver = MailerFactory::make($fullConfig, $this->logger);

            $this->logger?->smartLog("Mail driver changed successfully", [
                'old_driver' => $oldDriver,
                'new_driver' => get_class($this->driver),
                'driver_name' => $driverName
            ]);
        } catch (\Exception $e) {
            $this->logger?->error("Failed to change mail driver", [
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
     * @param array<string, mixed> $config Optional SMTP configuration
     */
    public function useSmtp(array $config = []): void
    {
        $this->logger?->info("Switching to SMTP driver", [
            'current_driver' => get_class($this->driver),
            'has_custom_config' => !empty($config)
        ]);
        $this->setDriver('smtp', $config);
    }

    /**
     * Switch to null driver (for testing).
     *
     * @param array<string, mixed> $config Optional configuration
     */
    public function useNull(array $config = []): void
    {
        $this->logger?->info("Switching to null driver for testing", [
            'current_driver' => get_class($this->driver),
            'has_custom_config' => !empty($config)
        ]);
        $this->setDriver('null', $config);
    }

    /**
     * Switch to sendmail driver.
     *
     * @param array<string, mixed> $config Optional configuration
     */
    public function useSendmail(array $config = []): void
    {
        $this->logger?->info("Switching to sendmail driver", [
            'current_driver' => get_class($this->driver),
            'has_custom_config' => !empty($config)
        ]);
        $this->setDriver('sendmail', $config);
    }

    /**
     * Switch to Mailgun driver with optional configuration.
     *
     * @param array<string, mixed> $config Optional Mailgun configuration
     */
    public function useMailgun(array $config = []): void
    {
        $this->logger?->info("Switching to Mailgun driver", [
            'current_driver' => get_class($this->driver),
            'has_custom_config' => !empty($config)
        ]);
        $this->setDriver('mailgun', $config);
    }

    private function getQueueInstance(): QueueInterface
    {
        if ($this->container === null) {
            $this->logger?->warning("Service container is null, using fallback Redis queue");
            return new RedisQueue();
        }

        try {
            /** @var QueueInterface $queue */
            $queue = $this->container->get(QueueInterface::class);

            $this->logger?->debug("Using queue from container", [
                'queue_class' => get_class($queue)
            ]);

            return $queue;
        } catch (\Exception $e) {
            $this->logger?->error("Container queue not available, using fallback Redis queue", [
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
        if ($this->container === null) {
            $this->logger?->error("Service container is null, cannot set From header");
            throw new \RuntimeException("Service container is required to set From header");
        }

        $config = $this->container->getConfig('mail');

        if (!array_key_exists('driver', $config)) {
            $this->logger?->error("Mail config missing 'driver' key");
            throw new \RuntimeException("Mail driver is not configured");
        }
        /** @var string $driverName */
        $driverName = $config['driver'];

        if (!array_key_exists('drivers', $config) || !is_array($config['drivers'])) {
            $this->logger?->error("Mail config missing 'drivers' key or it is not an array");
            throw new \RuntimeException("Mail drivers configuration is invalid");
        }
        $drivers = $config['drivers'];

        if (!array_key_exists($driverName, $drivers) || !is_array($drivers[$driverName])) {
            $this->logger?->error("Driver config for '{$driverName}' is missing or not an array");
            throw new \RuntimeException("Driver config for '{$driverName}' must be an array");
        }
        $driverConfig = $drivers[$driverName];

        if (!array_key_exists('from', $driverConfig) || !is_array($driverConfig['from'])) {
            $this->logger?->error("Driver config for '{$driverName}' missing 'from' key or it is not an array");
            throw new \RuntimeException("Driver config for '{$driverName}' must have a 'from' array");
        }
        $from = $driverConfig['from'];

        if (empty($from['address']) || !filter_var($from['address'], FILTER_VALIDATE_EMAIL) || !is_string($from['address'])) {
            $this->logger?->error("Invalid 'from.address' configured in mail driver '{$driverName}'");
            throw new \RuntimeException("Invalid 'from.address' configured in mail driver '{$driverName}'. Please update your mail configuration.");
        }

        $fromAddress = trim($from['address']);
        $fromName = isset($from['name']) && is_string($from['name']) ? trim($from['name']) : '';

        $fromHeader = $fromName !== ''
            ? sprintf('%s <%s>', $fromName, $fromAddress)
            : $fromAddress;

        $message->setFrom($fromHeader);

        $this->logger?->debug("From header set on message", [
            'from_address' => $fromAddress,
            'from_name' => $fromName,
            'from_header' => $fromHeader
        ]);
    }

    private function sign(Message $m): Message
    {
        if ($this->container === null) {
            $this->logger?->notice("Service container is null, cannot sign message");
            return $m;
        }

        $config = $this->container->getConfig('mail');

        if (!isset($config['driver']) || !is_string($config['driver'])) {
            $this->logger?->error("Mail config missing 'driver' key or it is not a string");
            throw new \RuntimeException("Mail driver is not configured");
        }

        $driverName = $config['driver'];

        if (!isset($config['drivers']) || !is_array($config['drivers'])) {
            $this->logger?->error("Mail config missing 'drivers' array");
            throw new \RuntimeException("Mail drivers config missing");
        }

        if (!isset($config['drivers'][$driverName]) || !is_array($config['drivers'][$driverName])) {
            $this->logger?->error("Driver config for '{$driverName}' is missing or not an array");
            throw new \RuntimeException("Driver config for '{$driverName}' must be an array");
        }

        $driverConfig = $config['drivers'][$driverName];

        // Provide default empty strings for keys to avoid "undefined offset"
        $dkimConfig = [
            'dkim_private_key' => safeString($driverConfig['dkim_private_key'] ?? null),
            'dkim_selector' => safeString($driverConfig['dkim_selector'] ?? null),
            'dkim_domain' => safeString($driverConfig['dkim_domain'] ?? null),
        ];

        if (!DkimSigner::shouldSign(get_class($this->driver), $dkimConfig)) {
            $this->logger?->debug("DKIM signing skipped for local transport", [
                'driver' => get_class($this->driver),
            ]);
            return $m;
        }

        $headers = $m->getHeaders();

        $body = $m->getContent();

        $dkimSigner = new DkimSigner(
            $dkimConfig['dkim_private_key'],
            $dkimConfig['dkim_selector'],
            $dkimConfig['dkim_domain']
        );

        $dkimSignature = $dkimSigner->sign($headers, $body);

        $m->setDkimSignature($dkimSignature);

        $this->logger?->debug("DKIM signature generated and attached to message", [
            'signature_length' => strlen($dkimSignature),
            'domain' => $dkimConfig['dkim_domain'],
            'selector' => $dkimConfig['dkim_selector'],
        ]);

        return $m;
    }
}
