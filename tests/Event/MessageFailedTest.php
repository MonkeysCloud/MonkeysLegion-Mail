<?php

declare(strict_types=1);

namespace MonkeysLegion\Mailer\Tests\Event;

use MonkeysLegion\Logger\Contracts\MonkeysLoggerInterface;
use MonkeysLegion\Mail\Event\MessageFailed;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;

#[CoversClass(MessageFailed::class)]
#[AllowMockObjectsWithoutExpectations]
class MessageFailedTest extends TestCase
{
    private MonkeysLoggerInterface&MockObject $logger;
    private \Exception $exception;
    private array $jobData;

    protected function setUp(): void
    {
        $this->logger    = $this->createMock(MonkeysLoggerInterface::class);
        $this->exception = new \Exception('Something went wrong', 42);
        $this->jobData   = ['job' => 'SendMailJob', 'to' => 'foo@bar.com'];
    }

    // ── Constructor / getters ─────────────────────────────────────────────

    #[Test]
    #[TestDox('Constructor stores all values and getters return them correctly')]
    public function test_constructor_and_getters(): void
    {
        $before = time();
        $event  = new MessageFailed('job-123', $this->jobData, $this->exception, 3, true, null);
        $after  = time();

        $this->assertSame('job-123', $event->getJobId());
        $this->assertSame($this->jobData, $event->getJobData());
        $this->assertSame($this->exception, $event->getException());
        $this->assertSame(3, $event->getAttempts());
        $this->assertTrue($event->willRetry());
        $this->assertGreaterThanOrEqual($before, $event->getFailedAt());
        $this->assertLessThanOrEqual($after, $event->getFailedAt());
    }

    #[Test]
    #[TestDox('willRetry returns false when constructed with false')]
    public function test_will_retry_false(): void
    {
        $event = new MessageFailed('job-x', [], $this->exception, 1, false, null);
        $this->assertFalse($event->willRetry());
    }

    #[Test]
    #[TestDox('getJobData returns empty array when no job data provided')]
    public function test_get_job_data_empty(): void
    {
        $event = new MessageFailed('job-x', [], $this->exception, 1, false, null);
        $this->assertSame([], $event->getJobData());
    }

    // ── Logger integration ────────────────────────────────────────────────

    #[Test]
    #[TestDox('Constructor calls logger->error with correct context')]
    public function test_constructor_calls_logger_error(): void
    {
        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                'MessageFailed event created',
                $this->callback(function (array $ctx) {
                    return $ctx['job_id']        === 'job-abc'
                        && $ctx['job_class']     === 'SendMailJob'
                        && $ctx['attempts']      === 2
                        && $ctx['will_retry']    === true
                        && $ctx['error_message'] === 'Something went wrong'
                        && is_int($ctx['failed_at']);
                })
            );

        new MessageFailed('job-abc', $this->jobData, $this->exception, 2, true, $this->logger);
    }

    #[Test]
    #[TestDox('Constructor logs job_class as "unknown" when job key is absent from jobData')]
    public function test_log_uses_unknown_job_class_when_key_absent(): void
    {
        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                'MessageFailed event created',
                $this->callback(fn(array $ctx) => $ctx['job_class'] === 'unknown')
            );

        new MessageFailed('job-x', ['other_key' => 'value'], $this->exception, 1, false, $this->logger);
    }

    #[Test]
    #[TestDox('Constructor does not call logger when logger is null')]
    public function test_no_logger_does_not_throw(): void
    {
        // Should simply not throw; no logger interaction
        $this->expectNotToPerformAssertions();
        new MessageFailed('job-x', $this->jobData, $this->exception, 1, false, null);
    }

    // ── PSR-14 dispatcher integration ─────────────────────────────────────

    #[Test]
    #[TestDox('Constructor dispatches itself via the injected PSR-14 EventDispatcherInterface')]
    public function test_constructor_dispatches_self_via_event_dispatcher(): void
    {
        /** @var EventDispatcherInterface&MockObject $dispatcher */
        $dispatcher = $this->createMock(EventDispatcherInterface::class);

        $capturedEvent = null;
        $dispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(MessageFailed::class))
            ->willReturnCallback(function (object $event) use (&$capturedEvent) {
                $capturedEvent = $event;
                return $event;
            });

        $event = new MessageFailed('job-disp', $this->jobData, $this->exception, 1, false, null, $dispatcher);

        // The dispatched object must be the same instance
        $this->assertSame($event, $capturedEvent);
    }

    #[Test]
    #[TestDox('Constructor does not call dispatch when no EventDispatcher is provided')]
    public function test_no_dispatcher_does_not_throw(): void
    {
        $this->expectNotToPerformAssertions();
        new MessageFailed('job-no-disp', $this->jobData, $this->exception, 1, true, null, null);
    }

    #[Test]
    #[TestDox('Constructor dispatches after logging (dispatcher receives already-logged event)')]
    public function test_dispatcher_receives_event_after_logging(): void
    {
        $logCalled   = false;
        $dispatchCalled = false;
        $order = [];

        $logger = $this->createMock(MonkeysLoggerInterface::class);
        $logger->method('error')->willReturnCallback(function () use (&$order) {
            $order[] = 'log';
        });

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnCallback(function (object $e) use (&$order) {
            $order[] = 'dispatch';
            return $e;
        });

        new MessageFailed('job-order', $this->jobData, $this->exception, 1, false, $logger, $dispatcher);

        $this->assertSame(['log', 'dispatch'], $order, 'Logger must be called before dispatcher');
    }

    // ── PSR-14 StoppableEventInterface ─────────────────────────────────────

    #[Test]
    #[TestDox('isPropagationStopped returns false by default')]
    public function test_propagation_not_stopped_by_default(): void
    {
        $event = new MessageFailed('job-x', [], $this->exception, 1, false, null);
        $this->assertFalse($event->isPropagationStopped());
    }

    #[Test]
    #[TestDox('stopPropagation causes isPropagationStopped to return true')]
    public function test_stop_propagation(): void
    {
        $event = new MessageFailed('job-x', [], $this->exception, 1, false, null);
        $event->stopPropagation();
        $this->assertTrue($event->isPropagationStopped());
    }

    #[Test]
    #[TestDox('MessageFailed implements StoppableEventInterface')]
    public function test_implements_stoppable_event_interface(): void
    {
        $event = new MessageFailed('job-x', [], $this->exception, 1, false, null);
        $this->assertInstanceOf(\Psr\EventDispatcher\StoppableEventInterface::class, $event);
    }
}
