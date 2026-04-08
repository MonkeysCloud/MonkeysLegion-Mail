# 🐒 MonkeysLegion Mail (v2)

A premium, high-performance mail engine for the **MonkeysLegion PHP framework**. Features PSR-14 events, DKIM signing, rate limiting, and a beautiful fluent API. Our codebase is rigorously tested with **288 unit and integration tests** ensuring **92.28% code coverage**.

[![PHP Version](https://img.shields.io/badge/php-8.4%2B-blue.svg)](https://php.net)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)
[![Tests](https://img.shields.io/badge/tests-288%20passed-brightgreen.svg)]()
[![Coverage](https://img.shields.io/badge/coverage-92.28%25-brightgreen.svg)]()

---

## 🚀 Quick Start: From Zero to "Sent"

### 1. Installation

Install the package via composer:

```bash
composer require monkeyscloud/monkeyslegion-mail:^2.0
```

Initialize the mail configuration and scaffold resources:

```bash
php ml mail:install
```

### 2. Configuration

Configure your sending credentials in your `.env` file. By default, the package uses the **Null Driver** for safe development.

```env
# Choose your driver (monkeys_mail, smtp, mailgun, sendmail, null)
MAIL_DRIVER=smtp

# SMTP Settings
MAIL_HOST=smtp.mailtrap.io
MAIL_PORT=587
MAIL_ENCRYPTION=tls
MAIL_USERNAME=your_username
MAIL_PASSWORD=your_password

# Global Identity
MAIL_FROM_ADDRESS=noreply@yourdomain.com
MAIL_FROM_NAME="Monkeys Legion App"
```

### 3. Manual Construction (DI)

If you are not using the full MonkeysLegion framework auto-wiring, here is how to manually construct the `Mailer` and its dependencies:

```php
use MonkeysLegion\Mail\Mailer;
use MonkeysLegion\Mail\Transport\SmtpTransport;
use MonkeysLegion\Mail\RateLimiter\RateLimiter;

$transport = new SmtpTransport([
    'host' => 'smtp.example.com',
    'port' => 587,
    'username' => 'user',
    'password' => 'secret'
]);

$rateLimiter = new RateLimiter('my_mail_app', 100, 60);

$mailer = new Mailer(
    $transport,
    $rateLimiter,
    $queueDispatcher, // MonkyesLegion-Queue (optional)
    $logger,          // PSR-3 or MonkeysLogger (optional)
    [],               // Raw configuration array for run time driver selection
    $eventDispatcher  // PSR-14 global dispatcher (optional)
);
```

### 4. Basic Sending (Direct)

Get the `Mailer` instance from the container and send an email immediately:

```php
use MonkeysLegion\Mail\Mailer;

// Resolve the mailer
$mailer = $container->get(Mailer::class);

$mailer->send(
    'recipient@example.com',
    'Hello from v2!',
    '<h1>Success!</h1><p>The MonkeysLegion mail engine is operational.</p>',
    'text/html'
);
```

### 5. Advanced: Using Mailables

Generate a new Mailable class:

```bash
php ml make:mail WelcomeMail
```

Configure your logic and templates in `src/Mail/WelcomeMail.php`:

```php
public function build(): self
{
    return $this->view('emails.welcome')
                ->subject('Welcome!')
                ->withData(['name' => $this->user->name]);
}
```

Then send or queue it fluently:

```php
(new WelcomeMail($user))
    ->setTo('user@example.com')
    ->send(); // or ->queue() for background processing
```

---

## 🔔 Events & Hooks (New in v2)

Listen to successful sends or failures globally via PSR-14, or locally on the instances.

```php
$mailer->onSent(function ($event) {
    echo "Message " . $event->getMessageId() . " sent successfully!";
});

$mailer->onFailed(function ($event) {
    Log::error("Failed to send: " . $event->getException()->getMessage());
});
```

See the **[Events Documentation](docs/events.md)** for details on the PSR-14 implementation.

---

## 📖 Complete Documentation

Explore each feature in detail:

- **[🚌 Transports & Drivers](docs/transports.md)**: SMTP, MonkeysMail API, Mailgun, and more.
- **[🎨 Mailable Classes](docs/mailables.md)**: Object-oriented composition and data binding.
- **[📊 Queue System](docs/queues.md)**: High-performance background processing.
- **[🛡️ DKIM Signing](docs/dkim.md)**: Ensure deliverability with cryptographic signatures.
- **[🚦 Security & Logging](docs/security.md)**: Rate limiting and monitoring.
- **[🔧 CLI Commands](docs/cli.md)**: Scaffolding and testing tools.

---

## ✨ Why MonkeysLegion Mail?

- **PSR-14 Native**: Full event-driven architecture.
- **DKIM Built-in**: Modern security standards out-of-the-box.
- **Rate Limited**: Protect your reputation and costs automatically.
- **Mobile-First Templates**: Optimized for standard email clients.
- **High Performance**: Zero-overhead queueing and batching.

---

## 🤝 Contributing & Security

We welcome contributions! Please see our **[CONTRIBUTING.md](CONTRIBUTING.md)** for guidelines on how to get started.

If you discover a security vulnerability, please review our **[SECURITY.md](SECURITY.md)** for our reporting process.

---

## ⚖️ License

Distributed under the MIT License. &copy; 2026 MonkeysCloud Team
