<?php

declare(strict_types=1);

namespace MonkeysLegion\Mailer\Tests\Security;

use MonkeysLegion\Mail\Security\DkimSigner;
use MonkeysLegion\Mail\Transport\NullTransport;
use MonkeysLegion\Mail\Transport\SendmailTransport;
use MonkeysLegion\Mail\Transport\SmtpTransport;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(DkimSigner::class)]
#[AllowMockObjectsWithoutExpectations]
class DkimSignerTest extends TestCase
{
    #[Test]
    #[TestDox('generateKeys returns a valid RSA key pair')]
    public function test_generate_keys_returns_key_pair(): void
    {
        if (!extension_loaded('openssl')) {
            $this->markTestSkipped('OpenSSL extension not available');
        }

        $keys = DkimSigner::generateKeys();

        $this->assertArrayHasKey('private', $keys);
        $this->assertArrayHasKey('public', $keys);
        $this->assertStringContainsString('BEGIN PRIVATE KEY', $keys['private']);
        $this->assertStringContainsString('BEGIN PUBLIC KEY', $keys['public']);
    }

    #[Test]
    #[TestDox('shouldSign returns true for a supported remote transport with full DKIM config')]
    public function test_should_sign_returns_true_for_supported_transports(): void
    {
        $config = [
            'dkim_private_key' => 'test_key',
            'dkim_selector' => 'default',
            'dkim_domain' => 'example.com'
        ];

        $shouldSign = DkimSigner::shouldSign(SmtpTransport::class, $config);

        $this->assertTrue($shouldSign);
    }

    #[Test]
    #[TestDox('shouldSign returns false for local transports like NullTransport')]
    public function test_should_sign_returns_false_for_local_transports(): void
    {
        $config = [
            'dkim_private_key' => 'test_key',
            'dkim_selector' => 'default',
            'dkim_domain' => 'example.com'
        ];

        $shouldSign = DkimSigner::shouldSign(NullTransport::class, $config);

        $this->assertFalse($shouldSign);
    }

    #[Test]
    #[TestDox('shouldSign returns false when DKIM config keys are empty')]
    public function test_should_sign_returns_false_when_config_missing(): void
    {
        $config = [
            'dkim_private_key' => '',
            'dkim_selector' => '',
            'dkim_domain' => ''
        ]; // Missing DKIM config

        $shouldSign = DkimSigner::shouldSign(SmtpTransport::class, $config);

        $this->assertFalse($shouldSign);
    }

    #[Test]
    #[TestDox('Constructor accepts private key selector and domain without throwing')]
    public function test_constructor_sets_properties(): void
    {
        $this->expectNotToPerformAssertions();

        $privateKey = 'test_private_key';
        $selector = 'test_selector';
        $domain = 'test.example.com';

        $signer = new DkimSigner($privateKey, $selector, $domain);
    }

    #[Test]
    #[TestDox('sign generates a valid DKIM-Signature header')]
    public function test_sign_generates_valid_signature(): void
    {
        if (!extension_loaded('openssl')) {
            $this->markTestSkipped('OpenSSL extension not available');
        }

        $keys = DkimSigner::generateKeys(1024);
        $privateKey = str_replace(['-----BEGIN PRIVATE KEY-----', '-----END PRIVATE KEY-----', "\n"], '', $keys['private']);

        $signer = new DkimSigner($privateKey, 'default', 'example.com');

        $headers = [
            'From' => 'sender@example.com',
            'To' => 'recipient@example.com',
            'Subject' => 'Test Subject',
            'Date' => 'Mon, 01 Jan 2024 12:00:00 +0000',
            'Message-ID' => '<test@example.com>'
        ];
        $body = 'Test email body';

        $signature = $signer->sign($headers, $body);

        $this->assertStringStartsWith('DKIM-Signature:', $signature);
        $this->assertStringContainsString('v=1', $signature);
        $this->assertStringContainsString('a=rsa-sha256', $signature);
        $this->assertStringContainsString('d=example.com', $signature);
        $this->assertStringContainsString('s=default', $signature);
    }

    #[Test]
    #[TestDox('shouldSign returns false for SendmailTransport')]
    public function test_should_sign_returns_false_for_sendmail(): void
    {
        $config = [
            'dkim_private_key' => 'key',
            'dkim_selector'    => 'sel',
            'dkim_domain'      => 'example.com',
        ];

        $this->assertFalse(DkimSigner::shouldSign(SendmailTransport::class, $config));
    }
}
