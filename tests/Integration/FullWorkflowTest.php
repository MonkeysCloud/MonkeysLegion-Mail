<?php

namespace MonkeysLegion\Mailer\Tests\Integration;

use MonkeysLegion\DI\ContainerBuilder;
use MonkeysLegion\Mail\Mailer;
use MonkeysLegion\Mail\Provider\MailServiceProvider;
use MonkeysLegion\Mail\Service\ServiceContainer;
use PHPUnit\Framework\TestCase;

class FullWorkflowTest extends TestCase
{
    protected function setUp(): void
    {
        $this->bootstrapServices();
    }

    private function bootstrapServices(): void
    {
        try {
            MailServiceProvider::register(new ContainerBuilder());
        } catch (\Exception $e) {
            throw new \RuntimeException("Failed to bootstrap mail services: " . $e->getMessage(), 0, $e);
        }
    }

    public function testCompleteEmailSendingWorkflow()
    {
        $container = ServiceContainer::getInstance();
        $mailer = $container->get(Mailer::class);

        // Use null transport for testing
        $mailer->useNull();

        // Test should not throw exception
        $mailer->send(
            'test@example.com',
            'Integration Test Email',
            '<h1>Test Content</h1><p>This is a test email.</p>',
            'text/html'
        );

        $this->assertTrue(true);
    }

    public function testMailerDriverSwitching()
    {
        $container = ServiceContainer::getInstance();
        $mailer = $container->get(Mailer::class);

        $originalDriver = $mailer->getCurrentDriver();

        // Switch to SMTP first, then to null
        $mailer->useSmtp(['host' => 'test.smtp.com', 'port' => 587]);
        $smtpDriver = $mailer->getCurrentDriver();

        $mailer->useNull();
        $nullDriver = $mailer->getCurrentDriver();

        $this->assertNotEquals($originalDriver, $smtpDriver);
        $this->assertNotEquals($smtpDriver, $nullDriver);
        $this->assertStringContainsString('NullTransport', $nullDriver);
    }

    public function testServiceContainerIntegration()
    {
        $container = ServiceContainer::getInstance();

        // Check if service exists by trying to get it
        try {
            $mailer = $container->get(Mailer::class);
            $this->assertInstanceOf(Mailer::class, $mailer);

            // Test singleton behavior
            $mailer2 = $container->get(Mailer::class);
            $this->assertSame($mailer, $mailer2);
        } catch (\Exception $e) {
            $this->fail('Mailer service not found in container: ' . $e->getMessage());
        }
    }
}
