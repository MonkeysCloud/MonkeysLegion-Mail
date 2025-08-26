<?php

declare(strict_types=1);

namespace MonkeysLegion\Mail\Enums;

final class MailDefaults
{
    // Mail-related defaults
    public const TIMEOUT = 30;
    public const CONNECT_TIMEOUT = 10;
    public const MAIL_DRIVER = 'null';

    // SMTP defaults
    public const SMTP_HOST = 'smtp.mailtrap.io';
    public const SMTP_PORT = 587;
    public const SMTP_ENCRYPTION = 'tls';
    public const SMTP_USERNAME = '';
    public const SMTP_PASSWORD = '';

    // Mail sender defaults
    public const MAIL_FROM_ADDRESS = 'noreply@yourapp.com';
    public const MAIL_FROM_NAME = 'My App';

    // DKIM defaults
    public const DKIM_PRIVATE_KEY = '';
    public const DKIM_SELECTOR = 'default';
    public const DKIM_DOMAIN = '';

    // Mailgun defaults
    public const MAILGUN_REGION = 'us';
    public const MAILGUN_TRACK_CLICKS = true;
    public const MAILGUN_TRACK_OPENS = true;

    // Sendmail defaults
    public const SENDMAIL_PATH = '/usr/sbin/sendmail';

    // Redis connection defaults
    public const REDIS_HOST = '127.0.0.1';
    public const REDIS_PORT = 6379;
    public const REDIS_PASSWORD = '';
    public const REDIS_DB = 0;
    public const REDIS_TIMEOUT = 30;

    // Queue defaults
    public const QUEUE_CONNECTION = 'default';
    public const QUEUE_NAME = 'emails';
    public const QUEUE_PREFIX = 'queue:';
    public const QUEUE_FAILED_KEY = 'queue:failed';

    // Worker defaults
    public const QUEUE_WORKER_SLEEP = 3;
    public const QUEUE_WORKER_MAX_TRIES = 3;
    public const QUEUE_WORKER_MEMORY = 128; // MB
    public const QUEUE_WORKER_TIMEOUT = 60; // seconds

    // Rate limiter defaults
    public const RATE_LIMITER_KEY = 'mail';
    public const RATE_LIMITER_LIMIT = 100;
    public const RATE_LIMITER_SECONDS = 60;
    public const RATE_LIMITER_STORAGE_PATH = '/mail';
}
