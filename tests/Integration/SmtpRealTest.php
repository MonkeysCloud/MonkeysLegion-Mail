<?php

declare(strict_types=1);

namespace MonkeysLegion\Mailer\Tests\Integration;

use MonkeysLegion\Mail\Message;
use MonkeysLegion\Mail\Transport\SmtpTransport;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Integration test for real SMTP server.
 */
#[AllowMockObjectsWithoutExpectations]
class SmtpRealTest extends TestCase
{
    private array $config;
    private string $recipient;

    protected function setUp(): void
    {
        $host = $_ENV['SMTP_HOST'] ?? '';
        $port = $_ENV['SMTP_PORT'] ?? '';
        
        if (empty($host) || empty($port)) {
            $this->markTestSkipped('Real SMTP credentials not provided in environment variables (SMTP_HOST, SMTP_PORT).');
        }

        $this->config = [
            'host' => $host,
            'port' => (int)$port,
            'encryption' => $_ENV['SMTP_ENCRYPTION'] ?? 'tls',
            'username' => $_ENV['SMTP_USERNAME'] ?? '',
            'password' => $_ENV['SMTP_PASSWORD'] ?? '',
            'timeout' => 15,
            'from' => [
                'address' => $_ENV['SMTP_FROM_ADDRESS'] ?? 'test@example.com',
                'name' => $_ENV['SMTP_FROM_NAME'] ?? 'Integration Test'
            ]
        ];

        $this->recipient = $_ENV['SMTP_RECIPIENT'] ?? 'test-recipient@example.com';
    }

    #[Test]
    #[TestDox('Sends email using real SMTP credentials')]
    public function test_real_smtp_send(): void
    {
        /** @var \MonkeysLegion\Logger\Contracts\MonkeysLoggerInterface $logger */
        $logger = \MonkeysLegion\DI\Container::instance()->get(\MonkeysLegion\Logger\Contracts\MonkeysLoggerInterface::class);
        $transport = new SmtpTransport($this->config, $logger);

        $html = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='utf-8'>
            <title>Integration Test</title>
        </head>
        <body style='font-family: Arial, sans-serif;'>
            <h2>SMTP Integration Test</h2>
            <p>If you received this message, the SmtpTransport driver is working correctly!</p>
        </body>
        </html>
        ";

        $message = new Message(
            $this->recipient,
            'Integration Test: SmtpTransport',
            $html,
            Message::CONTENT_TYPE_HTML
        );

        try {
            $transport->send($message);
            $this->assertTrue(true, 'Email sent successfully via real SMTP.');
        } catch (\Exception $e) {
            $this->fail('Failed to send email via real SMTP: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
        }
    }
}
