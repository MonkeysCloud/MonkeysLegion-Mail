# MonkeysLegion Mail

[![PHP Version](https://img.shields.io/badge/php-8.4%2B-blue.svg)](https://php.net)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

A powerful, feature-rich mail package for the MonkeysLegion PHP framework, providing robust email functionality with DKIM signing, queue support, rate limiting, and elegant template rendering.

## 📋 What's Inside

This comprehensive mail package includes everything you need for professional email handling:

### 🚀 **Getting Started**
- **Quick Installation**: Automated setup with scaffolding
- **Configuration**: Environment variables and driver setup
- **First Email**: Send your first email in minutes

### 📧 **Core Email Features**
- **Multiple Transports**: SMTP, Sendmail, Mailgun, and Null drivers
- **Direct Sending**: Immediate email delivery
- **Queue System**: Background email processing with Redis
- **Rate Limiting**: Prevent spam and control sending limits

### 🛡️ **Security & Authentication**
- **DKIM Signing**: Digital signatures for email authentication
- **SPF/DMARC Ready**: Compatible with modern email security
- **Raw Key Support**: Simplified DKIM key management

### 🎨 **Template System**
- **Mailable Classes**: Object-oriented email composition
- **ML View Engine**: Powerful template rendering
- **Email Components**: Reusable UI components
- **Dynamic Content**: Data binding and conditional rendering

### ⚡ **Advanced Features**
- **CLI Commands**: Command-line email management
- **Queue Management**: Job retry, failure handling
- **Logging**: Comprehensive PSR-3 compatible logging
- **Events**: Email lifecycle tracking

### 🔧 **Developer Tools**
- **Make Commands**: Generate mail classes instantly
- **Testing Tools**: Test email sending without queues
- **Debug Mode**: Detailed logging and error reporting

---

## 🚀 Quick Start

### Installation

```bash
# Install Monkeys Legion App
composer create-project --stability=dev \ monkeyscloud/monkeyslegion-skeleton my-app "dev-main"

# Publish configuration and scaffolding
php vendor/bin/ml mail:install
```

### Basic Configuration

Add these variables to your `.env` file:

```env
# Basic SMTP Configuration
MAIL_DRIVER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=your-email@gmail.com
MAIL_PASSWORD=your-app-password
MAIL_ENCRYPTION=tls

# OR Mailgun Configuration
MAILGUN_API_KEY=YOUR_MAILGUN_API_KEY
MAILGUN_DOMAIN=YOUR_MAILGUN_DOMAIN

# DKIM Configuration (Optional but Recommended)
MAIL_DKIM_PRIVATE_KEY=your-raw-private-key-without-headers
MAIL_DKIM_SELECTOR=default
MAIL_DKIM_DOMAIN=yourdomain.com

# Queue Configuration (Optional but required in queueing)
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
QUEUE_DEFAULT=emails

# A Global Configuration
MAIL_FROM_ADDRESS=your-email@gmail.com
MAIL_FROM_NAME="Your App Name"
```
### You can always check the mail.php config file to see all the available options you can set in your .env

### Test Your Setup

```bash
# Test email sending
php vendor/monkeyscloud/monkeyslegion-mail/bin/ml-mail.php mail:test your-email@example.com
```

---

## 📧 Sending Emails

### Direct Sending

```php
use MonkeysLegion\Mail\Mailer;

// Get mailer instance
$mailer = ML_CONTAINER->get(Mailer::class);

// Send immediately
$mailer->send(
    'user@example.com',
    'Welcome to Our App',
    '<h1>Welcome!</h1><p>Thanks for joining us.</p>',
    'text/html'
);
```

### Queue-Based Sending

```php
// Queue for background processing (required env redis vars)
$jobId = $mailer->queue(
    'user@example.com',
    'Welcome to Our App', 
    '<h1>Welcome!</h1><p>Thanks for joining us.</p>',
    'text/html'
);

echo "Email queued with job ID: $jobId";
```

### Using Mailable Classes

```php
// Generate a new mailable class
php vendor/bin/ml make:mail WelcomeMail

// Use the generated class
use App\Mail\WelcomeMail;

$mail = new WelcomeMail();
$mail->setTo('user@example.com')
     ->setViewData(['name' => 'John Doe'])
     ->send(); // or ->queue()
```

---

## 🎨 Mailable Classes

Mailable classes provide an elegant, object-oriented way to compose emails with templates, data binding, and fluent configuration.

### Creating a Mailable

```bash
# Generate a new mailable class
php vendor/bin/ml make:mail OrderConfirmation
```

### Example Mailable Class

```php
<?php

namespace App\Mail;

use MonkeysLegion\Mail\Mail\Mailable;

class OrderConfirmationMail extends Mailable
{
    public function __construct(
        private array $order,
        private array $customer
    ) {
        parent::__construct();
    }

    public function build(): self
    {
        return $this->view('emails.order-confirmation')
                    ->subject('Order Confirmation #' . $this->order['id'])
                    ->withData([
                        'order' => $this->order,
                        'customer' => $this->customer,
                        'total' => $this->order['total']
                    ])
                    ->attach('/path/to/invoice.pdf');
    }
}
```

### Using Mailable Classes

```php
// Create and send
$order = ['id' => 12345, 'total' => 99.99];
$customer = ['name' => 'John Doe', 'email' => 'john@example.com'];

$mail = new OrderConfirmationMail($order, $customer);

// Send immediately
$mail->setTo('john@example.com')->send();

// Or queue for background processing
$jobId = $mail->setTo('john@example.com')->queue();

// Configure dynamically
$mail->setTo('john@example.com')
     ->setSubject('Custom Subject')
     ->onQueue('high-priority')
     ->send();
```

### Mailable Features

#### Template Binding
```php
public function build(): self
{
    // emails.welcome => root/resources/views/emails/welcome.ml.php
    return $this->view('emails.welcome', [
        'user' => $this->user,
        'loginUrl' => 'https://app.com/login'
    ]);
}
```

#### Overridable Properties
Child classes can override these properties to set defaults:

```php
class OrderConfirmationMail extends Mailable
{
    // Queue configuration
    protected ?string $queue = 'orders';
    protected ?int $timeout = 120;
    protected ?int $maxTries = 5;
    
    // Content settings  
    protected string $contentType = 'text/html';
    
    // Runtime methods also available
    public function build(): self
    {
        return $this->view('emails.order')
                    ->setTimeout(180)           // Override timeout
                    ->setMaxTries(3)           // Override max tries
                    ->setContentType('text/html') // Override content type
                    ->addAttachment('/path/to/invoice.pdf');
    }
}
```

#### Available Configuration Methods

| Method | Description | Example |
|--------|-------------|---------|
| `setTimeout(int $timeout)` | Set job timeout in seconds | `->setTimeout(120)` |
| `setMaxTries(int $tries)` | Set maximum retry attempts | `->setMaxTries(5)` |
| `setContentType(string $type)` | Set content type | `->setContentType('text/plain')` |
| `addAttachment(string $path, ?string $name, ?string $mime)` | Add file attachment | `->addAttachment('/path/file.pdf', 'Invoice.pdf')` |
| `setAttachments(array $attachments)` | Set all attachments | `->setAttachments($fileArray)` |

#### Attachments
```php
public function build(): self
{
    return $this->view('emails.newsletter')
                ->addAttachment('/path/to/file.pdf', 'Newsletter.pdf')
                ->setAttachments([
                    ['path' => '/path/file1.pdf', 'name' => 'File1.pdf'],
                    ['path' => '/path/file2.pdf', 'name' => 'File2.pdf']
                ]);
}
```

#### Conditional Logic
```php
public function build(): self
{
    return $this->view('emails.notification')
                ->when($this->user->isPremium(), function($mail) {
                    $mail->addAttachment('/path/to/premium-guide.pdf');
                })
                ->unless($this->user->hasSeenWelcome(), function($mail) {
                    $mail->withData(['showWelcome' => true]);
                });
}
```

---

## 📊 Queue System

The queue system allows you to send emails in the background, improving application performance and providing retry capabilities.

### How It Works

1. **Queue Email**: Email is serialized and stored in Redis
2. **Worker Processing**: Background worker picks up jobs
3. **Retry Logic**: Failed jobs are automatically retried
4. **Failure Handling**: Permanently failed jobs are moved to failed queue

### Queue Benefits

- **Performance**: Non-blocking email sending
- **Reliability**: Automatic retries for failed sends
- **Scalability**: Multiple workers can process jobs
- **Monitoring**: Track job status and failures

### Example Queue Workflow

```php
// In your application
$jobId = $mailer->queue(
    'user@example.com',
    'Newsletter',
    $htmlContent,
    'text/html',
    [], // attachments
    'newsletters' // specific queue
);

// Start worker (separate process)
// php vendor/monkeyscloud/monkeyslegion-mail/bin/ml-mail.php mail:work newsletters
```

### Queue Monitoring

```bash
# Monitor queue status
php vendor/monkeyscloud/monkeyslegion-mail/bin/ml-mail.php mail:list

# Check for failed jobs
php vendor/monkeyscloud/monkeyslegion-mail/bin/ml-mail.php mail:failed

# Get queue statistics
redis-cli llen queue:emails          # Pending jobs
redis-cli llen queue:failed         # Failed jobs
```

---

## 🔧 CLI Commands

The mail package includes powerful CLI commands for testing, queue management, and maintenance.

### Email Testing

```bash
# Test email sending (bypasses queue)
php vendor/monkeyscloud/monkeyslegion-mail/bin/ml-mail.php mail:test user@example.com
```

### DKIM Key Generation

```bash
# Generate DKIM private and public key files in the specified directory
php vendor/bin/ml make:dkim-pkey <directory>
```
- This will create `dkim_private.key` and `dkim_public.key` in the given directory.
- Add the public key to your DNS as a TXT record for DKIM.

### Queue Management

```bash
# Start processing queued emails
php vendor/monkeyscloud/monkeyslegion-mail/bin/ml-mail.php mail:work

# Work on specific queue
php vendor/monkeyscloud/monkeyslegion-mail/bin/ml-mail.php mail:work high-priority

# List pending jobs
php vendor/monkeyscloud/monkeyslegion-mail/bin/ml-mail.php mail:list

# List failed jobs
php vendor/monkeyscloud/monkeyslegion-mail/bin/ml-mail.php mail:failed

# Retry specific failed job
php vendor/monkeyscloud/monkeyslegion-mail/bin/ml-mail.php mail:retry job_12345

# Retry all failed jobs  
php vendor/monkeyscloud/monkeyslegion-mail/bin/ml-mail.php mail:retry --all

# Clear pending jobs
php vendor/monkeyscloud/monkeyslegion-mail/bin/ml-mail.php mail:clear

# Delete all failed jobs
php vendor/monkeyscloud/monkeyslegion-mail/bin/ml-mail.php mail:flush

# Delete ALL jobs (pending + failed)
php vendor/monkeyscloud/monkeyslegion-mail/bin/ml-mail.php mail:purge
```

### Queue Worker Configuration

Configure worker behavior in `config/redis.php`:

```php
'queue' => [
    'worker' => [
        'sleep' => 3,           // Seconds between job checks
        'max_tries' => 3,       // Maximum retry attempts
        'memory' => 128,        // Memory limit (MB)
        'timeout' => 60,        // Job timeout (seconds)
    ],
],
```

---

## 🛡️ DKIM Email Signing

DKIM (DomainKeys Identified Mail) adds digital signatures to your emails, improving deliverability and preventing spoofing.

### Why DKIM Matters

- **Improved Deliverability**: Emails are more likely to reach the inbox
- **Authentication**: Proves emails actually came from your domain
- **Reputation Protection**: Prevents others from spoofing your domain
- **Compliance**: Required by many enterprise email systems

### Setting Up DKIM

#### 1. Generate DKIM Keys

```php
use MonkeysLegion\Mail\Security\DkimSigner;

// Generate a new key pair
$keys = DkimSigner::generateKeys(2048);

echo "Private Key:\n" . $keys['private'] . "\n\n";
echo "Public Key:\n" . $keys['public'];
```

#### 2. Configure Environment

```env
# Use raw private key without BEGIN/END headers
MAIL_DKIM_PRIVATE_KEY=MIICeAIBADANBgkqhkiG9w0BAQEFAASCAmIwggJeAgEAAoGBAMODcNBCB7...
MAIL_DKIM_SELECTOR=default
MAIL_DKIM_DOMAIN=yourdomain.com
```

#### 3. Add DNS Record

Create a TXT record in your DNS:

```
Name: default._domainkey.yourdomain.com
Value: v=DKIM1; k=rsa; p=MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQDA4...
```

#### 4. Verify DKIM

```bash
# Test DKIM signing
php vendor/monkeyscloud/monkeyslegion-mail/bin/ml-mail.php mail:test test@gmail.com
```

### DKIM Features

- **Automatic Signing**: All emails are automatically signed when configured
- **Raw Key Support**: No need for PEM headers - just paste the key data
- **Transport Compatibility**: Works with SMTP, Mailgun, and other transports
- **Queue Preservation**: DKIM signatures are preserved through queue processing

---

## ⚙️ Configuration

### Driver Configuration

#### SMTP Driver
```php
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
```

#### Mailgun Driver
```php
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

    // DKIM signing as SMTP configuration
],
```

#### Null Driver
```php
'null' => [
    'from' => [
        'address' => $_ENV['MAIL_FROM_ADDRESS'] ?? 'noreply@yourdomain.com',
        'name' => $_ENV['MAIL_FROM_NAME'] ?? 'Your App'
    ]
]
```

#### Sendmail Driver
```php
'sendmail' => [
    'path' => $_ENV['MAIL_SENDMAIL_PATH'] ?? '/usr/sbin/sendmail',
    'from' => [
        'address' => $_ENV['MAIL_FROM_ADDRESS'] ?? 'noreply@yourdomain.com',
        'name' => $_ENV['MAIL_FROM_NAME'] ?? 'Your App'
    ],
    // DKIM signing as SMTP configuration
],
```

### Rate Limiting

Configure rate limiting in `config/rate_limiter.php`:

```php
return [
    'key' => 'mail',           // Unique identifier
    'limit' => 100,            // Max emails per window
    'seconds' => 60,           // Time window (seconds)
    'storage_path' => '/mail', // Storage location
];
```

---

## 🎯 Rate Limiting

Prevent spam and control email sending rates with built-in rate limiting.

### Configuration

Rate limiting is configured in the `config/rate_limiter.php` file:

```php
return [
    'key' => 'mail',           // Unique identifier
    'limit' => 100,            // Max emails per window
    'seconds' => 60,           // Time window (seconds)
    'storage_path' => '/mail', // Storage location
];
```

### Available Methods

```php
use MonkeysLegion\Mail\RateLimiter\RateLimiter;

// Create rate limiter with config values
$rateLimiter = new RateLimiter('user_123', 100, 3600);

// Check remaining quota
$remaining = $rateLimiter->remaining(); // e.g., 85

// Get reset time
$resetTime = $rateLimiter->resetTime(); // seconds until reset

// Get configuration
$config = $rateLimiter->getConfig();

// Reset limit (admin function)
$rateLimiter->reset();

// Clean old records (scheduled task)
$stats = RateLimiter::cleanupAll('/tmp');
```

### Scheduled Cleanup

Add to your cron jobs:

```bash
# Clean up old rate limit records every hour
0 * * * * php /path/to/cleanup-rate-limits.php
```

```php
<?php
// cleanup-rate-limits.php
use MonkeysLegion\Mail\RateLimiter\RateLimiter;

$results = RateLimiter::cleanupAll('/tmp');
echo "Cleaned up " . $results['cleaned'] . " files\n";
```

---

## 🎨 Template System

Create beautiful, reusable email templates with the ML View engine.

For complete template documentation, see: [MonkeysLegion Template Engine](https://monkeyslegion.com/docs/packages/template)

### Email Components

The package includes pre-built email components

## 📝 Logging

Comprehensive logging helps you monitor email delivery and debug issues. Logging behavior is controlled by your application mode in the `.env` file.

### Configuration

Logging is automatically configured based on your application environment:

```env
# Application Mode (controls logging behavior)
APP_ENV=production  # production, development, testing

# Debug Mode (enables detailed SMTP/DKIM logging)
MAIL_DEBUG=false    # Set to true for debugging
```

### Log Levels by Environment

#### Production Mode (`APP_ENV=prod`)
- **INFO**: Successful operations, performance metrics
- **WARNING**: Rate limits, retry attempts
- **ERROR**: Failed operations, configuration issues
- **CRITICAL**: System failures requiring immediate attention

#### Development Mode (`APP_ENV=dev`)
- **DEBUG**: Detailed SMTP communication, DKIM signing process
- **INFO**: All successful operations with timing data
- **WARNING**: Non-critical issues and suggestions
- **ERROR**: Failed operations with full stack traces

#### Testing Mode (`APP_ENV=test`)
- **ERROR**: Only failures and critical issues
- **INFO**: Test-specific events and assertions

### Log Examples

```php
// Successful send (Production)
[Log TimeStamp] app.INFO Email sent successfully {
    "to": "user@example.com",
    "subject": "Welcome",
    "duration_ms": 1250,
    "driver": "SmtpTransport"
}

// DKIM signing (Development)
[Log TimeStamp] app.DEBUG DKIM signature generated {
    "domain": "yourdomain.com", 
    "selector": "default",
    "signature_length": 344,
    "headers_signed": ["From", "To", "Subject", "Date", "Message-ID"]
}

// Queue processing (All modes)
[Log TimeStamp] app.INFO Job processed successfully {
    "job_id": "job_64f8a2b1c3d4e",
    "duration_ms": 890,
    "memory_usage_mb": 15.2,
    "queue": "emails"
}

// Rate limiting (Production)
[Log TimeStamp] app.WARNING Rate limit exceeded {
    "key": "user_123",
    "limit": 100,
    "window": 3600,
    "remaining_reset_time": 1845
}

// SMTP Debug (Development only)
[Log TimeStamp] app.DEBUG SMTP command sent {
    "command": "MAIL FROM:<sender@example.com>",
    "response_code": 250,
    "response": "2.1.0 Ok"
}
```

### Log File Locations

Logs are stored in different locations based on your environment:

```bash
var/log/app.log
```

### Custom Logging

```php
use MonkeysLegion\Mail\Logger\Logger;

$logger = ML_CONTAINER->get(Logger::class);

// Log custom events
$logger->log("Custom email campaign started", [
    'campaign_id' => 'summer2024',
    'recipient_count' => 1000,
    'estimated_duration' => '5 minutes'
]);
```

---

## 🚀 Advanced Usage

### Multiple Drivers

```php
// Switch drivers at runtime
$mailer->useSmtp(['host' => 'smtp.mailgun.org']);
$mailer->send($to, $subject, $content);

$mailer->useSendmail();
$mailer->send($to, $subject, $content);

$mailer->useNull();
$mailer->send($to, $subject, $content);
```

### Bulk Email Processing

```php
// Queue multiple emails efficiently
$recipients = ['user1@example.com', 'user2@example.com', 'user3@example.com'];

foreach ($recipients as $recipient) {
    $mail = new NewsletterMail($content);
    $mail->setTo($recipient)
         ->onQueue('newsletters')
         ->queue();
}

// Process with dedicated worker
// php bin/ml-mail.php mail:work newsletters
```

---

## 🤝 Contributing

We welcome contributions! Please see our [Contributing Guide](CONTRIBUTING.md) for details.

### Development Setup

```bash
# Clone the repository
git clone https://github.com/MonkeysCloud/MonkeysLegion-Mail.git

# Install dependencies
composer install
```

---

## 📄 License

This package is open-sourced software licensed under the [MIT license](LICENSE).

---

## 🆘 Support

- **Documentation**: [MonkeysLegion Mail Package](https://monkeyslegion.com/docs/packages/mail)
- **Issues**: [GitHub Issues](https://github.com/MonkeysCloud/MonkeysLegion-Mail/issues)
- **Discussions**: [GitHub Discussions](https://github.com/MonkeysCloud/MonkeysLegion-Mail/discussions)
- **Email**: to add later ...

---

**Made with ❤️ by the MonkeysLegion Team**

