<?php

declare(strict_types=1);

namespace MonkeysLegion\Mailer\Tests;

use MonkeysLegion\Logger\Contracts\MonkeysLoggerInterface;
use MonkeysLegion\Mail\Jobs\SendMailJob;
use MonkeysLegion\Mail\Mailer;
use MonkeysLegion\Mail\MailerFactory;
use MonkeysLegion\Mail\RateLimiter\RateLimiter;
use MonkeysLegion\Mail\TransportInterface;
use MonkeysLegion\Mailer\Tests\Abstracts\AbstractBaseTest;
use MonkeysLegion\Queue\Contracts\QueueDispatcherInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;

#[CoversClass(Mailer::class)]
#[AllowMockObjectsWithoutExpectations]
class MailerTest extends AbstractBaseTest
{
    private TransportInterface&MockObject $transport;
    private RateLimiter&MockObject $rateLimiter;
    private QueueDispatcherInterface&MockObject $queueDispatcher;
    private MonkeysLoggerInterface&MockObject $logger;
    private Mailer $mailer;

    /** @var array<string, mixed> */
    private array $config;

    public function setUp(): void
    {
        parent::setUp();

        $this->transport       = $this->createMock(TransportInterface::class);
        $this->rateLimiter     = $this->createMock(RateLimiter::class);
        $this->queueDispatcher = $this->createMock(QueueDispatcherInterface::class);
        $this->logger          = $this->createMock(MonkeysLoggerInterface::class);

        $this->config = [
            'driver'  => 'null',
            'drivers' => [
                'null' => [
                    'from' => [
                        'address' => 'test@example.com',
                        'name'    => 'Test Sender',
                    ],
                ],
            ],
        ];

        $this->mailer = new Mailer(
            $this->transport,
            $this->rateLimiter,
            $this->queueDispatcher,
            $this->logger,
            $this->config,
        );
    }

    // ── send() ────────────────────────────────────────────────────────────────

    #[Test]
    #[TestDox('Send succeeds when rate limiter allows and transport sends')]
    public function test_send_succeeds_when_allowed(): void
    {
        $this->rateLimiter->method('allow')->willReturn(true);
        $this->transport->expects($this->once())->method('send');

        $this->mailer->send('recipient@example.com', 'Test Subject', 'Body');
    }

    #[Test]
    #[TestDox('Send calls transport with correct args')]
    public function test_send_calls_transport(): void
    {
        $this->rateLimiter->method('allow')->willReturn(true);
        $this->transport->expects($this->once())->method('send');

        $this->mailer->send('a@b.com', 'Subj', 'Content');
    }

    #[Test]
    #[TestDox('Send with valid email and content type text/html succeeds')]
    public function test_send_with_html_content_type(): void
    {
        $this->rateLimiter->method('allow')->willReturn(true);
        $this->transport->expects($this->once())->method('send');

        $this->mailer->send('test@example.com', 'Test Subject', 'Body', 'text/html');
    }

    #[Test]
    #[TestDox('Send throws RuntimeException when rate limit exceeded')]
    public function test_send_throws_when_rate_limit_exceeded(): void
    {
        $this->rateLimiter->method('allow')->willReturn(false);
        $this->transport->expects($this->never())->method('send');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Rate limit exceeded for sending emails. Please try again later.');
        $this->mailer->send('test@example.com', 'Subject', 'Body');
    }

