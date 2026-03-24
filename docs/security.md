# 🛡️ Security & Logging

Enhance your mail delivery with built-in rate limiting and detailed logging for monitoring and debugging.

---

## 🚦 Rate Limiting

Prevent spam and control your sending rates by configuring the built-in rate limiter.

### Configuration
Configure your mail rate limit in `config/rate_limiter.php`:

```php
return [
    'key' => 'mail',           // Unique identifier for the rate limit
    'limit' => 100,            // Max emails allowed per window
    'seconds' => 60,           // Time window in seconds (e.g., 60 = 1 minute)
    'storage_path' => '/mail', // Storage location (typically within storage/rate_limit)
];
```

### Usage
The `Mailer` and `Mailable` classes automatically respect this rate limit when sending.
You can manually check or reset the limit:

```php
use MonkeysLegion\Mail\RateLimiter\RateLimiter;

$rateLimiter = new RateLimiter('custom_limit_key', 50, 3600);
if (!$rateLimiter->allow()) {
    throw new \RuntimeException("Rate limit exceeded!");
}
```

---

## 📝 Logging

Monitoring email delivery is critical for debugging issues and tracking successful sends.

### Log Levels by Environment
- **Production (`APP_ENV=production`)**: Logs success events, rate limits, and errors.
- **Development (`APP_ENV=development`)**: Detailed logs including SMTP handshake, DKIM signing, and full error traces.
- **Testing (`APP_ENV=test`)**: Only critical failures and test-specific events.

### Log Locations
Logs are stored in the application's central log file (typically `var/log/app.log`).

### Example Success Log
```json
[2026-03-24] app.INFO Email sent successfully {
    "to": "user@gmail.com",
    "subject": "Welcome",
    "duration_ms": 1250,
    "driver": "SmtpTransport"
}
```

### Example Fail Log
```json
[2026-03-24] app.ERROR Email sending failed {
    "to": "user@gmail.com",
    "subject": "Welcome",
    "error_message": "SMTP Connection Refused",
    "trace": "..."
}
```

---

## 🛡️ Best Security Practices

1.  **Don't Hardcode Secrets**: Always use `.env` for API keys, passwords, and DKIM private keys.
2.  **Enable DKIM**: It drastically reduces the chance of your emails being marked as spam.
3.  **Use a Dedicated Domain**: Separate your main domain from your "mail" domain (e.g., `mg.monkeys.cloud`).
4.  **Sandbox in Dev**: Always use the **Null Driver** (`MAIL_DRIVER=null`) or tools like **Mailtrap** in your local and staging environments.
5.  **Monitor Workers**: Ensure your background workers are monitored by **Supervisor** to prevent job pile-up if an API goes down.
