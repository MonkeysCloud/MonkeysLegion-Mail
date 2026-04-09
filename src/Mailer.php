<?php

declare(strict_types=1);

namespace MonkeysLegion\Mail;

use MonkeysLegion\Logger\Contracts\MonkeysLoggerInterface;
use MonkeysLegion\Mail\Event\MessageFailed;
use MonkeysLegion\Mail\Event\MessageSent;
use MonkeysLegion\Mail\Jobs\SendMailJob;
use MonkeysLegion\Mail\RateLimiter\RateLimiter;
use MonkeysLegion\Mail\Security\DkimSigner;
use MonkeysLegion\Queue\Contracts\QueueDispatcherInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

use Exception;
use InvalidArgumentException;
use RuntimeException;

class Mailer
{
    /**
     * Per-instance listeners called when a message is sent successfully.
     * Registered via onSent(). Isolated to this Mailer instance.
     *
     * @var list<callable(MessageSent): void>
     */
    private array $sentListeners = [];

    /**
     * Per-instance listeners called when a message fails.
     * Registered via onFailed(). Isolated to this Mailer instance.
     *
     * @var list<callable(MessageFailed): void>
     */
    private array $failedListeners = [];

    /**
     * FQCN of the Mailable currently being dispatched (set by Mailable::send/queue).
     * Carried into events so listeners can filter by mailable class.
     */
    private ?string $currentMailableClass = null;

    /**
     * Mailer constructor.
     *
     * @param TransportInterface       $driver
     * @param RateLimiter              $rateLimiter
     * @param QueueDispatcherInterface $dispatcher
     * @param MonkeysLoggerInterface|null $logger
     * @param array<string, mixed>     $rawConfig
     * @param EventDispatcherInterface|null $eventDispatcher  PSR-14 global dispatcher (optional).
     */
    public function __construct(
        private TransportInterface $driver,
        private RateLimiter $rateLimiter,
        private QueueDispatcherInterface $dispatcher,
        private ?MonkeysLoggerInterface $logger = null,
        private array $rawConfig = [],
        private ?EventDispatcherInterface $eventDispatcher = null,
    ) {
        $this->logger?->debug(
            "Mailer initialized with driver: " . \get_class($driver),
            [
                'driver'           => \get_class($driver),
                'rate_limiter'     => \get_class($rateLimiter),
                'queue_dispatcher' => \get_class($dispatcher),
                'mlc_config_keys'  => array_keys($this->rawConfig),
                'event_dispatcher' => $eventDispatcher !== null ? \get_class($eventDispatcher) : null,
            ]
        );
    }

    // ── Per-instance listener registration ───────────────────────────────────

    /**
     * Register a listener that fires on *this* Mailer instance when a message is sent.
     *
     * Use this when you need mailable-scoped or mailer-scoped behaviour instead of a
     * global PSR-14 dispatcher.
     *
     * Example — per-mailer:
     *   $mailer->onSent(function(MessageSent $e) { ... });
     *
     * Example — per-mailable (inside WelcomeMail::build()):
     *   $this->getMailer()->onSent(function(MessageSent $e) {
     *       if ($e->getMailableClass() === static::class) { ... }
     *   });
     *
     * @param callable(MessageSent): void $listener
     */
    public function onSent(callable $listener): static
    {
        $this->sentListeners[] = $listener;
        return $this;
    }

    /**
     * Register a listener that fires on *this* Mailer instance when a message fails.
     *
     * @param callable(MessageFailed): void $listener
     */
    public function onFailed(callable $listener): static
    {
        $this->failedListeners[] = $listener;
        return $this;
    }

    /**
     * Set the active mailable class context.
     * Called internally by Mailable before invoking send()/queue() so that
     * the emitted events know which mailable triggered them.
     *
     * @internal
     */
    public function setMailableContext(?string $mailableClass): static
    {
        $this->currentMailableClass = $mailableClass;
        return $this;
    }