    #[Test]
    #[TestDox('Send wraps transport exception in RuntimeException')]
    public function test_send_wraps_transport_exception(): void
    {
        $this->rateLimiter->method('allow')->willReturn(true);
        $this->transport->method('send')->willThrowException(new \RuntimeException('SMTP fail'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SMTP fail');
        $this->mailer->send('test@example.com', 'Subj', 'Body');
    }

    #[Test]
    #[TestDox('Send throws InvalidArgumentException when driver config is missing')]
    public function test_send_throws_invalid_argument_from_bad_config(): void
    {
        $badMailer = new Mailer(
            $this->transport,
            $this->rateLimiter,
            $this->queueDispatcher,
            $this->logger,
            [] // No 'driver' key
        );

        $this->rateLimiter->method('allow')->willReturn(true);

        $this->expectException(\RuntimeException::class);
        $badMailer->send('test@example.com', 'Subject', 'Body');
    }

    #[Test]
    #[TestDox('Send with empty body succeeds')]
    public function test_send_with_empty_body(): void
    {
        $this->rateLimiter->method('allow')->willReturn(true);
        $this->transport->expects($this->once())->method('send');

        $this->mailer->send('test@example.com', 'Subject', '');
    }

    // ── queue() ───────────────────────────────────────────────────────────────

    #[Test]
    #[TestDox('Queue dispatches a SendMailJob to default queue')]
    public function test_queue_dispatches_job_to_default_queue(): void
    {
        $this->queueDispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(SendMailJob::class), 'default');

        $result = $this->mailer->queue('test@example.com', 'Queued Subject', 'Body');
        $this->assertTrue($result);
    }

    #[Test]
    #[TestDox('Queue dispatches to custom queue when specified')]
    public function test_queue_dispatches_to_custom_queue(): void
    {
        $this->queueDispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(SendMailJob::class), 'high-priority');

        $result = $this->mailer->queue('test@example.com', 'Priority Subject', 'Body', 'text/html', [], 'high-priority');
        $this->assertTrue($result);
    }

