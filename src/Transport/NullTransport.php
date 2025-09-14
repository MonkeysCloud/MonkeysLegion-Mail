<?php

declare(strict_types=1);

namespace MonkeysLegion\Mail\Transport;

use MonkeysLegion\Core\Contracts\FrameworkLoggerInterface;
use MonkeysLegion\Mail\Enums\MailDriverName;
use MonkeysLegion\Mail\Message;
use MonkeysLegion\Mail\TransportInterface;

/**
 * Null transport for testing purposes
 * Does not actually send emails, just logs them
 */
final class NullTransport implements TransportInterface
{
    private string $fromAddress;
    private string $fromName;

    /**
     * @param array<string, mixed> $config
     * @param FrameworkLoggerInterface|null $logger
     */
    public function __construct(
        private array $config,
        private ?FrameworkLoggerInterface $logger
    ) {
        $this->validateAndSetConfig();
    }

    public function send(Message $message): void
    {
        $to = null;
        $subject = null;

        try {
            $to = $message->getTo();
            $subject = $message->getSubject();

            $this->validateEmail($to, $subject);

            $logData = [
                'to' => $to,
                'subject' => $subject,
                'from_address' => $this->fromAddress,
                'from_name' => $this->fromName,
                'content_type' => $message->getContentType(),
                'content' => substr($message->getContent(), 0, 100) . '...',
                'timestamp' => date('Y-m-d H:i:s'),
            ];

            $this->logger?->smartLog("NullTransport: Email to {$to} with subject '{$subject}'", $logData);
        } catch (\InvalidArgumentException $e) {
            $this->logger?->error("NullTransport send failed due to invalid argument", [
                'to' => $to,
                'subject' => $subject,
                'exception' => $e,
                'error_message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    public function getName(): string
    {
        return MailDriverName::NULL->value;
    }

    private function validateEmail(string $to, string $subject): bool
    {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException("Invalid email address: $to");
        }

        if (empty($subject)) {
            throw new \InvalidArgumentException("Email subject cannot be empty");
        }

        return true;
    }

    private function validateAndSetConfig(): void
    {
        $from = $this->config['from'] ?? [];

        if (
            !is_array($from) ||
            empty($from['address']) ||
            !filter_var($from['address'], FILTER_VALIDATE_EMAIL)
        ) {
            throw new \InvalidArgumentException("Invalid or missing 'from.address' in config.");
        }

        $this->fromAddress = safeString($from['address']);
        $this->fromName = safeString($from['name'] ?? 'No Name');

        $this->logger?->info('NullTransport config validated and applied', [
            'from_address' => $this->fromAddress,
            'from_name' => $this->fromName,
        ]);
    }
}
