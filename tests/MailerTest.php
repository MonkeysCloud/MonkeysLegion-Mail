<?php

namespace MonkeysLegion\Mailer\Tests;

use MonkeysLegion\DI\ContainerBuilder;
use MonkeysLegion\Mail\Jobs\SendMailJob;
use MonkeysLegion\Mail\Mailer;
use MonkeysLegion\Mail\Message;
use MonkeysLegion\Mail\Provider\MailServiceProvider;
use MonkeysLegion\Mail\Queue\QueueInterface;
use MonkeysLegion\Mail\RateLimiter\RateLimiter;
use MonkeysLegion\Mail\Service\ServiceContainer;
use MonkeysLegion\Mail\TransportInterface;
use PHPUnit\Framework\TestCase;

class MailerTest extends TestCase
{
    private TransportInterface $transport;
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

    public function testSendReturnsTrueOnSuccess()
    {
        $this->rateLimiter->method('allow')->willReturn(true);
        $this->transport->expects($this->once())->method('send');

        $this->mailer->send(
            to: 'recipient@example.com',
            subject: 'Test Subject',
            content: 'Test Body'
        );

        $this->assertTrue(true); // No exception means success
    }

    public function testSendThrowsOnInvalidEmail()
    {
        $this->rateLimiter->method('allow')->willReturn(true);
        $transport = $this->createMock(TransportInterface::class);
        $rateLimiter = $this->createMock(RateLimiter::class);
        $rateLimiter->method('allow')->willReturn(true);
        $container = ServiceContainer::getInstance();

        $mailer = new Mailer($transport, $rateLimiter, $container);

        try {
            $mailer->send('invalid-email', 'Subject', 'Body');
        } catch (\InvalidArgumentException $e) {
            $this->assertTrue(true);
            return;
        }
        $this->fail('Expected exception not thrown');
    }

    public function testSendCallsTransport()
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

    public function testSendWithValidEmailSucceeds()
    {
        $this->rateLimiter->method('allow')->willReturn(true);
        $this->transport->expects($this->once())->method('send');

        $this->mailer->send(
            'test@example.com',
            'Test Subject',
            'Test Body',
            'text/html'
        );

        $this->assertTrue(true); // No exception means success
    }

    public function testSendWithRateLimitExceededThrowsException()
    {
        $this->rateLimiter->method('allow')->willReturn(false);
        $this->transport->expects($this->never())->method('send');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Rate limit exceeded for sending emails. Please try again later.');

        $this->mailer->send('test@example.com', 'Subject', 'Body');
    }

    public function testQueueEmailSuccessfully()
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

    public function testQueueEmailWithCustomQueue()
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
            [],
            'high-priority'
        );

        echo "\n\nJob ID: $jobId \n\n"; // For debugging purposes
        $this->assertEquals('job_67890', $jobId);
    }

    public function testSetDriverChangesTransport()
    {
        // Test that driver switching doesn't throw exceptions
        $this->mailer->setDriver('null');

        // Verify current driver changed by checking the class name
        $currentDriver = $this->mailer->getCurrentDriver();
        $this->assertStringContainsString('Transport', $currentDriver);
    }

    public function testUseSmtpSwitchesDriver()
    {
        $config = ['host' => 'test.gmail.com', 'port' => 587, 'encryption' => 'testtls', 'username' => 'user', 'password' => 'pass'];

        $this->mailer->useSmtp($config);

        // Should not throw exception
        $this->assertTrue(true);
    }

    public function testUseNullSwitchesDriver()
    {
        $this->mailer->useNull();

        // Should not throw exception
        $this->assertTrue(true);
    }

    public function testUseSendmailSwitchesDriver()
    {
        $this->mailer->useSendmail();

        // Should not throw exception
        $this->assertTrue(true);
    }

    public function testGetCurrentDriverReturnsClassName()
    {
        $className = $this->mailer->getCurrentDriver();

        $this->assertIsString($className);
        $this->assertStringContainsString('Transport', $className);
    }

    public function testSendWithEmptySubjectThrowsException()
    {
        $this->rateLimiter->method('allow')->willReturn(true);
        $this->expectException(\InvalidArgumentException::class);

        $this->mailer->send('test@example.com', '', 'Body');
    }

    public function testSendWithEmptyBodySucceeds()
    {
        $this->rateLimiter->method('allow')->willReturn(true);
        $this->transport->expects($this->once())->method('send');

        $this->mailer->send('test@example.com', 'Subject', '');

        $this->assertTrue(true);
    }

    private function bootstrapServices(): void
    {
        try {
            MailServiceProvider::register(new ContainerBuilder());
        } catch (\Exception $e) {
            throw new \RuntimeException("Failed to bootstrap mail services: " . $e->getMessage(), 0, $e);
        }
    }
}
