<?php

declare(strict_types=1);

namespace MonkeysLegion\Mail\Transport;

use MonkeysLegion\Mail\Message;
use MonkeysLegion\Mail\TransportInterface;

/**
 * Null transport for testing purposes
 * Does not actually send emails, just logs them
 */
final class NullTransport implements TransportInterface
{
    private string $logPath;

    public function __construct()
    {
        $this->logPath = __DIR__ . '/../../logs/null_transport.log';
        $this->ensureLogDirectoryExists();
    }

    public function send(Message $message): void
    {
        // Just log the email instead of sending it
        error_log("NullTransport: Email to {$message->getTo()} with subject '{$message->getSubject()}'");

        // Write to log file for testing
        $logData = [
            'to' => $message->getTo(),
            'subject' => $message->getSubject(),
            'content_type' => $message->getContentType(),
            'content' => substr($message->getContent(), 0, 100) . '...', // Truncate for readability
            'timestamp' => date('Y-m-d H:i:s')
        ];

        $logEntry = json_encode($logData) . "\n";

        if (file_put_contents($this->logPath, $logEntry, FILE_APPEND | LOCK_EX) === false) {
            error_log("Failed to write to null transport log file: {$this->logPath}");
        }
    }

    /**
     * Ensure the logs directory exists
     */
    private function ensureLogDirectoryExists(): void
    {
        $logDir = dirname($this->logPath);
        if (!is_dir($logDir)) {
            if (!mkdir($logDir, 0755, true)) {
                error_log("Failed to create log directory: {$logDir}");
            }
        }
    }
}
