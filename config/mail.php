<?php

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

    'driver' => $_ENV['MAIL_DRIVER'] ?? 'log',

    'drivers' => [

        /*
        |--------------------------------------------------------------------------
        | Log Mailer
        |--------------------------------------------------------------------------
        |
        | This mailer is used to log all email messages to a file.
        |
        */
        'null' => [],

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
            'timeout' => null,
            'from' => [
                'address' => $_ENV['MAIL_FROM_ADDRESS'] ?? 'noreply@yourapp.com',
                'name' => $_ENV['MAIL_FROM_NAME'] ?? 'My App'
            ]
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
                'name' => $_ENV['MAIL_FROM_NAME'] ?? 'Your App'
            ]
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
            ]
        ],
    ]
];
