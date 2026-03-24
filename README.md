# MonkeysLegion Mail

[![PHP Version](https://img.shields.io/badge/php-8.4%2B-blue.svg)](https://php.net)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

A powerful, feature-rich mail package for the **MonkeysLegion PHP framework**, providing robust email functionality with DKIM signing, queue support, rate limiting, and elegant template rendering.

---

## 📋 Table of Contents

1.  **[🚀 Get Started](#🚀-get-started)**
2.  **[🚌 Transports & Drivers](docs/transports.md)**
3.  **[🎨 Mailable Classes](docs/mailables.md)**
4.  **[📊 Queue System](docs/queues.md)**
5.  **[🛡️ DKIM Signing](docs/dkim.md)**
6.  **[🚦 Security & Logging](docs/security.md)**
7.  **[🔧 CLI Commands](docs/cli.md)**

---

## 🚀 Get Started

### Installation

```bash
# Publish configuration and scaffolding
php ml mail:install
```

### Basic Configuration

Add these variables to your `.env` file (see [Transports Doc](docs/transports.md) for more options):

```env
# Basic Configuration
MAIL_DRIVER=monkeys_mail
MONKEYS_MAIL_API_KEY=YOUR_API_KEY
MONKEYS_MAIL_DOMAIN=monkeys.cloud

# A Global Configuration
MAIL_FROM_ADDRESS=noreply@yourdomain.com
MAIL_FROM_NAME="Your App Name"
```

### Test Your Setup

```bash
# Send a test email immediately
php ml mail:test your-email@example.com
```

---

## 📧 Usage Examples

### Direct Sending

```php
use MonkeysLegion\Mail\Mailer;

/** @var Mailer $mailer */
$mailer = ML_CONTAINER->get(Mailer::class);

$mailer->send(
    'user@example.com',
    'Welcome!',
    '<h1>Welcome to MonkeysCloud!</h1>',
    'text/html'
);
```

### Using Mailable classes

```php
use App\Mail\WelcomeMail;

(new WelcomeMail(['name' => 'John']))
    ->setTo('john@example.com')
    ->send(); // or ->queue()
```

---

## ✨ Features 

-   **Multiple Transports**: Support for [MonkeysMail API](docs/transports.md#🐒-monkeys-mail-monkeyslegion-api), SMTP, Mailgun, and Sendmail.
-   **High Performance**: Background email processing via [MonkeysLegion-Queue](docs/queues.md).
-   **Secure by Default**: Built-in [DKIM signing](docs/dkim.md) and [Rate Limiting](docs/security.md#🚦-rate-limiting).
-   **Template Engine**: Render beautiful emails using the [ML View Engine](docs/mailables.md#🎨-template-binding).
-   **Modern CLI**: Generate mail classes and manage your setup via the [CLI](docs/cli.md).

---

## ⚖️ License

Distributed under the MIT License. See `LICENSE` for more information.

&copy; 2024 MonkeysCloud Team
