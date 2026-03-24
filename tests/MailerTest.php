<?php

namespace MonkeysLegion\Mailer\Tests;

use MonkeysLegion\Logger\Contracts\MonkeysLoggerInterface;
use MonkeysLegion\Mail\Jobs\SendMailJob;
use MonkeysLegion\Mail\Mailer;
use MonkeysLegion\Mail\RateLimiter\RateLimiter;
use MonkeysLegion\Mail\TransportInterface;
use MonkeysLegion\Mailer\Tests\Abstracts\AbstractBaseTest;
use MonkeysLegion\Queue\Contracts\QueueDispatcherInterface;
use PHPUnit\Framework\MockObject\MockObject;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

#[AllowMockObjectsWithoutExpectations]
class MailerTest extends AbstractBaseTest
{
    /** @var TransportInterface&MockObject */
    private TransportInterface $transport;
    /** @var RateLimiter&MockObject */
    private RateLimiter $rateLimiter;
    /** @var QueueDispatcherInterface&MockObject */
    private QueueDispatcherInterface $queueDispatcher;
    /** @var MonkeysLoggerInterface&MockObject */
    private MonkeysLoggerInterface $logger;
    /** @var Mailer */
    private Mailer $mailer;

    public function setUp(): void
    {
        $this->bootstrapServices();

        $this->transport = $this->createMock(TransportInterface::class);
        $this->rateLimiter = $this->createMock(RateLimiter::class);
        $this->queueDispatcher = $this->createMock(QueueDispatcherInterface::class);
        $this->logger = $this->createMock(MonkeysLoggerInterface::class);
        
        $config = [
            'driver' => 'null',
            'drivers' => [
                'null' => [
                    'from' => [
                        'address' => 'test@example.com',
                        'name' => 'Test Sender'
                    ]
                ]
            ]
        ];

        $this->mailer = new Mailer(
            $this->transport,
            $this->rateLimiter,
            $this->queueDispatcher,
            $this->logger,
            $config,
        );
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
        // Ensure the rate limiter allows sending
        $this->rateLimiter->method('allow')->willReturn(true);

        // Ensure the transport's send method is called once
        $this->transport->expects($this->once())
            ->method('send');

        $config = $this->mailer->getConfig();
        $mailer = new Mailer($this->transport, $this->rateLimiter, $this->queueDispatcher, $this->logger, $config);

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
        $this->queueDispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(SendMailJob::class), 'default');

        $result = $this->mailer->queue(
            'test@example.com',
            'Queued Subject',
            'Queued Body'
        );

        $this->assertTrue($result);
    }

    public function testQueueEmailWithCustomQueue(): void
    {
        $this->queueDispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(SendMailJob::class), 'high-priority');

        $result = $this->mailer->queue(
            'test@example.com',
            'Priority Subject',
            'Priority Body',
            'text/html',
            [],
            'high-priority'
        );

        $this->assertTrue($result);
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

        $config = [
            'host' => 'test.gmail.com', 
            'port' => 587, 
            'encryption' => 'tls', 
            'username' => 'user', 
            'password' => 'pass',
            'timeout' => 30,
            'from' => ['address' => 'test@example.com', 'name' => 'Sender']
        ];

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

        $this->mailer->useSendmail([
            'from' => ['address' => 'test@example.com', 'name' => 'Sender']
        ]);

        // Should not throw exception

    }

    public function testUseMailgunSwitchesDriver(): void
    {
        $this->expectNotToPerformAssertions();

        $config = [
            'api_key' => 'test_api_key',
            'domain' => 'test_domain',
            'from' => ['address' => 'test@example.com', 'name' => 'Sender'],
            'timeout' => 30,
            'connect_timeout' => 10
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
