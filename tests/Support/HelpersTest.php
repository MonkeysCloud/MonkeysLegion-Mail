<?php

declare(strict_types=1);

namespace MonkeysLegion\Mailer\Tests\Support;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class HelpersTest extends TestCase
{
    private string $tempDir;
    private string $dummyFile;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->tempDir = sys_get_temp_dir() . '/ml_mail_test_' . uniqid();
        mkdir($this->tempDir, 0777, true);
        
        $this->dummyFile = $this->tempDir . '/dummy.txt';
        file_put_contents($this->dummyFile, 'dummy content');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->dummyFile)) {
            unlink($this->dummyFile);
        }
        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
        parent::tearDown();
    }

    #[Test]
    #[TestDox('safeString returns string representation for scalar values')]
    public function test_safestring_with_scalars(): void
    {
        $this->assertSame('123', \safeString(123));
        $this->assertSame('123.45', \safeString(123.45));
        $this->assertSame('1', \safeString(true));
        $this->assertSame('', \safeString(false));
        $this->assertSame('abc', \safeString('abc'));
    }

    #[Test]
    #[TestDox('safeString returns default for non-scalar values')]
    public function test_safestring_with_non_scalars(): void
    {
        $this->assertSame('', \safeString([]));
        $this->assertSame('def', \safeString(new \stdClass(), 'def'));
        $this->assertSame('def', \safeString(null, 'def'));
    }

    #[Test]
    #[TestDox('normalizeAttachment standardizes local file array format')]
    public function test_normalize_attachment_array(): void
    {
        $attachment = [
            'path' => 'dummy.txt',
            'name' => 'custom.txt',
            'mime_type' => 'text/plain'
        ];

        $result = \normalizeAttachment($attachment, $this->tempDir);

        $this->assertSame('dummy.txt', $result['path']);
        $this->assertSame('custom.txt', $result['filename']);
        $this->assertSame('text/plain', $result['mime_type']);
        $this->assertFalse($result['is_url']);
        $this->assertSame($this->dummyFile, $result['full_path']);
        $this->assertSame(base64_encode('dummy content'), $result['base64']);
        $this->assertStringContainsString('Content-Type: text/plain', $result['boundary_encoded']);
    }

    #[Test]
    #[TestDox('normalizeAttachment processes string path resolving to baseDir')]
    public function test_normalize_attachment_string(): void
    {
        // For testing we will just pass absolute path and null base string, or
        // relative path and explicit base.
        $result = \normalizeAttachment('dummy.txt', $this->tempDir);

        $this->assertSame('dummy.txt', $result['path']);
        $this->assertSame('dummy.txt', $result['filename']);
        $this->assertSame('text/plain', $result['mime_type']);
    }

    #[Test]
    #[TestDox('normalizeAttachment handles URL resources')]
    public function test_normalize_attachment_url(): void
    {
        // Mock file_get_contents for HTTP
        if (!ini_get('allow_url_fopen')) {
            $this->markTestSkipped('allow_url_fopen is disabled');
        }

        // We use php://memory or an internal PHP function mock in actual usage, but
        // trying a known online or just using an error path or using error control:
        // Actually since normalizeAttachment suppresses error, let's trigger the read fail.
        $attachment = [
            'path' => 'http://nonexistent.domain.test/file.txt',
            'name' => 'remote.txt',
            'mime_type' => 'text/html'
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to read attachment data');
        
        \normalizeAttachment($attachment);
    }

    #[Test]
    #[TestDox('normalizeAttachment correctly handles forCurl flag')]
    public function test_normalize_attachment_for_curl(): void
    {
        $result = \normalizeAttachment('dummy.txt', $this->tempDir, true);

        $this->assertArrayHasKey('path', $result);
        $this->assertArrayHasKey('full_path', $result);
        $this->assertArrayNotHasKey('base64', $result);
        $this->assertArrayNotHasKey('boundary_encoded', $result);
    }

    #[Test]
    #[TestDox('normalizeAttachment throws exception on empty path')]
    public function test_normalize_attachment_empty_path(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Attachment path is missing');
        \normalizeAttachment('');
    }

    #[Test]
    #[TestDox('normalizeAttachment throws exception on unreadable file')]
    public function test_normalize_attachment_unreadable_file(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Attachment file not found or unreadable');
        \normalizeAttachment('non-existent.txt', $this->tempDir);
    }
}
