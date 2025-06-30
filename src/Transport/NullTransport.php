<?php

declare(strict_types=1);

namespace MonkeysLegion\Mail\Transport;

use MonkeysLegion\Mail\Logger\Logger;
use MonkeysLegion\Mail\Message;
use MonkeysLegion\Mail\TransportInterface;

/**
 * Null transport for testing purposes
 * Does not actually send emails, just logs them
 */
final class NullTransport implements TransportInterface
{

    public function __construct(private Logger $logger) {}

    public function send(Message $message): void
    {
        $logData = [
            'to' => $message->getTo(),
            'subject' => $message->getSubject(),
            'content_type' => $message->getContentType(),
            'content' => substr($message->getContent(), 0, 100) . '...', // Truncate for readability
            'timestamp' => date('Y-m-d H:i:s')
        ];
        $this->logger->log("NullTransport: Email to {$message->getTo()} with subject '{$message->getSubject()}'", $logData);
    }
}
