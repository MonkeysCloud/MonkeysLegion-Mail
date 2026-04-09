# Upgrading to v2.0.0

This guide documents the changes and steps required to upgrade from v1.x to v2.0.0 of the MonkeysLegion-Mail package.

## 🚀 Key Changes

v2.0.0 introduces a modern, PSR-14 compliant event system that supports both global dispatching and granular, per-instance hooks.

### Added Dependency

- **Requirement**: `psr/event-dispatcher: ^1.0` is now a required dependency.
- **Action**: Run `composer update` to pull in the new package.

### Context-Aware Events

The `MessageSent` and `MessageFailed` events now include the FQCN of the originating `Mailable` class. This enables better filtering for global listeners.

```php
$event->getMailableClass(); // Returns the mailable class name or null if sent directly.
```

## 🛠️ Implementation Steps

### 1. Global Event Dispatching (Optional)

If you use a PSR-14 event dispatcher, you can now inject it into the `Mailer`.

```php
use Psr\EventDispatcher\EventDispatcherInterface;
use MonkeysLegion\Mail\Mailer;

// In your service container config
$container->set(Mailer::class, function($c) {
    return new Mailer(
        $c->get(TransportInterface::class),
        $c->get(RateLimiter::class),
        $c->get(QueueDispatcherInterface::class),
        $c->get(MonkeysLoggerInterface::class),
        $c->get(EventDispatcherInterface::class) // NEW
    );
});
```

### 2. Per-Instance Listening

You can now register listeners directly on a `Mailer` instance. These listeners only fire for that specific mailer, which is ideal for one-off monitoring without polluting the global event dispatcher.

```php
$mailer->onSent(function (MessageSent $event) {
    // Custom logic on success
});

$mailer->onFailed(function (MessageFailed $event) {
    // Custom logic on failure
    $e = $event->getException();
});
```

### 3. Mailable Hooks (Breaking Change Warning)

The internal context-tracking mechanism has changed. If you have overridden `send()` or `queue()` in your custom `Mailable` classes, ensure you either:

1. Call `parent::send()` or `parent::queue()`.
2. Manually set the context on the mailer using `mailer->setMailableContext(static::class)` and ensure you clear it in a `finally` block with `setMailableContext(null)`.

Failure to correctly set the context will result in null `mailableClass` values in triggered events.

## 🧪 Testing Considerations

If you have mock-based tests for `Mailer` or `Mailable`, ensure they respect the new dependency on `psr/event-dispatcher` if your container is providing it. Additionally, PHPUnit 12+ may require the `#[AllowMockObjectsWithoutExpectations]` attribute for mocks created in `setUp` that are not configured in every test.
