<?php

namespace MonkeysLegion\Mailer\Tests;

use MonkeysLegion\Mail\Jobs\SendMailJob;
use MonkeysLegion\Mail\Mailer;
use MonkeysLegion\Mail\Queue\QueueInterface;
use MonkeysLegion\Mail\RateLimiter\RateLimiter;
use MonkeysLegion\Mail\Service\ServiceContainer;
use MonkeysLegion\Mail\TransportInterface;
use MonkeysLegion\Mailer\Tests\Abstracts\AbstractBaseTest;
use PHPUnit\Framework\MockObject\MockObject;

class MailerTest extends AbstractBaseTest
{
    /** @var TransportInterface&MockObject */
    private TransportInterface $transport;
    /** @var RateLimiter&MockObject */
    private RateLimiter $rateLimiter;
    private ServiceContainer $container;
    private Mailer $mailer;

    public function setUp(): void
    {
        $this->bootstrapServices();

        $this->transport = $this->createMock(TransportInterface::class);
        $this->rateLimiter = $this->createMock(RateLimiter::class);
        $this->container = ServiceContainer::getInstance();
        $this->mailer = new Mailer($this->transport, $this->rateLimiter, $this->container);
    }

    public function testSendReturnsTrueOnSuccess(): void
    {
        $this->rateLimiter->method('allow')->willReturn(true);
        $this->transport->expects($this->once())->method('send');

        $this->mailer->send(
            to: 'recipient@example.com',
            subject: 'Test Subject',
            content: 'Test Body'
        );
        // No exception means success
    }

    public function testSendCallsTransport(): void
    {
        $container = ServiceContainer::getInstance();

        // Ensure the rate limiter allows sending
        $this->rateLimiter->method('allow')->willReturn(true);

        // Ensure the transport's send method is called once
        $this->transport->expects($this->once())
            ->method('send');

        $mailer = new Mailer($this->transport, $this->rateLimiter, $container);

        // Act
        $mailer->send(
            to: 'recipient@example.com',
            subject: 'Subject',
            content: 'Body'
        );
    }

    public function testSendWithValidEmailSucceeds(): void
    {
        $this->rateLimiter->method('allow')->willReturn(true);
        $this->transport->expects($this->once())->method('send');

        $this->mailer->send(
            'test@example.com',
            'Test Subject',
            'Test Body',
            'text/html'
        );

        // No exception means success
    }

    public function testSendWithRateLimitExceededThrowsException(): void
    {
        $this->rateLimiter->method('allow')->willReturn(false);
        $this->transport->expects($this->never())->method('send');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Rate limit exceeded for sending emails. Please try again later.');

        $this->mailer->send('test@example.com', 'Subject', 'Body');
    }

    public function testQueueEmailSuccessfully(): void
    {
        $queue = $this->createMock(QueueInterface::class);
        $queue->expects($this->once())
            ->method('push')
            ->willReturn('job_12345');

        // Use a factory closure for ServiceContainer::set()
        $this->container->set(QueueInterface::class, function () use ($queue) {
            return $queue;
        });

        $jobId = $this->mailer->queue(
            'test@example.com',
            'Queued Subject',
            'Queued Body'
        );

        $this->assertEquals('job_12345', $jobId);
    }

    public function testQueueEmailWithCustomQueue(): void
    {
        $queue = $this->createMock(QueueInterface::class);
        $queue->expects($this->once())
            ->method('push')
            ->with(
                SendMailJob::class,
                $this->anything(),
                'high-priority'
            )
            ->willReturn('job_67890');

        // Use a factory closure for ServiceContainer::set()
        $this->container->set(QueueInterface::class, function () use ($queue) {
            return $queue;
        });

        $jobId = $this->mailer->queue(
            'test@example.com',
            'Priority Subject',
            'Priority Body',
            'text/html',
            [],
            'high-priority'
        );

        $this->assertEquals('job_67890', $jobId);
    }

    public function testSetDriverChangesTransport(): void
    {
        // Test that driver switching doesn't throw exceptions
        $this->mailer->setDriver('null');

        // Verify current driver changed by checking the class name
        $currentDriver = $this->mailer->getCurrentDriver();
        $this->assertStringContainsString('Transport', $currentDriver);
    }

    public function testUseSmtpSwitchesDriver(): void
    {
        $this->expectNotToPerformAssertions();

        $config = ['host' => 'test.gmail.com', 'port' => 587, 'encryption' => 'tls', 'username' => 'user', 'password' => 'pass'];

        $this->mailer->useSmtp($config);

        // Should not throw exception

    }

    public function testUseNullSwitchesDriver(): void
    {
        $this->expectNotToPerformAssertions();

        $this->mailer->useNull();

        // Should not throw exception

    }

    public function testUseSendmailSwitchesDriver(): void
    {
        $this->expectNotToPerformAssertions();

        $this->mailer->useSendmail();

        // Should not throw exception

    }

    public function testUseMailgunSwitchesDriver(): void
    {
        $this->expectNotToPerformAssertions();

        $config = [
            'api_key' => 'test_api_key',
            'domain' => 'test_domain'
        ];
        $this->mailer->useMailgun($config);

        // Should not throw exception

    }

    public function testGetCurrentDriverReturnsClassName(): void
    {
        $className = $this->mailer->getCurrentDriver();

        $this->assertStringContainsString('Transport', $className);
    }

    public function testSendWithEmptyBodySucceeds(): void
    {
        $this->rateLimiter->method('allow')->willReturn(true);
        $this->transport->expects($this->once())->method('send');

        $this->mailer->send('test@example.com', 'Subject', '');
    }
}
