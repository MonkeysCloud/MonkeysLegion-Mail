# 🔔 Event System (PSR-14)

MonkeysLegion Mail features a modern, standard-compliant event system based on **PSR-14**. This allows you to hook into the email sending process globally via a dispatcher or locally on specific `Mailer` instances.

---

## 🚀 Available Events

All events are located in the `MonkeysLegion\Mail\Event` namespace and implement `Psr\EventDispatcher\StoppableEventInterface`.

### 1. `MessageSent`

Triggered after an email has been successfully handed off to the transport driver.

| Method | Description |
|--------|-------------|
| `getMessageId(): string` | The unique ID assigned to the message. |
| `getJobData(): array` | Original data (`to`, `subject`, `content`, etc.). |
| `getDuration(): int` | Time spent sending in milliseconds. |
| `getMailableClass(): ?string` | The FQCN of the originating Mailable class. |

### 2. `MessageFailed`

Triggered when an error occurs during the sending process (e.g., transport failure, rate limiting).

| Method | Description |
|--------|-------------|
| `getException(): \Throwable` | The underlying error that caused the failure. |
| `getJobData(): array` | Original data that failed to send. |
| `getMailableClass(): ?string` | The FQCN of the originating Mailable class. |

---

## 🌍 Global Integration (PSR-14)

If your application provides a `Psr\EventDispatcher\EventDispatcherInterface` in the service container, the `Mailer` will automatically dispatch events to it.

```php
use MonkeysLegion\Mail\Event\MessageSent;

// In your EventServiceProvider or global listener setup:
$dispatcher->listen(MessageSent::class, function (MessageSent $event) {
    $mailable = $event->getMailableClass();
    
    if ($mailable === WelcomeMail::class) {
        // Specifically handle successful 'Welcome' emails
        $to = $event->getJobData()['to'];
        Log::info("Welcome email reached $to");
    }
});
```

---

## 🎯 Per-Instance Listeners

If you want to listen for events on a **specific** mailer instance without polluting the global event dispatcher, use the `onSent` and `onFailed` hooks.

```php
use MonkeysLegion\Mail\Mailer;
use MonkeysLegion\Mail\Event\MessageSent;
use MonkeysLegion\Mail\Event\MessageFailed;

$mailer = $container->get(Mailer::class);

$mailer->onSent(function (MessageSent $event) {
    // Only fires if THIS mailer instance succeeds
})->onFailed(function (MessageFailed $event) {
    // Only fires if THIS mailer instance fails
});

$mailer->send('user@example.com', 'Subject', 'Content');
```

---

## 🎨 Mailable Hooks

Mailables automatically propagate their class name to the events they trigger. This allows you to write clean, mailable-specific logic in your global listeners.

```php
// In a global listener
if ($event->getMailableClass() === InvoiceMail::class) {
    // Mark invoice as 'Email Sent' in database
}
```

---

## 📊 Monitoring & Performance

The `MessageSent` event provides the `getDuration()` method, which is excellent for monitoring the performance of different transport drivers (SMTP vs. API) across your application.

```php
$dispatcher->listen(MessageSent::class, function ($event) {
    if ($event->getDuration() > 2000) {
        Log::warning("Slow email delivery detected", [
            'duration' => $event->getDuration(),
            'to' => $event->getJobData()['to']
        ]);
    }
});
```
