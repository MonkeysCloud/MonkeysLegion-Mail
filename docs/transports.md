# 🚌 Mail Transports

MonkeysLegion Mail supports multiple transports to handle different email delivery needs. All transports can be configured via your `.env` file or dynamically at runtime.

---

## 🐒 Monkeys Mail (MonkeysLegion API)

This is the recommended driver for the MonkeysLegion ecosystem. It sends emails via the high-performance MonkeysMail HTTP API.

### Configuration

Add these variables to your `.env`:

```env
MAIL_DRIVER=monkeys_mail
MONKEYS_MAIL_API_KEY=your_monkeys_api_key
MONKEYS_MAIL_DOMAIN=monkeys.cloud
MONKEYS_MAIL_TRACKING_OPENS=true
MONKEYS_MAIL_TRACKING_CLICKS=true
```

### Features
- **Native API Integration**: High speed and reliability.
- **X-API-Key Authentication**: Secure communication with the MonkeysMail cloud.
- **Open & Click Tracking**: Detailed engagement metrics out of the box.
- **No SMTP Overhead**: Bypasses SMTP handshake latency.

---

## ✉️ SMTP Mailer

The classic standard for email delivery. Use this for Gmail, Outlook, Mailtrap, and other SMTP-based providers.

### Configuration

```env
MAIL_DRIVER=smtp
MAIL_HOST=smtp.mailtrap.io
MAIL_PORT=587
MAIL_ENCRYPTION=tls
MAIL_USERNAME=your_username
MAIL_PASSWORD=your_password
```

---

## 🔫 Mailgun Mailer

Direct integration with the Mailgun API.

### Configuration

```env
MAIL_DRIVER=mailgun
MAILGUN_API_KEY=YOUR_MAILGUN_API_KEY
MAILGUN_DOMAIN=YOUR_MAILGUN_DOMAIN
MAILGUN_REGION=us  # us or eu
MAILGUN_TRACK_CLICKS=true
MAILGUN_TRACK_OPENS=true
```

---

## 📁 Sendmail Mailer

Uses the local `sendmail` binary on your server.

### Configuration

```env
MAIL_DRIVER=sendmail
MAIL_SENDMAIL_PATH="/usr/sbin/sendmail -bs"
```

---

## 🕳️ Null Mailer

Discards all messages. Perfect for local development or automated testing.

### Configuration

```env
MAIL_DRIVER=null
```

---

## 🔄 Switching Drivers at Runtime

You can easily switch drivers for a specific request:

```php
use MonkeysLegion\Mail\Mailer;

/** @var Mailer $mailer */
$mailer = ML_CONTAINER->get(Mailer::class);

// Switch to SMTP for a high-priority message
$mailer->useSmtp([
    'host' => 'high-priority.smtp.server.com',
    'username' => 'priority-user'
]);

$mailer->send(...);
```

### Available Helper Methods:
- `$mailer->useSmtp(array $config = [])`
- `$mailer->useMailgun(array $config = [])`
- `$mailer->useSendmail(array $config = [])`
- `$mailer->useNull(array $config = [])`
- `$mailer->setDriver(string $driverName, array $config = [])`
