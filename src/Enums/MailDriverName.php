<?php

declare(strict_types=1);

namespace MonkeysLegion\Mail\Enums;

enum MailDriverName: string
{
    case SMTP = 'smtp';
    case SENDMAIL = 'sendmail';
    case NULL = 'null';
    case MAILGUN = 'mailgun';
}
