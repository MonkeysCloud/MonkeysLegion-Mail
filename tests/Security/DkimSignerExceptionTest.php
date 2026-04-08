<?php

declare(strict_types=1);

/**
 * Namespace-level function mocks for DkimSigner exception-path testing.
 *
 * These override PHP built-ins when called from the MonkeysLegion\Mail\Security namespace,
 * allowing us to simulate failure conditions without needing a real misconfigured system.
 */
namespace MonkeysLegion\Mail\Security;

if (!function_exists('MonkeysLegion\Mail\Security\openssl_pkey_new')) {
    function openssl_pkey_new(array $options = []): mixed
    {
        if (\MonkeysLegion\Mailer\Tests\Security\DkimSignerExceptionTest::$opensslPkeyNewReturn !== null) {
            return \MonkeysLegion\Mailer\Tests\Security\DkimSignerExceptionTest::$opensslPkeyNewReturn;
        }
        return \openssl_pkey_new($options);
    }
}

if (!function_exists('MonkeysLegion\Mail\Security\openssl_pkey_export')) {
    function openssl_pkey_export(mixed $key, string &$output, ?string $passphrase = null, ?array $options = null): bool
    {
        if (\MonkeysLegion\Mailer\Tests\Security\DkimSignerExceptionTest::$opensslPkeyExportReturn !== null) {
            // Optionally also set the $output if we want it to look empty
            if (\MonkeysLegion\Mailer\Tests\Security\DkimSignerExceptionTest::$opensslPkeyExportOutput !== null) {
                $output = \MonkeysLegion\Mailer\Tests\Security\DkimSignerExceptionTest::$opensslPkeyExportOutput;
            }
            return \MonkeysLegion\Mailer\Tests\Security\DkimSignerExceptionTest::$opensslPkeyExportReturn;
        }
        return \openssl_pkey_export($key, $output, $passphrase, $options ?? []);
    }
}

if (!function_exists('MonkeysLegion\Mail\Security\openssl_pkey_get_details')) {
    function openssl_pkey_get_details(\OpenSSLAsymmetricKey $key): array|false
    {
        if (\MonkeysLegion\Mailer\Tests\Security\DkimSignerExceptionTest::$opensslPkeyGetDetailsReturn !== null) {
            return \MonkeysLegion\Mailer\Tests\Security\DkimSignerExceptionTest::$opensslPkeyGetDetailsReturn;
        }
        return \openssl_pkey_get_details($key);
    }
}

if (!function_exists('MonkeysLegion\Mail\Security\openssl_sign')) {
    function openssl_sign(string $data, string &$signature, mixed $private_key, string|int $algorithm = OPENSSL_ALGO_SHA1): bool
    {
        if (\MonkeysLegion\Mailer\Tests\Security\DkimSignerExceptionTest::$opensslSignReturn !== null) {
            $signature = '';
            return \MonkeysLegion\Mailer\Tests\Security\DkimSignerExceptionTest::$opensslSignReturn;
        }
        return \openssl_sign($data, $signature, $private_key, $algorithm);
    }
}

if (!function_exists('MonkeysLegion\Mail\Security\openssl_pkey_get_private')) {
    function openssl_pkey_get_private(mixed $private_key, ?string $passphrase = null): \OpenSSLAsymmetricKey|false
    {
        if (\MonkeysLegion\Mailer\Tests\Security\DkimSignerExceptionTest::$opensslPkeyGetPrivateReturn !== null) {
            return \MonkeysLegion\Mailer\Tests\Security\DkimSignerExceptionTest::$opensslPkeyGetPrivateReturn;
        }
        return \openssl_pkey_get_private($private_key, $passphrase ?? '');
    }
}

if (!function_exists('MonkeysLegion\Mail\Security\realpath')) {
    function realpath(string $path): string|false
    {
        if (\MonkeysLegion\Mailer\Tests\Security\DkimSignerExceptionTest::$realpathReturn !== null) {
            return \MonkeysLegion\Mailer\Tests\Security\DkimSignerExceptionTest::$realpathReturn;
        }
        return \realpath($path);
    }
}

if (!function_exists('MonkeysLegion\Mail\Security\is_readable')) {
    function is_readable(string $filename): bool
    {
        if (\MonkeysLegion\Mailer\Tests\Security\DkimSignerExceptionTest::$isReadableReturn !== null) {
            return \MonkeysLegion\Mailer\Tests\Security\DkimSignerExceptionTest::$isReadableReturn;
        }
        return \is_readable($filename);
    }
}

if (!function_exists('MonkeysLegion\Mail\Security\is_writable')) {
    function is_writable(string $filename): bool
    {
        if (\MonkeysLegion\Mailer\Tests\Security\DkimSignerExceptionTest::$isWritableReturn !== null) {
            return \MonkeysLegion\Mailer\Tests\Security\DkimSignerExceptionTest::$isWritableReturn;
        }
        return \is_writable($filename);
    }
}

// ─────────────────────────────────────────────────────────────────────────────

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
use RuntimeException;