    /**
     * Send an email message synchronously.
     *
     * Fires {@see MessageSent} on success and {@see MessageFailed} on failure
     * through the injected PSR-14 event dispatcher (if any).
     *
     * @param string               $to          Recipient email address.
     * @param string               $subject     Email subject.
     * @param string               $content     Email body.
     * @param string               $contentType MIME content-type (default: text/html).
     * @param array<string|int, mixed> $attachments File attachments.
     */
    public function send(
        string $to,
        string $subject,
        string $content,
        string $contentType = 'text/html',
        array $attachments = []
    ): void {
        $startTime = microtime(true);

        try {
            $allowed = $this->rateLimiter->allow();

            if (!$allowed) {
                $this->logger?->smartLog("Rate limit exceeded for sending emails", [
                    'to'               => $to,
                    'subject'          => $subject,
                    'content_type'     => $contentType,
                    'has_attachments'  => !empty($attachments),
                    'attachment_count' => \count($attachments),
                    'driver'           => \get_class($this->driver),
                ]);
                throw new RuntimeException("Rate limit exceeded for sending emails. Please try again later.");
            }

            $this->logger?->smartLog("Attempting to send email", [
                'to'               => $to,
                'subject'          => $subject,
                'content_type'     => $contentType,
                'has_attachments'  => !empty($attachments),
                'attachment_count' => \count($attachments),
                'driver'           => \get_class($this->driver),
            ]);

            $message = new Message($to, $subject, $content, $contentType, $attachments);

            $this->setFromHeader($message);
            $message = $this->sign($message);

            $this->driver->send($message);

            $duration = (int) round((microtime(true) - $startTime) * 1000);

            $this->logger?->smartLog("Email sent successfully", [
                'to'          => $to,
                'subject'     => $subject,
                'duration_ms' => $duration,
                'driver'      => \get_class($this->driver),
            ]);

            $messageData = [
                'to'          => $to,
                'subject'     => $subject,
                'content'     => $content,
                'contentType' => $contentType,
                'attachments' => $attachments,
            ];

            $messageId = uniqid('direct_', true);

            // Fire PSR-14 MessageSent event + per-instance listeners
            $sent = new MessageSent(
                $messageId,
                $messageData,
                $duration,
                $this->logger,
                $this->eventDispatcher,
                $this->currentMailableClass,
            );
            foreach ($this->sentListeners as $listener) {
                $listener($sent);
            }
        } catch (InvalidArgumentException $e) {
            throw new InvalidArgumentException($e->getMessage(), 0, $e);
        } catch (Exception $e) {
            $duration = (int) round((microtime(true) - $startTime) * 1000);

            $this->logger?->warning("Email sending failed", [
                'to'            => $to,
                'subject'       => $subject,
                'duration_ms'   => $duration,
                'driver'        => \get_class($this->driver),
                'exception'     => $e,
                'error_message' => $e->getMessage(),
                'trace'         => $e->getTraceAsString(),
            ]);

            $messageData = [
                'to'          => $to,
                'subject'     => $subject,
                'content'     => $content,
                'contentType' => $contentType,
                'attachments' => $attachments,
                'job'         => 'direct_send',
            ];

            $messageId = uniqid('fail_', true);

            // Fire PSR-14 MessageFailed event + per-instance listeners
            $failed = new MessageFailed(
                $messageId,
                $messageData,
                $e,
                1,
                false,
                $this->logger,
                $this->eventDispatcher,
                $this->currentMailableClass,
            );
            foreach ($this->failedListeners as $listener) {
                $listener($failed);
            }

            throw new RuntimeException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Queue an email for background processing.
     *
     * Fires {@see MessageFailed} through the PSR-14 dispatcher if queueing fails.
     *
     * @param string                   $to          Recipient address.
     * @param string                   $subject     Subject line.
     * @param string                   $content     Body content.
     * @param string                   $contentType MIME type (default: text/html).
     * @param array<string|int, mixed> $attachments File attachments.
     * @param string|null              $queue       Queue name (null = 'default').
     * @return mixed                                true on successful queue dispatch.
     */
    public function queue(
        string $to,
        string $subject,
        string $content,
        string $contentType = 'text/html',
        array $attachments = [],
        ?string $queue = null
    ): mixed {
        $queueName = $queue ?? 'default';

        $this->logger?->smartLog("Queuing email for background processing", [
            'to'               => $to,
            'subject'          => $subject,
            'content_type'     => $contentType,
            'has_attachments'  => !empty($attachments),
            'attachment_count' => \count($attachments),
            'queue'            => $queueName,
        ]);

        try {
            $message = new Message($to, $subject, $content, $contentType, $attachments);
            $this->setFromHeader($message);
            $message = $this->sign($message);

            $this->dispatcher->dispatch(
                job: new SendMailJob($message),
                queue: $queueName,
            );

            $this->logger?->smartLog("Email queued successfully", [
                'to'               => $to,
                'subject'          => $subject,
                'queue'            => $queueName,
                'dispatcher_class' => \get_class($this->dispatcher),
            ]);

            return true;
        } catch (Exception $e) {
            $this->logger?->warning("Failed to queue email", [
                'to'            => $to,
                'subject'       => $subject,
                'queue'         => $queueName,
                'exception'     => $e,
                'error_message' => $e->getMessage(),
                'trace'         => $e->getTraceAsString(),
            ]);

            $messageData = [
                'to'          => $to,
                'subject'     => $subject,
                'job'         => SendMailJob::class,
                'queue'       => $queueName,
            ];

            $messageId = uniqid('queue_fail_', true);

            // Fire PSR-14 MessageFailed event + per-instance listeners for queue failures
            $failed = new MessageFailed(
                $messageId,
                $messageData,
                $e,
                1,
                false,
                $this->logger,
                $this->eventDispatcher,
                $this->currentMailableClass,
            );
            foreach ($this->failedListeners as $listener) {
                $listener($failed);
            }

            throw new RuntimeException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Change the mail driver at runtime.
     *
     * @param string               $driverName Target driver name ('smtp', 'sendmail', 'null', …)
     * @param array<string, mixed> $config     Optional configuration override.
     */
    public function setDriver(string $driverName, array $config = []): void
    {
        $oldDriver = \get_class($this->driver);

        $this->logger?->smartLog("Changing mail driver", [
            'old_driver'        => $oldDriver,
            'new_driver'        => $driverName,
            'has_custom_config' => !empty($config),
        ]);

        try {
            if (!isset($this->rawConfig['drivers']) || !\is_array($this->rawConfig['drivers'])) {
                throw new RuntimeException('Mail config "drivers" key must be an array.');
            }

            $drivers = $this->rawConfig['drivers'];
            $existingDriverConfig = \is_array($drivers[$driverName] ?? null) ? $drivers[$driverName] : [];

            $driverConfig = !empty($config)
                ? array_replace_recursive($existingDriverConfig, $config)
                : $existingDriverConfig;

            /** @var array<string, mixed> $fullConfig */
            $fullConfig = array_replace_recursive($this->rawConfig, [
                'driver'  => $driverName,
                'drivers' => array_replace_recursive($drivers, [$driverName => $driverConfig]),
            ]);

            $this->driver = MailerFactory::make($fullConfig, $this->logger);

            $this->logger?->smartLog("Mail driver changed successfully", [
                'old_driver'  => $oldDriver,
                'new_driver'  => \get_class($this->driver),
                'driver_name' => $driverName,
            ]);
        } catch (Exception $e) {
            $this->logger?->error("Failed to change mail driver", [
                'old_driver'       => $oldDriver,
                'attempted_driver' => $driverName,
                'exception'        => $e,
                'error_message'    => $e->getMessage(),
                'trace'            => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /** @return string Current driver FQCN */
    public function getCurrentDriver(): string
    {
        return \get_class($this->driver);
    }

    /**
     * Switch to SMTP driver.
     *
     * @param array<string, mixed> $config
     */
    public function useSmtp(array $config = []): void
    {
        $this->logger?->info("Switching to SMTP driver", [
            'current_driver'    => \get_class($this->driver),
            'has_custom_config' => !empty($config),
        ]);
        $this->setDriver('smtp', $config);
    }

    /**
     * Switch to the null (testing) driver.
     *
     * @param array<string, mixed> $config
     */
    public function useNull(array $config = []): void
    {
        $this->logger?->info("Switching to null driver for testing", [
            'current_driver'    => \get_class($this->driver),
            'has_custom_config' => !empty($config),
        ]);
        $this->setDriver('null', $config);
    }

    /**
     * Switch to sendmail driver.
     *
     * @param array<string, mixed> $config
     */
    public function useSendmail(array $config = []): void
    {
        $this->logger?->info("Switching to sendmail driver", [
            'current_driver'    => \get_class($this->driver),
            'has_custom_config' => !empty($config),
        ]);
        $this->setDriver('sendmail', $config);
    }

    /**
     * Switch to Mailgun driver.
     *
     * @param array<string, mixed> $config
     */
    public function useMailgun(array $config = []): void
    {
        $this->logger?->info("Switching to Mailgun driver", [
            'current_driver'    => \get_class($this->driver),
            'has_custom_config' => !empty($config),
        ]);
        $this->setDriver('mailgun', $config);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function setFromHeader(Message $message): void
    {
        $config = $this->rawConfig;

        if (!array_key_exists('driver', $config)) {
            $this->logger?->error("Mail config missing 'driver' key");
            throw new RuntimeException("Mail driver is not configured");
        }

        /** @var string $driverName */
        $driverName = $config['driver'];

        if (!array_key_exists('drivers', $config) || !\is_array($config['drivers'])) {
            $this->logger?->error("Mail config missing 'drivers' key or it is not an array");
            throw new RuntimeException("Mail drivers configuration is invalid");
        }

        $drivers = $config['drivers'];

        if (!array_key_exists($driverName, $drivers) || !\is_array($drivers[$driverName])) {
            $this->logger?->error("Driver config for '{$driverName}' is missing or not an array");
            throw new RuntimeException("Driver config for '{$driverName}' must be an array");
        }

        $driverConfig = $drivers[$driverName];

        if (!array_key_exists('from', $driverConfig) || !\is_array($driverConfig['from'])) {
            $this->logger?->error("Driver config for '{$driverName}' missing 'from' key or it is not an array");
            throw new RuntimeException("Driver config for '{$driverName}' must have a 'from' array");
        }

        $from = $driverConfig['from'];

        if (empty($from['address']) || !filter_var($from['address'], FILTER_VALIDATE_EMAIL) || !\is_string($from['address'])) {
            $this->logger?->error("Invalid 'from.address' configured in mail driver '{$driverName}'");
            throw new RuntimeException("Invalid 'from.address' configured in mail driver '{$driverName}'. Please update your mail configuration.");
        }

        $fromAddress = trim($from['address']);
        $fromName    = isset($from['name']) && \is_string($from['name']) ? trim($from['name']) : '';
        $fromHeader  = $fromName !== '' ? sprintf('%s <%s>', $fromName, $fromAddress) : $fromAddress;

        $message->setFrom($fromHeader);

        $this->logger?->debug("From header set on message", [
            'from_address' => $fromAddress,
            'from_name'    => $fromName,
            'from_header'  => $fromHeader,
        ]);
    }

    private function sign(Message $m): Message
    {
        $config = $this->rawConfig;

        if (!isset($config['driver']) || !\is_string($config['driver'])) {
            $this->logger?->error("Mail config missing 'driver' key or it is not a string");
            throw new RuntimeException("Mail driver is not configured");
        }

        $driverName = $config['driver'];

        if (!isset($config['drivers']) || !\is_array($config['drivers'])) {
            $this->logger?->error("Mail config missing 'drivers' array");
            throw new RuntimeException("Mail drivers config missing");
        }

        if (!isset($config['drivers'][$driverName]) || !\is_array($config['drivers'][$driverName])) {
            $this->logger?->error("Driver config for '{$driverName}' is missing or not an array");
            throw new RuntimeException("Driver config for '{$driverName}' must be an array");
        }

        $driverConfig = $config['drivers'][$driverName];

        $dkimConfig = [
            'dkim_private_key' => safeString($driverConfig['dkim_private_key'] ?? null),
            'dkim_selector'    => safeString($driverConfig['dkim_selector'] ?? null),
            'dkim_domain'      => safeString($driverConfig['dkim_domain'] ?? null),
        ];

        if (!DkimSigner::shouldSign(\get_class($this->driver), $dkimConfig)) {
            $this->logger?->debug("DKIM signing skipped for local transport", [
                'driver' => \get_class($this->driver),
            ]);
            return $m;
        }

        $dkimSigner    = new DkimSigner($dkimConfig['dkim_private_key'], $dkimConfig['dkim_selector'], $dkimConfig['dkim_domain']);
        $dkimSignature = $dkimSigner->sign($m->getHeaders(), $m->getContent());

        $m->setDkimSignature($dkimSignature);

        $this->logger?->debug("DKIM signature generated and attached to message", [
            'signature_length' => strlen($dkimSignature),
            'domain'           => $dkimConfig['dkim_domain'],
            'selector'         => $dkimConfig['dkim_selector'],
        ]);

        return $m;
    }

    /**
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        return $this->rawConfig;
    }
}
