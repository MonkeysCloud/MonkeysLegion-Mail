<?php

declare(strict_types=1);

namespace MonkeysLegion\Mailer\Tests\Integration;

use MonkeysLegion\Mail\Message;
use MonkeysLegion\Mail\Transport\SendmailTransport;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Integration test for real system Sendmail binary.
 */
#[AllowMockObjectsWithoutExpectations]
class SendmailRealTest extends TestCase
{
    private string $command;
    private string $recipient;

    protected function setUp(): void
    {
        // By default, just testing if the binary actually works. 
        // Can be skipped if "SENDMAIL_BIN" is unset if it's strictly env-driven.
        $this->command = $_ENV['SENDMAIL_BIN'] ?? '';
        
        if (empty($this->command)) {
            $this->markTestSkipped('Real Sendmail test skipped. Set SENDMAIL_BIN environment variable (e.g., /usr/sbin/sendmail -bs) to run.');
        }

        $this->recipient = $_ENV['SENDMAIL_RECIPIENT'] ?? 'test-recipient@example.com';
    }

    #[Test]
    #[TestDox('Sends email using real local Sendmail binary')]
    public function test_real_sendmail_send(): void
    {
        $config = [
            'command' => $this->command,
        ];

        $transport = new SendmailTransport($config);

        $html = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='utf-8'>
            <title>Integration Test</title>
        </head>
        <body style='font-family: Arial, sans-serif;'>
            <h2>Sendmail Integration Test</h2>
            <p>If you received this message, the SendmailTransport driver is working correctly!</p>
        </body>
        </html>
        ";

        $message = new Message(
            $this->recipient,
            'Integration Test: SendmailTransport',
            $html,
            Message::CONTENT_TYPE_HTML
        );
        $message->setFrom('integration@example.com');

        try {
            $transport->send($message);
            $this->assertTrue(true, 'Email sent successfully via system sendmail.');
        } catch (\Exception $e) {
            $this->fail('Failed to send email via system sendmail: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
        }
    }
}
