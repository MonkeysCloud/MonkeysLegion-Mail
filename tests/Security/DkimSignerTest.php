<?php

namespace MonkeysLegion\Mailer\Tests\Security;

use MonkeysLegion\Mail\Security\DkimSigner;
use MonkeysLegion\Mail\Transport\NullTransport;
use MonkeysLegion\Mail\Transport\SmtpTransport;
use PHPUnit\Framework\TestCase;

class DkimSignerTest extends TestCase
{
    public function testGenerateKeysReturnsKeyPair(): void
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

    public function testShouldSignReturnsTrueForSupportedTransports(): void
    {
        $config = [
            'dkim_private_key' => 'test_key',
            'dkim_selector' => 'default',
            'dkim_domain' => 'example.com'
        ];

        $shouldSign = DkimSigner::shouldSign(SmtpTransport::class, $config);

        $this->assertTrue($shouldSign);
    }

    public function testShouldSignReturnsFalseForLocalTransports(): void
    {
        $config = [
            'dkim_private_key' => 'test_key',
            'dkim_selector' => 'default',
            'dkim_domain' => 'example.com'
        ];

        $shouldSign = DkimSigner::shouldSign(NullTransport::class, $config);

        $this->assertFalse($shouldSign);
    }

    public function testShouldSignReturnsFalseWhenConfigMissing(): void
    {
        $config = [
            'dkim_private_key' => '',
            'dkim_selector' => '',
            'dkim_domain' => ''
        ]; // Missing DKIM config

        $shouldSign = DkimSigner::shouldSign(SmtpTransport::class, $config);

        $this->assertFalse($shouldSign);
    }

    public function testConstructorSetsProperties(): void
    {
        $this->expectNotToPerformAssertions();

        $privateKey = 'test_private_key';
        $selector = 'test_selector';
        $domain = 'test.example.com';

        $signer = new DkimSigner($privateKey, $selector, $domain);
    }

    public function testSignGeneratesValidSignature(): void
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
}
