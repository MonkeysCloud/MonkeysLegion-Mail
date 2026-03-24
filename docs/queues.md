# 📊 Queue System

The MonkeysLegion Mail package enables seamless background email processing by integrating with the central **MonkeysLegion-Queue** system.

---

## 🚀 How It Works

1.  **Queue the mail**: When you call `$mailer->queue()` or `$mailable->queue()`, your email is transformed into a `SendMailJob`.
2.  **Dispatch to standard dispatcher**: The job is dispatched via the framework's `QueueDispatcherInterface`.
3.  **Process by central workers**: Background workers managed by `MonkeysLegion-Queue` pick up these jobs and execute them.

---

## 📨 Sending Emails to a Queue

### Via Mailer Instance

```php
use MonkeysLegion\Mail\Mailer;

/** @var Mailer $mailer */
$mailer = ML_CONTAINER->get(Mailer::class);

// Queue for background processing
$jobId = $mailer->queue(
    'user@example.com',
    'Newsletter',
    '<h1>Weekly News</h1>',
    'text/html',
    [], // attachments
    'emails' // specific queue name (optional)
);
```

### Via Mailable Class

```php
use App\Mail\WelcomeMail;

$mail = new WelcomeMail($userData);
$mail->setTo('user@example.com')
     ->onQueue('high-priority') // optionally target a queue
     ->queue();
```

---

## ⚙️ Worker Management

Since the package delegates all queue logic to the framework's central queue system, use the standard `queue` CLI commands:

### Start a Worker
To process emails, run a worker dedicated to the `emails` queue (or `default`):

```bash
php ml queue:work emails
```

### Manage Failures
If an API or SMTP server is down, jobs will move to the failed queue and can be retried:

```bash
# List failed email jobs
php ml queue:failed

# Retry all failed jobs
php ml queue:retry --all
```

---

## 🛠️ Performance & Scalability

The queue system allows you to:
-   **Improve HTTP response times**: Users don't wait for your SMTP server during a request.
-   **Scale horizontally**: Run 10+ workers as separate processes to process massive email campaigns.
-   **Automatic Retries**: Failed attempts due to network issues are retried automatically.
-   **Rate Limit Control**: Adjust worker numbers to stay within your provider's rate limits.

### Production Setup (Supervisor)
Use a process manager like **Supervisor** to ensure at least 2 workers are always running in production:

```ini
[program:mail-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/your/app/ml queue:work emails
autostart=true
autorestart=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/log/mail-worker.log
```
