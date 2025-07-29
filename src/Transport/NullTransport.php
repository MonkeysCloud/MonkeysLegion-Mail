<?php

declare(strict_types=1);

namespace MonkeysLegion\Mail\Transport;

use MonkeysLegion\Core\Contracts\FrameworkLoggerInterface;
use MonkeysLegion\Mail\Message;
use MonkeysLegion\Mail\TransportInterface;

/**
 * Null transport for testing purposes
 * Does not actually send emails, just logs them
 */
final class NullTransport implements TransportInterface
{

    public function __construct(private FrameworkLoggerInterface $logger) {}

    public function send(Message $message): void
    {
        try {
            $to = $message->getTo();
            $subject = $message->getSubject();
            // Validate email and subject
            $this->validateEmail($to, $subject);

            // Log the message details
            $logData = [
                'to' => $to,
                'subject' => $subject,
                'content_type' => $message->getContentType(),
                'content' => substr($message->getContent(), 0, 100) . '...', // Truncate for readability
                'timestamp' => date('Y-m-d H:i:s')
            ];
            $this->logger->smartLog("NullTransport: Email to {$to} with subject '{$subject}'", $logData);
        } catch (\InvalidArgumentException $e) {
            $this->logger->error("NullTransport send failed due to invalid argument", [
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
        return 'null';
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
}