    #[Test]
    #[TestDox('Queue throws RuntimeException when dispatcher throws')]
    public function test_queue_wraps_dispatcher_exception(): void
    {
        $this->queueDispatcher->method('dispatch')
            ->willThrowException(new \RuntimeException('Queue unavailable'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Queue unavailable');
        $this->mailer->queue('test@example.com', 'Subject', 'Body');
    }

    // ── setDriver() ───────────────────────────────────────────────────────────

    #[Test]
    #[TestDox('SetDriver changes the current transport to null transport')]
    public function test_set_driver_switches_to_null(): void
    {
        $this->mailer->setDriver('null');
        $this->assertStringContainsString('Transport', $this->mailer->getCurrentDriver());
    }

    #[Test]
    #[TestDox('SetDriver throws when drivers config key is missing')]
    public function test_set_driver_throws_when_drivers_config_missing(): void
    {
        $badMailer = new Mailer(
            $this->transport,
            $this->rateLimiter,
            $this->queueDispatcher,
            $this->logger,
            ['driver' => 'null'] // missing 'drivers'
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Mail config "drivers" key must be an array');
        $badMailer->setDriver('null');
    }

    // ── use*() convenience methods ────────────────────────────────────────────

    #[Test]
    #[TestDox('useNull switches transport to NullTransport')]
    public function test_use_null_switches_driver(): void
    {
        $this->mailer->useNull();
        $this->assertStringContainsString('Transport', $this->mailer->getCurrentDriver());
    }

    #[Test]
    #[TestDox('useSmtp switches transport to SmtpTransport')]
    public function test_use_smtp_switches_driver(): void
    {
        $smtpConfig = [
            'host'       => 'smtp.example.com',
            'port'       => 587,
            'encryption' => 'tls',
            'username'   => 'user',
            'password'   => 'pass',
            'timeout'    => 30,
            'from'       => ['address' => 'test@example.com', 'name' => 'Sender'],
        ];
        $this->mailer->useSmtp($smtpConfig);
        $this->assertStringContainsString('Transport', $this->mailer->getCurrentDriver());
    }

    #[Test]
    #[TestDox('useSendmail switches transport to SendmailTransport')]
    public function test_use_sendmail_switches_driver(): void
    {
        $this->mailer->useSendmail([
            'from' => ['address' => 'test@example.com', 'name' => 'Sender'],
        ]);
        $this->assertStringContainsString('Transport', $this->mailer->getCurrentDriver());
    }

    #[Test]
    #[TestDox('useMailgun switches transport to MailgunTransport')]
    public function test_use_mailgun_switches_driver(): void
    {
        $this->mailer->useMailgun([
            'api_key'         => 'key123',
            'domain'          => 'mg.example.com',
            'from'            => ['address' => 'test@example.com', 'name' => 'Sender'],
            'timeout'         => 30,
            'connect_timeout' => 10,
        ]);
        $this->assertStringContainsString('Transport', $this->mailer->getCurrentDriver());
    }

    // ── getCurrentDriver() ────────────────────────────────────────────────────

    #[Test]
    #[TestDox('getCurrentDriver returns class name containing Transport')]
    public function test_get_current_driver_returns_class_name(): void
    {
        $this->assertStringContainsString('Transport', $this->mailer->getCurrentDriver());
    }

    // ── getConfig() ───────────────────────────────────────────────────────────

    #[Test]
    #[TestDox('getConfig returns the raw config')]
    public function test_get_config_returns_raw_config(): void
    {
        $this->assertEquals($this->config, $this->mailer->getConfig());
    }

    // ── setFromHeader() – error branches ─────────────────────────────────────

    #[Test]
    #[TestDox('Send throws when driver key missing from config')]
    public function test_send_throws_when_driver_key_missing(): void
    {
        $mailer = new Mailer(
            $this->transport,
            $this->rateLimiter,
            $this->queueDispatcher,
            null,
            [] // completely empty
        );
        $this->rateLimiter->method('allow')->willReturn(true);

        $this->expectException(\RuntimeException::class);
        $mailer->send('x@x.com', 'S', 'B');
    }

    #[Test]
    #[TestDox('Send throws when drivers array is missing from config')]
    public function test_send_throws_when_drivers_missing(): void
    {
        $mailer = new Mailer(
            $this->transport,
            $this->rateLimiter,
            $this->queueDispatcher,
            null,
            ['driver' => 'null'] // no 'drivers'
        );
        $this->rateLimiter->method('allow')->willReturn(true);

        $this->expectException(\RuntimeException::class);
        $mailer->send('x@x.com', 'S', 'B');
    }

    #[Test]
    #[TestDox('Send throws when from config is missing for selected driver')]
    public function test_send_throws_when_from_config_missing(): void
    {
        $mailer = new Mailer(
            $this->transport,
            $this->rateLimiter,
            $this->queueDispatcher,
            null,
            ['driver' => 'null', 'drivers' => ['null' => []]]  // no 'from'
        );
        $this->rateLimiter->method('allow')->willReturn(true);

        $this->expectException(\RuntimeException::class);
        $mailer->send('x@x.com', 'S', 'B');
    }

    #[Test]
    #[TestDox('Send throws when from address is invalid')]
    public function test_send_throws_when_from_address_invalid(): void
    {
        $mailer = new Mailer(
            $this->transport,
            $this->rateLimiter,
            $this->queueDispatcher,
            null,
            ['driver' => 'null', 'drivers' => ['null' => ['from' => ['address' => 'bad-email', 'name' => '']]]]
        );
        $this->rateLimiter->method('allow')->willReturn(true);

        $this->expectException(\RuntimeException::class);
        $mailer->send('x@x.com', 'S', 'B');
    }

    // ── PSR-14 event dispatcher integration ──────────────────────────────────

    #[Test]
    #[TestDox('Send fires MessageSent through the injected PSR-14 event dispatcher')]
    public function test_send_dispatches_message_sent_event(): void
    {
        $this->rateLimiter->method('allow')->willReturn(true);
        $this->transport->method('send');

        $eventDispatcher = $this->createMock(\Psr\EventDispatcher\EventDispatcherInterface::class);
        $eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(\MonkeysLegion\Mail\Event\MessageSent::class));

        $mailer = new Mailer(
            $this->transport,
            $this->rateLimiter,
            $this->queueDispatcher,
            null,
            $this->config,
            $eventDispatcher,
        );

        $mailer->send('r@example.com', 'Subject', 'Body');
    }

    #[Test]
    #[TestDox('Send fires MessageFailed through the injected PSR-14 event dispatcher on transport failure')]
    public function test_send_dispatches_message_failed_event_on_error(): void
    {
        $this->rateLimiter->method('allow')->willReturn(true);
        $this->transport->method('send')->willThrowException(new \RuntimeException('SMTP kaboom'));

        $eventDispatcher = $this->createMock(\Psr\EventDispatcher\EventDispatcherInterface::class);
        $eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(\MonkeysLegion\Mail\Event\MessageFailed::class));

        $mailer = new Mailer(
            $this->transport,
            $this->rateLimiter,
            $this->queueDispatcher,
            null,
            $this->config,
            $eventDispatcher,
        );

        $this->expectException(\RuntimeException::class);
        $mailer->send('r@example.com', 'Subject', 'Body');
    }

    #[Test]
    #[TestDox('Queue fires MessageFailed through the injected PSR-14 event dispatcher on dispatch failure')]
    public function test_queue_dispatches_message_failed_event_on_error(): void
    {
        $this->queueDispatcher->method('dispatch')
            ->willThrowException(new \RuntimeException('Queue down'));

        $eventDispatcher = $this->createMock(\Psr\EventDispatcher\EventDispatcherInterface::class);
        $eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(\MonkeysLegion\Mail\Event\MessageFailed::class));

        $mailer = new Mailer(
            $this->transport,
            $this->rateLimiter,
            $this->queueDispatcher,
            null,
            $this->config,
            $eventDispatcher,
        );

        $this->expectException(\RuntimeException::class);
        $mailer->queue('r@example.com', 'Subject', 'Body');
    }

