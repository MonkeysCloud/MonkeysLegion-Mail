<?php

declare(strict_types=1);

namespace MonkeysLegion\Mail;

interface TransportInterface
{
    /**
     * Send an email.
     *
     * @param Message $message.
     * @return void
     */
    public function send(Message $m): void;
}
