<?php

declare(strict_types=1);

namespace MonkeysLegion\Mail\Transport;

use MonkeysLegion\Mail\Message;
use MonkeysLegion\Mail\TransportInterface;


class NullTransport implements TransportInterface
{
    public function send(Message $m): void {}
}
