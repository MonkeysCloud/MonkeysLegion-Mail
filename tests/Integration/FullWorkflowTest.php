<?php

namespace MonkeysLegion\Mailer\Tests\Integration;

use MonkeysLegion\Mail\Mailer;
use MonkeysLegion\Mail\Service\ServiceContainer;
use MonkeysLegion\Mailer\Tests\Abstracts\AbstractBaseTest;

class FullWorkflowTest extends AbstractBaseTest
{
    public function testCompleteEmailSendingWorkflow(): void
    {
        $this->expectNotToPerformAssertions();

        $container = ServiceContainer::getInstance();

        /** @var Mailer $mailer */
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
    }

    public function testMailerDriverSwitching(): void
    {
        $container = ServiceContainer::getInstance();

        /** @var Mailer $mailer */
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

    public function testServiceContainerIntegration(): void
    {
        $container = ServiceContainer::getInstance();

        // Check if service exists by trying to get it
        try {
            /** @var Mailer $mailer */
            $mailer = $container->get(Mailer::class);
            $this->assertInstanceOf(Mailer::class, $mailer);

            // Test singleton behavior
            /** @var Mailer $mailer2 */
            $mailer2 = $container->get(Mailer::class);
            $this->assertSame($mailer, $mailer2);
        } catch (\Exception $e) {
            $this->fail('Mailer service not found in container: ' . $e->getMessage());
        }
    }
}
