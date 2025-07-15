<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Mailer
    |--------------------------------------------------------------------------
    |
    | This option controls the default mailer that is used to send all email
    | messages unless another mailer is explicitly specified when sending
    | the message.a
    |
    */

    'driver' => $_ENV['MAIL_DRIVER'] ?? 'null', // Changed from 'log' to 'null'

    'drivers' => [

        /*|--------------------------------------------------------------------------
        | SMTP Mailer
        |--------------------------------------------------------------------------
        |
        | This mailer is used to send email messages via SMTP.
        |
        */
        'smtp' => [
            'host' => $_ENV['MAIL_HOST'] ?? 'smtp.mailtrap.io',
            'port' => $_ENV['MAIL_PORT'] ?? 587,
            'encryption' => $_ENV['MAIL_ENCRYPTION'] ?? 'tls', // tls / ssl / null
            'username' => $_ENV['MAIL_USERNAME'] ?? '',
            'password' => $_ENV['MAIL_PASSWORD'] ?? '',
            'timeout' => $_ENV['MAIL_TIMEOUT'] ?? 30,
            'from' => [
                'address' => $_ENV['MAIL_FROM_ADDRESS'] ?? 'noreply@yourapp.com',
                'name' => $_ENV['MAIL_FROM_NAME'] ?? 'My App'
            ],
            'dkim_private_key' => $_ENV['MAIL_DKIM_PRIVATE_KEY'] ?? '',
            'dkim_selector' => $_ENV['MAIL_DKIM_SELECTOR'] ?? 'default',
            'dkim_domain' => $_ENV['MAIL_DKIM_DOMAIN'] ?? '',
        ],

        /*|--------------------------------------------------------------------------
        | Mailgun Mailer
        |--------------------------------------------------------------------------
        |
        | This mailer is used to send email messages via Mailgun.
        |*/
        'mailgun' => [
            'api_key' => $_ENV['MAILGUN_API_KEY'] ?? '',
            'domain' => $_ENV['MAILGUN_DOMAIN'] ?? '',

            'from' => [
                'address' => $_ENV['MAIL_FROM_ADDRESS'] ?? 'noreply@yourdomain.com',
                'name' => $_ENV['MAIL_FROM_NAME'] ?? 'Your App',
            ],

            // Optional tracking options (used by addOptionalParameters)
            'tracking' => [
                'clicks' => filter_var($_ENV['MAILGUN_TRACK_CLICKS'] ?? true, FILTER_VALIDATE_BOOLEAN),
                'opens'  => filter_var($_ENV['MAILGUN_TRACK_OPENS'] ?? true, FILTER_VALIDATE_BOOLEAN),
            ],

            // Optional delivery time in RFC2822 or ISO 8601 format
            'delivery_time' => $_ENV['MAILGUN_DELIVERY_TIME'] ?? null,

            // Optional array of tags (Mailgun supports up to 3 tags per message)
            'tags' => explode(',', $_ENV['MAILGUN_TAGS'] ?? ''), // e.g. "welcome,new-user"

            // Optional custom variables to include with the message
            'variables' => [
                // Dynamically assign or leave empty if not used
            ],

            // Mailgun region (us or eu)
            'region' => $_ENV['MAILGUN_REGION'] ?? 'us',

            // Optional timeouts
            'timeout' => ($_ENV['MAILGUN_TIMEOUT'] ?? 30),
            'connect_timeout' => ($_ENV['MAILGUN_CONNECT_TIMEOUT'] ?? 10),

            // DKIM signing (used if you generate DKIM manually)
            'dkim_private_key' => $_ENV['MAIL_DKIM_PRIVATE_KEY'] ?? '',
            'dkim_selector'    => $_ENV['MAIL_DKIM_SELECTOR'] ?? 'default',
            'dkim_domain'      => $_ENV['MAIL_DKIM_DOMAIN'] ?? '',
        ],



        /*|--------------------------------------------------------------------------
        | Sendmail Mailer
        |--------------------------------------------------------------------------
        | This mailer is used to send email messages via the sendmail program.
        | It uses the system's sendmail binary to send emails.
        |*/
        'sendmail' => [
            'path' => $_ENV['MAIL_SENDMAIL_PATH'] ?? '/usr/sbin/sendmail',
            'from' => [
                'address' => $_ENV['MAIL_FROM_ADDRESS'] ?? 'noreply@yourdomain.com',
                'name' => $_ENV['MAIL_FROM_NAME'] ?? 'Your App'
            ],
            'dkim_private_key' => $_ENV['MAIL_DKIM_PRIVATE_KEY'] ?? '',
            'dkim_selector' => $_ENV['MAIL_DKIM_SELECTOR'] ?? 'default',
            'dkim_domain' => $_ENV['MAIL_DKIM_DOMAIN'] ?? '',
        ],

        /*|--------------------------------------------------------------------------
        | Null Mailer
        |--------------------------------------------------------------------------
        | This mailer is used to discard all email messages.
        | It is useful for testing and development purposes.
        |*/
        'null' => [
            'from' => [
                'address' => $_ENV['MAIL_FROM_ADDRESS'] ?? 'noreply@yourdomain.com',
                'name' => $_ENV['MAIL_FROM_NAME'] ?? 'Your App'
            ]
        ]
    ]
];