#[CoversClass(DkimSigner::class)]
#[AllowMockObjectsWithoutExpectations]
class DkimSignerExceptionTest extends TestCase
{
    // ── Namespace mock control properties ────────────────────────────────────
    public static ?bool   $opensslPkeyNewReturn        = null; // false = simulate failure
    public static ?bool   $opensslPkeyExportReturn     = null;
    public static ?string $opensslPkeyExportOutput     = null;
    public static mixed   $opensslPkeyGetDetailsReturn = null; // false or array
    public static ?bool   $opensslSignReturn           = null; // false = failure
    public static mixed   $opensslPkeyGetPrivateReturn = null; // false = bad key
    public static mixed   $realpathReturn              = null; // false = path not found
    public static ?bool   $isReadableReturn            = null;
    public static ?bool   $isWritableReturn            = null;

    protected function tearDown(): void
    {
        // Reset ALL mock overrides after every test so nothing bleeds through.
        self::$opensslPkeyNewReturn        = null;
        self::$opensslPkeyExportReturn     = null;
        self::$opensslPkeyExportOutput     = null;
        self::$opensslPkeyGetDetailsReturn = null;
        self::$opensslSignReturn           = null;
        self::$opensslPkeyGetPrivateReturn = null;
        self::$realpathReturn              = null;
        self::$isReadableReturn            = null;
        self::$isWritableReturn            = null;
    }

    // ── generateKeys() ───────────────────────────────────────────────────────

    #[Test]
    #[TestDox('generateKeys throws when OpenSSL config file is not found (realpath returns false)')]
    public function test_generate_keys_throws_when_openssl_cnf_not_found(): void
    {
        self::$realpathReturn = false; // simulate missing openssl.cnf

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OpenSSL config file not found or not readable');

        DkimSigner::generateKeys();
    }

    #[Test]
    #[TestDox('generateKeys throws when OpenSSL config file is not readable')]
    public function test_generate_keys_throws_when_openssl_cnf_not_readable(): void
    {
        // realpath returns a plausible-looking path but is_readable says no
        self::$realpathReturn  = '/some/path/openssl.cnf';
        self::$isReadableReturn = false;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OpenSSL config file not found or not readable');

        DkimSigner::generateKeys();
    }

    #[Test]
    #[TestDox('generateKeys throws when tmp directory is not writable')]
    public function test_generate_keys_throws_when_tmp_not_writable(): void
    {
        // Let openssl.cnf be found & readable, but tmp not writable
        self::$realpathReturn  = '/some/path/openssl.cnf';
        self::$isReadableReturn = true;
        self::$isWritableReturn = false;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Temporary directory is not writable');

        DkimSigner::generateKeys();
    }

    #[Test]
    #[TestDox('generateKeys throws when openssl_pkey_new fails')]
    public function test_generate_keys_throws_when_pkey_new_fails(): void
    {
        // Real openssl.cnf exists in repo — allow realpath / is_readable / is_writable to pass normally
        self::$opensslPkeyNewReturn = false; // simulate failure

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to generate private key');

        DkimSigner::generateKeys();
    }

    #[Test]
    #[TestDox('generateKeys throws when exported private key is empty string')]
    public function test_generate_keys_throws_when_private_key_empty_after_export(): void
    {
        // openssl_pkey_new succeeds (null = passthrough), but export gives empty string
        self::$opensslPkeyExportReturn = true;  // export "succeeds"
        self::$opensslPkeyExportOutput = '';    // …but key is empty

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Exported private key is not a valid string');

        DkimSigner::generateKeys();
    }

    #[Test]
    #[TestDox('generateKeys throws when openssl_pkey_export returns false')]
    public function test_generate_keys_throws_when_pkey_export_fails(): void
    {
        // We need the key resource to be valid so pkey_new passes, then export fails.
        // Strategy: let pkey_new run normally but force export to return false AND leave output non-empty
        // so we hit the "!$exportResult" branch (not the empty-string branch).
        self::$opensslPkeyExportReturn = false;
        self::$opensslPkeyExportOutput = 'SOME_KEY'; // non-empty so first check passes

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to export private key');

        DkimSigner::generateKeys();
    }

    #[Test]
    #[TestDox('generateKeys throws when openssl_pkey_get_details returns false')]
    public function test_generate_keys_throws_when_get_details_fails(): void
    {
        self::$opensslPkeyGetDetailsReturn = false;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to get public key details');

        DkimSigner::generateKeys();
    }

    #[Test]
    #[TestDox('generateKeys throws when public key is missing from details array')]
    public function test_generate_keys_throws_when_public_key_missing_from_details(): void
    {
        // Details comes back but without a 'key' entry
        self::$opensslPkeyGetDetailsReturn = ['bits' => 2048]; // no 'key'

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Public key is not a valid string');

        DkimSigner::generateKeys();
    }

    #[Test]
    #[TestDox('generateKeys throws when public key entry is empty string')]
    public function test_generate_keys_throws_when_public_key_empty(): void
    {
        self::$opensslPkeyGetDetailsReturn = ['key' => '']; // present but empty

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Public key is not a valid string');

        DkimSigner::generateKeys();
    }

