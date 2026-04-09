<?php

declare(strict_types=1);

namespace MonkeysLegion\Mailer\Tests\Integration;

use MonkeysLegion\Mail\Message;
use MonkeysLegion\Mail\Transport\MailgunTransport;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Integration test for Mailgun API.
 */
#[AllowMockObjectsWithoutExpectations]
class MailgunRealTest extends TestCase
{
    private string $apiKey;
    private string $domain;
    private string $recipient;

    protected function setUp(): void
    {
        $this->apiKey = $_ENV['MAILGUN_API_KEY'] ?? '';
        $this->domain = $_ENV['MAILGUN_DOMAIN'] ?? '';
        
        if (empty($this->apiKey) || empty($this->domain)) {
            $this->markTestSkipped('Real Mailgun credentials not provided in environment variables (MAILGUN_API_KEY, MAILGUN_DOMAIN).');
        }

        $this->recipient = $_ENV['MAILGUN_RECIPIENT'] ?? 'test-recipient@example.com';
    }

    #[Test]
    #[TestDox('Sends email using real Mailgun API')]
    public function test_real_mailgun_send(): void
    {
        $config = [
            'api_key' => $this->apiKey,
            'domain' => $this->domain,
            'region' => $_ENV['MAILGUN_REGION'] ?? 'us',
            'from' => [
                'address' => $_ENV['MAILGUN_FROM_ADDRESS'] ?? 'no-reply@' . $this->domain,
                'name' => $_ENV['MAILGUN_FROM_NAME'] ?? 'Mailgun Test'
            ]
        ];

        $transport = new MailgunTransport($config);

        $html = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='utf-8'>
            <title>Integration Test</title>
        </head>
        <body style='font-family: Arial, sans-serif;'>
            <h2>Mailgun Integration Test</h2>
            <p>If you received this message, the MailgunTransport driver is working correctly!</p>
        </body>
        </html>
        ";

        $message = new Message(
            $this->recipient,
            'Integration Test: MailgunTransport',
            $html,
            Message::CONTENT_TYPE_HTML
        );

        try {
            $transport->send($message);
            $this->assertTrue(true, 'Email sent successfully via Mailgun API.');
        } catch (\Exception $e) {
            $this->fail('Failed to send email via Mailgun API: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
        }
    }
}
