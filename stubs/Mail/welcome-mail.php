<?php

declare(strict_types=1);

namespace App\Mail;

use MonkeysLegion\Mail\Mail\Mailable;

/**
 * Welcome Mail Class
 * 
 * Sends a welcome email to new users.
 */
class WelcomeMail extends Mailable
{
    /**
     * Mail-specific timeout override (optional)
     */
    protected ?int $timeout = 60;

    /**
     * Max retry attempts override (optional)
     */
    protected ?int $maxTries = 5;

    /**
     * Create a new welcome mail instance.
     * 
     * @param string $userName The user's name
     * @param string $userEmail The user's email address
     * @param string|null $verificationUrl Optional email verification URL
     */
    public function __construct(
        private string $userName,
        private string $userEmail,
        private ?string $verificationUrl = null
    ) {
        parent::__construct();
    }

    /**
     * Build the welcome mail message.
     * 
     * @return self
     */
    public function build(): self
    {
        return $this
            ->view('emails.welcome')
            ->to($this->userEmail)
            ->subject("Welcome to Our Platform, {$this->userName}!")
            ->contentType('text/html')
            ->withData([
                'userName' => $this->userName,
                'appName' => 'Your App Name',
                'verificationRequired' => $this->verificationUrl !== null,
                'verificationUrl' => $this->verificationUrl,
                'verificationExpiry' => 24,
                'supportEmail' => 'support@yourapp.com'
            ]);
    }

    /**
     * Runtime configuration examples:
     * 
     * $mail = new WelcomeMail('John', 'john@example.com');
     * 
     * // Runtime setters
     * $mail->setTo('different@example.com')
     *      ->setSubject('Updated Welcome!')
     *      ->setView('emails.custom-welcome');
     *
     * // Bulk configuration
     * $mail->configure([
     *     'to' => 'bulk@example.com',
     *     'subject' => 'Bulk Welcome',
     *     'queue' => 'high_priority',
     *     'viewData' => ['customData' => 'value']
     * ]);
     *
     * // Conditional configuration
     * $mail->when($isVip, function($mail) {
     *     $mail->setQueue('vip');
     * });
     */
}
