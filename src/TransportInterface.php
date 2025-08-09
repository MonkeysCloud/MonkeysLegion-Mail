<?php

declare(strict_types=1);

namespace MonkeysLegion\Mail;

interface TransportInterface
{
    /**
     * Send an email.
     *
     * @param Message $m The message to send.
     * @return void
     */
    public function send(Message $m): void;
}
