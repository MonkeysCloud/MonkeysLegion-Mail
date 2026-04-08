# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.0] - 2026-04-08

### Added

- **PSR-14 Event System Implementation**:
  - Integrated standard `Psr\EventDispatcher\EventDispatcherInterface` into the `Mailer`.
  - Added `MonkeysLegion\Mail\Event\MessageSent` event triggered on success.
  - Added `MonkeysLegion\Mail\Event\MessageFailed` event triggered on failure.
  - Both events implement `Psr\EventDispatcher\StoppableEventInterface`.
- **Granular Event Listening API**:
  - Added `Mailer::onSent(callable $listener)` for registering per-instance success hooks.
  - Added `Mailer::onFailed(callable $listener)` for registering per-instance failure hooks.
- **Context-Aware Events**:
  - Events now include a `mailableClass` property (`getMailableClass()`) when triggered from a `Mailable`.
  - Added `Mailer::setMailableContext(?string $mailableClass)` for internal tracking.
- **Improved DI Support**:
  - `Mailer` constructor now optionally accepts a `Psr\EventDispatcher\EventDispatcherInterface`.

### Changed

- **Modernized Architecture**:
  - Transitioned from a notification-only model to a full standard event-driven architecture.
  - Updated `Mailable::send()` and `Mailable::queue()` to automatically propagate their class name to triggered events for better observability.
- **Internal Refactoring**:
  - `Mailer::send()` and `Mailer::queue()` now fire both global PSR-14 events (via the dispatcher) and isolated per-instance listeners.
- **Dependency Updates**:
  - Added explicit requirement for `psr/event-dispatcher: ^1.0`.

### Fixed

- Improved failure reporting in `SmtpTransport` with more descriptive exceptions and better integration with `MessageFailed` logging.
- Fixed mock-related PHPUnit notices by adhering to modern strict mocking standards.

## [1.1.0] - 2025-12-20

### Added

- DKIM signing support.
- Rate limiting for outgoing messages.

## [1.0.0] - 2024-06-01

### Added

- Initial release with SMTP, Sendmail, and Mailgun support.
- Basic Mailable class structure.
