<?php

namespace MonkeysLegion\Mailer\Tests\Integration;

use MonkeysLegion\Mail\Mailer;
use MonkeysLegion\Mailer\Tests\Abstracts\AbstractBaseTest;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

#[AllowMockObjectsWithoutExpectations]
class FullWorkflowTest extends AbstractBaseTest
{
    private Mailer $mailer;

    public function setUp(): void
    {
        parent::setUp();

        /** @var \MonkeysLegion\Mail\TransportInterface&\PHPUnit\Framework\MockObject\MockObject $transport */
        $transport = $this->createMock(\MonkeysLegion\Mail\TransportInterface::class);
        /** @var \MonkeysLegion\Mail\RateLimiter\RateLimiter&\PHPUnit\Framework\MockObject\MockObject $rateLimiter */
        $rateLimiter = $this->createMock(\MonkeysLegion\Mail\RateLimiter\RateLimiter::class);
        $rateLimiter->method('allow')->willReturn(true);
        /** @var \MonkeysLegion\Queue\Contracts\QueueDispatcherInterface&\PHPUnit\Framework\MockObject\MockObject $dispatcher */
        $dispatcher = $this->createMock(\MonkeysLegion\Queue\Contracts\QueueDispatcherInterface::class);
        $logger = new \MonkeysLegion\Logger\Logger\NullLogger();
        
        $config = [
            'driver' => 'null',
            'drivers' => [
                'null' => [
                    'from' => [
                        'address' => 'test@example.com',
                        'name' => 'Test Sender'
                    ]
                ],
                'smtp' => [
                    'from' => [
                        'address' => 'test@example.com',
                        'name' => 'Test Sender'
                    ],
                    'host' => 'localhost',
                    'port' => 25
                ]
            ]
        ];

        $this->mailer = new Mailer(
            $transport,
            $rateLimiter,
            $dispatcher,
            $logger,
            $config
        );
    }

    public function testCompleteEmailSendingWorkflow(): void
    {
        $this->expectNotToPerformAssertions();

            // Use null transport for testing
        $this->mailer->useNull();

        // Test should not throw exception
        $this->mailer->send(
            'test@example.com',
            'Integration Test Email',
            '<h1>Test Content</h1><p>This is a test email.</p>',
            'text/html'
        );
    }

    public function testMailerDriverSwitching(): void
    {
        $originalDriver = $this->mailer->getCurrentDriver();

        // Switch to SMTP first, then to null
        $this->mailer->useSmtp([
            'host' => 'test.smtp.com', 
            'port' => 587, 
            'username' => 'test', 
            'password' => 'secret', 
            'timeout' => 30,
            'from' => [
                'address' => 'test@example.com',
                'name' => 'Sender'
            ]
        ]);
        $smtpDriver = $this->mailer->getCurrentDriver();

        $this->mailer->useNull();
        $nullDriver = $this->mailer->getCurrentDriver();

        $this->assertNotEquals($originalDriver, $smtpDriver);
        $this->assertNotEquals($smtpDriver, $nullDriver);
        $this->assertStringContainsString('NullTransport', $nullDriver);
    }
}
