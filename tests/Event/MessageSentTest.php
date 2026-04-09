<?php

declare(strict_types=1);

namespace MonkeysLegion\Mailer\Tests\Event;

use MonkeysLegion\Logger\Contracts\MonkeysLoggerInterface;
use MonkeysLegion\Mail\Event\MessageSent;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(MessageSent::class)]
#[AllowMockObjectsWithoutExpectations]
class MessageSentTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $jobData;

    protected function setUp(): void
    {
        $this->jobData = [
            'job'     => 'SendMailJob',
            'to'      => 'test@example.com',
            'subject' => 'Hello',
        ];
    }

    #[Test]
    #[TestDox('Constructor sets job id and can be retrieved')]
    public function test_get_job_id_returns_constructed_value(): void
    {
        $event = new MessageSent('job-abc', $this->jobData, 120, null);
        $this->assertEquals('job-abc', $event->getJobId());
    }

    #[Test]
    #[TestDox('Constructor sets job data and can be retrieved')]
    public function test_get_job_data_returns_constructed_value(): void
    {
        $event = new MessageSent('job-abc', $this->jobData, 120, null);
        $this->assertEquals($this->jobData, $event->getJobData());
    }

    #[Test]
    #[TestDox('getSentAt returns a unix timestamp close to now')]
    public function test_get_sent_at_returns_timestamp(): void
    {
        $before = time();
        $event  = new MessageSent('job-abc', $this->jobData, 120, null);
        $after  = time();

        $this->assertGreaterThanOrEqual($before, $event->getSentAt());
        $this->assertLessThanOrEqual($after, $event->getSentAt());
    }

    #[Test]
    #[TestDox('getDuration returns the duration passed to constructor')]
    public function test_get_duration_returns_constructed_value(): void
    {
        $event = new MessageSent('job-xyz', $this->jobData, 350, null);
        $this->assertEquals(350, $event->getDuration());
    }

    #[Test]
    #[TestDox('Constructor calls logger smartLog when logger provided')]
    public function test_constructor_calls_logger_smartlog(): void
    {
        $logger = $this->createMock(MonkeysLoggerInterface::class);
        $logger->expects($this->once())
            ->method('smartLog')
            ->with('MessageSent event created', $this->isArray());

        new MessageSent('job-log', $this->jobData, 50, $logger);
    }

    #[Test]
    #[TestDox('Constructor works without logger')]
    public function test_constructor_without_logger(): void
    {
        $event = new MessageSent('job-nolog', $this->jobData, 75, null);
        $this->assertEquals('job-nolog', $event->getJobId());
    }

    #[Test]
    #[TestDox('Log context includes expected keys')]
    public function test_constructor_logger_context_has_expected_keys(): void
    {
        $logger = $this->createMock(MonkeysLoggerInterface::class);
        $logger->expects($this->once())
            ->method('smartLog')
            ->with(
                'MessageSent event created',
                $this->callback(fn($ctx) =>
                    array_key_exists('job_id', $ctx) &&
                    array_key_exists('job_class', $ctx) &&
                    array_key_exists('duration_ms', $ctx) &&
                    array_key_exists('sent_at', $ctx)
                )
            );

        new MessageSent('job-ctx', $this->jobData, 100, $logger);
    }

    #[Test]
    #[TestDox('When job data has no job key, log still works')]
    public function test_constructor_handles_missing_job_class_in_data(): void
    {
        $logger = $this->createMock(MonkeysLoggerInterface::class);
        $logger->expects($this->once())
            ->method('smartLog')
            ->with(
                'MessageSent event created',
                $this->callback(fn($ctx) => $ctx['job_class'] === 'unknown')
            );

        new MessageSent('job-noclass', [], 10, $logger);
    }

    // ── PSR-14 StoppableEventInterface ────────────────────────────────────

    #[Test]
    #[TestDox('MessageSent implements StoppableEventInterface')]
    public function test_implements_stoppable_event_interface(): void
    {
        $event = new MessageSent('job-x', [], 0, null);
        $this->assertInstanceOf(\Psr\EventDispatcher\StoppableEventInterface::class, $event);
    }

    #[Test]
    #[TestDox('isPropagationStopped returns false by default')]
    public function test_propagation_not_stopped_by_default(): void
    {
        $event = new MessageSent('job-x', [], 0, null);
        $this->assertFalse($event->isPropagationStopped());
    }

    #[Test]
    #[TestDox('stopPropagation causes isPropagationStopped to return true')]
    public function test_stop_propagation(): void
    {
        $event = new MessageSent('job-x', [], 0, null);
        $event->stopPropagation();
        $this->assertTrue($event->isPropagationStopped());
    }

    // ── PSR-14 dispatcher injection ───────────────────────────────────────

    #[Test]
    #[TestDox('Constructor dispatches itself when EventDispatcherInterface is provided')]
    public function test_constructor_dispatches_self_via_event_dispatcher(): void
    {
        $dispatcher = $this->createMock(\Psr\EventDispatcher\EventDispatcherInterface::class);

        $capturedEvent = null;
        $dispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(MessageSent::class))
            ->willReturnCallback(function (object $event) use (&$capturedEvent) {
                $capturedEvent = $event;
                return $event;
            });

        $event = new MessageSent('job-disp', $this->jobData, 100, null, $dispatcher);
        $this->assertSame($event, $capturedEvent);
    }

    #[Test]
    #[TestDox('No dispatcher provided means dispatch is never called')]
    public function test_no_dispatcher_does_not_throw(): void
    {
        $this->expectNotToPerformAssertions();
        new MessageSent('job-no-disp', [], 50, null, null);
    }
}

