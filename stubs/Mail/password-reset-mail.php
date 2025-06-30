<?php

declare(strict_types=1);

namespace App\Mail;

use MonkeysLegion\Mail\Mail\Mailable;

/**
 * Password Reset Mail Class
 * 
 * Sends password reset instructions to users.
 */
class PasswordResetMail extends Mailable
{
    /**
     * Mail-specific timeout override (optional)
     */
    protected ?int $timeout = 30;

    /**
     * Max retry attempts override (optional)
     */
    protected ?int $maxTries = 3;

    /**
     * Create a new password reset mail instance.
     * 
     * @param string $userName The user's name
     * @param string $userEmail The user's email address  
     * @param string $resetUrl The password reset URL
     * @param int $resetExpiry Reset link expiry in minutes
     */
    public function __construct(
        private string $userName,
        private string $userEmail,
        private string $resetUrl,
        private int $resetExpiry = 60
    ) {
        parent::__construct();
    }

    /**
     * Build the password reset mail message.
     * 
     * @return self
     */
    public function build(): self
    {
        return $this
            ->view('emails.password-reset')
            ->to($this->userEmail)
            ->subject('Reset Your Password')
            ->contentType('text/html')
            ->onQueue('security')
            ->withData([
                'userName' => $this->userName,
                'appName' => 'Your App Name',
                'resetUrl' => $this->resetUrl,
                'resetExpiry' => $this->resetExpiry,
                'requestTime' => date('Y-m-d H:i:s'),
                'ipAddress' => $_SERVER['REMOTE_ADDR'] ?? 'Unknown',
                'userAgent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
                'supportContact' => 'security@yourapp.com'
            ]);
    }

    /**
     * Runtime configuration examples:
     * 
     * $mail = new PasswordResetMail('John', 'john@example.com', 'https://reset-url', 30);
     * 
     * // Runtime setters
     * $mail->setTo('different@example.com')
     *      ->setSubject('Urgent: Reset Your Password')
     *      ->setQueue('high_priority');
     *
     * // Conditional configuration
     * $mail->when($isAdmin, function($mail) {
     *     $mail->setQueue('admin_security');
     * });
     */
}
