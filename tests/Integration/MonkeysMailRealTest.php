<?php

declare(strict_types=1);

namespace MonkeysLegion\Mailer\Tests\Integration;

use MonkeysLegion\Mail\Message;
use MonkeysLegion\Mail\Transport\MonkeysMailTransport;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Integration test for MonkeysMail API.
 * 
 * To run this test, set the MONKEYS_MAIL_API_KEY environment variable:
 * 
 * export MONKEYS_MAIL_API_KEY='your-real-key-here'
 * ./vendor/bin/phpunit tests/Integration/MonkeysMailRealTest.php
 */
#[AllowMockObjectsWithoutExpectations]
class MonkeysMailRealTest extends TestCase
{
    private string $apiKey;
    private string $recipient;

    protected function setUp(): void
    {
        $this->apiKey = $_ENV['MONKEYS_MAIL_API_KEY'] ?? '';
        $this->recipient = $_ENV['MONKEYS_MAIL_RECIPIENT'] ?? '';

        if (empty($this->apiKey) || empty($this->recipient)) {
            $this->markTestSkipped('Real API key or recipient not provided in environment variable MONKEYS_MAIL_API_KEY or MONKEYS_MAIL_RECIPIENT.');
        }
    }

    #[Test]
    #[TestDox('Sends email using real MonkeysMail API')]
    public function testRealApiSend(): void
    {
        $config = [
            'api_key' => $this->apiKey,
            'domain' => 'monkeys.cloud',
            'tracking_opens' => true,
            'tracking_clicks' => true,
            'from' => [
                'address' => 'no-reply@monkeys.cloud',
                'name' => 'MonkeysCloud Test'
            ]
        ];

        $transport = new MonkeysMailTransport($config);

        $html = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='utf-8'>
            <title>Integration Test</title>
        </head>
        <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
            <h2>Welcome to MonkeysCloud!</h2>
            <p>This is an <strong>integration test</strong> for the MonkeysMailTransport driver.</p>
            <p>If you received this message, the driver is working correctly!</p>
            <p>Thank you for signing up. Your account has been successfully created.</p>
            <p>If you have any questions, please feel free to reach out to our support team.</p>
            <p>Best regards,<br>The MonkeysCloud Team</p>
        </body>
        </html>
        ";

        $message = new Message(
            $this->recipient,
            'Integration Test: MonkeysMailTransport',
            $html,
            Message::CONTENT_TYPE_HTML
        );
        $message->setFrom('MonkeysCloud Support <no-reply@monkeys.cloud>');

        try {
            $transport->send($message);
            $this->assertTrue(true, 'Email sent successfully via API.');
        } catch (\Exception $e) {
            $this->fail('Failed to send email via real API: ' . $e->getMessage());
        }
    }
}