    // ── sign() / cleanPrivateKey() ────────────────────────────────────────────

    #[Test]
    #[TestDox('sign throws RuntimeException when supplied private key is invalid (cleanPrivateKey path)')]
    public function test_sign_throws_when_private_key_invalid(): void
    {
        // Force openssl_pkey_get_private (called inside cleanPrivateKey) to fail
        self::$opensslPkeyGetPrivateReturn = false;

        $signer = new DkimSigner('TOTALLY_INVALID_KEY_DATA', 'sel', 'example.com');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid private key format');

        $signer->sign(['From' => 'a@b.com'], 'body');
    }

    #[Test]
    #[TestDox('sign throws RuntimeException when openssl_sign fails')]
    public function test_sign_throws_when_openssl_sign_fails(): void
    {
        if (!extension_loaded('openssl')) {
            $this->markTestSkipped('OpenSSL extension not available');
        }

        // Generate a real key so cleanPrivateKey passes, then force openssl_sign to fail
        $keys         = DkimSigner::generateKeys(1024);
        $rawKey       = str_replace(
            ['-----BEGIN PRIVATE KEY-----', '-----END PRIVATE KEY-----', "\n"],
            '',
            $keys['private']
        );

        $signer = new DkimSigner($rawKey, 'sel', 'example.com');

        self::$opensslSignReturn = false; // fail the actual signing step

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to sign DKIM headers');

        $signer->sign(['From' => 'a@b.com', 'Subject' => 'Test'], 'Hello body');
    }

    // ── shouldSign() – edge cases not yet in the existing suite ─────────────

    #[Test]
    #[TestDox('shouldSign returns false when only some required DKIM config keys are present')]
    public function test_should_sign_returns_false_when_selector_missing(): void
    {
        $config = [
            'dkim_private_key' => 'key',
            'dkim_selector'    => '',      // empty
            'dkim_domain'      => 'example.com',
        ];

        $this->assertFalse(DkimSigner::shouldSign(SmtpTransport::class, $config));
    }

    #[Test]
    #[TestDox('shouldSign returns false when dkim_domain is missing')]
    public function test_should_sign_returns_false_when_domain_missing(): void
    {
        $config = [
            'dkim_private_key' => 'key',
            'dkim_selector'    => 'sel',
            'dkim_domain'      => '',      // empty
        ];

        $this->assertFalse(DkimSigner::shouldSign(SmtpTransport::class, $config));
    }

    #[Test]
    #[TestDox('shouldSign returns false for an unrecognised local transport string')]
    public function test_should_sign_returns_false_for_sendmail_transport(): void
    {
        $config = [
            'dkim_private_key' => 'key',
            'dkim_selector'    => 'sel',
            'dkim_domain'      => 'example.com',
        ];

        $this->assertFalse(DkimSigner::shouldSign(SendmailTransport::class, $config));
        $this->assertFalse(DkimSigner::shouldSign(NullTransport::class, $config));
    }

    // ── sign() – canonicalisation / happy-path edge cases ────────────────────

    #[Test]
    #[TestDox('sign produces signature even when some optional headers are absent')]
    public function test_sign_with_partial_headers(): void
    {
        if (!extension_loaded('openssl')) {
            $this->markTestSkipped('OpenSSL extension not available');
        }

        $keys   = DkimSigner::generateKeys(1024);
        $rawKey = str_replace(
            ['-----BEGIN PRIVATE KEY-----', '-----END PRIVATE KEY-----', "\n"],
            '',
            $keys['private']
        );

        $signer = new DkimSigner($rawKey, 'default', 'example.com');

        // Only supply two of the five canonical headers
        $headers = [
            'From'    => 'sender@example.com',
            'Subject' => 'Partial test',
        ];

        $signature = $signer->sign($headers, 'Hello!');

        $this->assertStringStartsWith('DKIM-Signature:', $signature);
        $this->assertStringContainsString('bh=', $signature);
        $this->assertStringContainsString('b=', $signature);
    }

    #[Test]
    #[TestDox('sign canonicalises body correctly (strips trailing CRLF and appends one)')]
    public function test_sign_with_various_body_line_endings(): void
    {
        if (!extension_loaded('openssl')) {
            $this->markTestSkipped('OpenSSL extension not available');
        }

        $keys   = DkimSigner::generateKeys(1024);
        $rawKey = str_replace(
            ['-----BEGIN PRIVATE KEY-----', '-----END PRIVATE KEY-----', "\n"],
            '',
            $keys['private']
        );

        $signer = new DkimSigner($rawKey, 'sel', 'example.com');

        // Body with mixed line endings — should still produce a valid signature
        $body = "Line one\r\nLine two\nLine three\r\n\r\n\r\n";

        $signature = $signer->sign(['From' => 'a@b.com'], $body);

        $this->assertStringStartsWith('DKIM-Signature:', $signature);
    }
}
