<?php

use MonkeysLegion\Mail\Enums\MailDefaults;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Mailer
    |--------------------------------------------------------------------------
    |
    | This option controls the default mailer that is used to send all email
    | messages unless another mailer is explicitly specified when sending
    | the message.
    |
    */

    'driver' => $_ENV['MAIL_DRIVER'] ?? MailDefaults::MAIL_DRIVER,

    'drivers' => [

        /*
        |--------------------------------------------------------------------------
        | SMTP Mailer
        |--------------------------------------------------------------------------
        |
        | This mailer is used to send email messages via SMTP.
        |
        */
        'smtp' => [
            'host' => $_ENV['MAIL_HOST'] ?? MailDefaults::SMTP_HOST,
            'port' => (int) ($_ENV['MAIL_PORT'] ?? MailDefaults::SMTP_PORT),
            'encryption' => $_ENV['MAIL_ENCRYPTION'] ?? MailDefaults::SMTP_ENCRYPTION,
            'username' => $_ENV['MAIL_USERNAME'] ?? MailDefaults::SMTP_USERNAME,
            'password' => $_ENV['MAIL_PASSWORD'] ?? MailDefaults::SMTP_PASSWORD,
            'timeout' => (int) ($_ENV['MAIL_TIMEOUT'] ?? MailDefaults::TIMEOUT),
            'from' => [
                'address' => $_ENV['MAIL_FROM_ADDRESS'] ?? MailDefaults::MAIL_FROM_ADDRESS,
                'name' => $_ENV['MAIL_FROM_NAME'] ?? MailDefaults::MAIL_FROM_NAME,
            ],
            'dkim_private_key' => $_ENV['MAIL_DKIM_PRIVATE_KEY'] ?? MailDefaults::DKIM_PRIVATE_KEY,
            'dkim_selector' => $_ENV['MAIL_DKIM_SELECTOR'] ?? MailDefaults::DKIM_SELECTOR,
            'dkim_domain' => $_ENV['MAIL_DKIM_DOMAIN'] ?? MailDefaults::DKIM_DOMAIN,
        ],

        /*
        |--------------------------------------------------------------------------
        | Mailgun Mailer
        |--------------------------------------------------------------------------
        |
        | This mailer is used to send email messages via Mailgun.
        |
        */
        'mailgun' => [
            'api_key' => $_ENV['MAILGUN_API_KEY'] ?? '',
            'domain' => $_ENV['MAILGUN_DOMAIN'] ?? '',

            'from' => [
                'address' => $_ENV['MAIL_FROM_ADDRESS'] ?? MailDefaults::MAIL_FROM_ADDRESS,
                'name' => $_ENV['MAIL_FROM_NAME'] ?? MailDefaults::MAIL_FROM_NAME,
            ],

            // Optional tracking options (used by addOptionalParameters)
            'tracking' => [
                'clicks' => filter_var($_ENV['MAILGUN_TRACK_CLICKS'] ?? MailDefaults::MAILGUN_TRACK_CLICKS, FILTER_VALIDATE_BOOLEAN),
                'opens'  => filter_var($_ENV['MAILGUN_TRACK_OPENS'] ?? MailDefaults::MAILGUN_TRACK_OPENS, FILTER_VALIDATE_BOOLEAN),
            ],

            // Optional delivery time in RFC2822 or ISO 8601 format
            'delivery_time' => $_ENV['MAILGUN_DELIVERY_TIME'] ?? null,

            // Optional array of tags (Mailgun supports up to 3 tags per message)
            'tags' => array_filter(array_map('trim', explode(',', $_ENV['MAILGUN_TAGS'] ?? ''))),

            // Optional custom variables to include with the message
            'variables' => [
                // Add dynamic key-value pairs if needed
            ],

            // Mailgun region (us or eu)
            'region' => $_ENV['MAILGUN_REGION'] ?? MailDefaults::MAILGUN_REGION,

            // Optional timeouts
            'timeout' => (int) ($_ENV['MAILGUN_TIMEOUT'] ?? MailDefaults::TIMEOUT),
            'connect_timeout' => (int) ($_ENV['MAILGUN_CONNECT_TIMEOUT'] ?? MailDefaults::CONNECT_TIMEOUT),

            // DKIM signing (if you manage DKIM manually)
            'dkim_private_key' => $_ENV['MAIL_DKIM_PRIVATE_KEY'] ?? MailDefaults::DKIM_PRIVATE_KEY,
            'dkim_selector'    => $_ENV['MAIL_DKIM_SELECTOR'] ?? MailDefaults::DKIM_SELECTOR,
            'dkim_domain'      => $_ENV['MAIL_DKIM_DOMAIN'] ?? MailDefaults::DKIM_DOMAIN,
        ],

        /*
        |--------------------------------------------------------------------------
        | Sendmail Mailer
        |--------------------------------------------------------------------------
        |
        | This mailer is used to send email messages via the sendmail program.
        | It uses the system's sendmail binary to send emails.
        |
        */
        'sendmail' => [
            'path' => $_ENV['MAIL_SENDMAIL_PATH'] ?? MailDefaults::SENDMAIL_PATH,
            'from' => [
                'address' => $_ENV['MAIL_FROM_ADDRESS'] ?? MailDefaults::MAIL_FROM_ADDRESS,
                'name' => $_ENV['MAIL_FROM_NAME'] ?? MailDefaults::MAIL_FROM_NAME,
            ],
            'dkim_private_key' => $_ENV['MAIL_DKIM_PRIVATE_KEY'] ?? MailDefaults::DKIM_PRIVATE_KEY,
            'dkim_selector' => $_ENV['MAIL_DKIM_SELECTOR'] ?? MailDefaults::DKIM_SELECTOR,
            'dkim_domain' => $_ENV['MAIL_DKIM_DOMAIN'] ?? MailDefaults::DKIM_DOMAIN,
        ],

        /*
        |--------------------------------------------------------------------------
        | Null Mailer
        |--------------------------------------------------------------------------
        |
        | This mailer is used to discard all email messages.
        | It is useful for testing and development purposes.
        |
        */
        'null' => [
            'from' => [
                'address' => $_ENV['MAIL_FROM_ADDRESS'] ?? MailDefaults::MAIL_FROM_ADDRESS,
                'name' => $_ENV['MAIL_FROM_NAME'] ?? MailDefaults::MAIL_FROM_NAME,
            ],
        ],

    ],

];
