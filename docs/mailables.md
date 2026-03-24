# 🎨 Mailable Classes

Mailable classes provide an elegant, object-oriented way to compose emails with templates, data binding, and fluent configuration.

---

## 📧 Basic Usage

### Creating a Mailable

```bash
# Generate a new mailable class
php ml make:mail WelcomeMail
```

### Example Mailable Class

```php
<?php

namespace App\Mail;

use MonkeysLegion\Mail\Mail\Mailable;

class WelcomeMail extends Mailable
{
    public function __construct(
        private array $userData
    ) {
        parent::__construct();
    }

    public function build(): self
    {
        return $this->view('emails.welcome')
                    ->subject('Welcome to MonkeysCloud, ' . $this->userData['name'] . '!')
                    ->withData([
                        'user' => $this->userData,
                        'loginUrl' => 'https://app.monkeys.cloud/login'
                    ])
                    ->attach('/path/to/user-guide.pdf');
    }
}
```

### Sending a Mailable

```php
$mail = new WelcomeMail(['name' => 'John Doe']);

// Send immediately
$mail->setTo('user@example.com')->send();

// Or queue for background processing
$mail->setTo('user@example.com')->queue();
```

---

## 🎨 Template Binding

### Using ML View Engine
Root directory for views is: `root/resources/views/`.
A template named `emails.welcome` should be at: `root/resources/views/emails/welcome.ml.php`.

```php
public function build(): self
{
    return $this->view('emails.welcome', [
        'name' => 'John',
        'referralCode' => 'ABCD123'
    ]);
}
```

---

## 🖇️ Attachments

Mailable supports strings (local paths), URLs, or structured arrays for attachments.

```php
public function build(): self
{
    return $this->view('emails.report')
                ->addAttachment('/local/storage/report.pdf', 'Your Report.pdf')
                ->setAttachments([
                    '/path/to/simple-file.pdf', 
                    'https://cloud.com/shared-doc.pdf',
                    ['path' => '/file.pdf', 'name' => 'CustomName.pdf', 'mime_type' => 'application/pdf']
                ]);
}
```

---

## ⚡ Fluid Configuration

| Method | Description |
|--------|-------------|
| `setTo(string $to)` | Set the recipient's email address |
| `setSubject(string $subject)` | Override the default subject |
| `setContentType(string $type)` | Set content type (text/html / text/plain) |
| `setTimeout(int $timeout)` | Set job timeout in seconds (for queues) |
| `setMaxTries(int $tries)` | Set maximum retry attempts (for queues) |
| `onQueue(string $queue)` | Choose a specific queue name |

---

## 🛡️ Conditional Configuration

Use `when` or `unless` to configure your email dynamically:

```php
public function build(): self
{
    return $this->view('emails.notification')
                ->when($this->user->isPremium(), function($mail) {
                    $mail->addAttachment('/path/to/premium-benefits.pdf');
                });
}
```
