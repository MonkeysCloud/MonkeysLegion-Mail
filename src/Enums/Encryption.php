<?php

declare(strict_types=1);

namespace MonkeysLegion\Mail\Enums;

enum Encryption: string
{
    case SSL = 'ssl';
    case TLS = 'tls';
    case NONE = 'none';
}
