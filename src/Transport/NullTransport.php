<?php

declare(strict_types=1);

namespace MonkeysLegion\Mail\Transport;

use MonkeysLegion\Logger\Contracts\MonkeysLoggerInterface;
use MonkeysLegion\Mail\Enums\MailDriverName;
use MonkeysLegion\Mail\Message;
use MonkeysLegion\Mail\TransportInterface;

/**
 * Null transport for testing purposes
 * Does not actually send emails, just falls into the void.
 */
class NullTransport implements TransportInterface
{
    /**
     * @param array<string, mixed> $config
     * @param MonkeysLoggerInterface|null $logger
     */
    public function __construct(
    ) {
    }

    public function send(Message $message): void
    {
    }

    public function getName(): string
    {
        return MailDriverName::NULL->value;
    }
}