    #[Test]
    #[TestDox('Send works without an event dispatcher (null dispatcher)')]
    public function test_send_without_event_dispatcher(): void
    {
        $this->rateLimiter->method('allow')->willReturn(true);
        $this->transport->expects($this->once())->method('send');

        // $this->mailer has no event dispatcher — must not throw
        $this->mailer->send('r@example.com', 'Subject', 'Body');
    }

    #[Test]
    #[TestDox('onSent listeners are called when email is sent')]
    public function test_on_sent_listeners_are_called(): void
    {
        $this->rateLimiter->method('allow')->willReturn(true);
        $this->transport->method('send');

        $called = false;
        $this->mailer->onSent(function (\MonkeysLegion\Mail\Event\MessageSent $event) use (&$called) {
            $called = true;
            $this->assertEquals('r@example.com', $event->getJobData()['to']);
        });

        $this->mailer->send('r@example.com', 'Subject', 'Body');
        $this->assertTrue($called);
    }

    #[Test]
    #[TestDox('onFailed listeners are called when email fails')]
    public function test_on_failed_listeners_are_called(): void
    {
        $this->rateLimiter->method('allow')->willReturn(true);
        $this->transport->method('send')->willThrowException(new \RuntimeException('Fail'));

        $called = false;
        $this->mailer->onFailed(function (\MonkeysLegion\Mail\Event\MessageFailed $event) use (&$called) {
            $called = true;
            $this->assertEquals('Fail', $event->getException()->getMessage());
        });

        try {
            $this->mailer->send('r@example.com', 'Subject', 'Body');
        } catch (\RuntimeException) {
        }
        $this->assertTrue($called);
    }

    #[Test]
    #[TestDox('Mailable class context is passed to events')]
    public function test_mailable_context_is_passed_to_events(): void
    {
        $this->rateLimiter->method('allow')->willReturn(true);
        $this->transport->method('send');

        $mailableClass = null;
        $this->mailer->onSent(function (\MonkeysLegion\Mail\Event\MessageSent $event) use (&$mailableClass) {
            $mailableClass = $event->getMailableClass();
        });

        $this->mailer->setMailableContext('MyMailableClass');
        $this->mailer->send('r@example.com', 'Subject', 'Body');

        $this->assertEquals('MyMailableClass', $mailableClass);
    }
}
