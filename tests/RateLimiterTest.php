<?php

namespace MonkeysLegion\Mailer\Tests;

use MonkeysLegion\Mail\RateLimiter\RateLimiter;
use PHPUnit\Framework\TestCase;

use function PHPUnit\Framework\directoryExists;

class RateLimiterTest extends TestCase
{
    private string $testStoragePath;

    protected function setUp(): void
    {
        $this->testStoragePath = sys_get_temp_dir() . '/rate_limiter_tests_' . uniqid();
        if (!is_dir($this->testStoragePath)) {
            mkdir($this->testStoragePath, 0755, true);
        }

        // Clean up any existing test files
        $files = glob($this->testStoragePath . '/ratelimit_*');
        if ($files !== false) {
            foreach ($files as $file) {
                if (file_exists($file)) {
                    unlink($file);
                }
            }
        }
    }

    protected function tearDown(): void
    {
        // Clean up test files
        $files = glob($this->testStoragePath . '/ratelimit_*');
        if ($files !== false) {
            foreach ($files as $file) {
                if (file_exists($file)) {
                    unlink($file);
                }
            }
        }

        // Remove the test directory
        if (is_dir($this->testStoragePath)) {
            rmdir($this->testStoragePath);
        }
    }

    public function testAllowReturnsTrueWithinLimit(): void
    {
        $rateLimiter = new RateLimiter('test_key', 5, 60, $this->testStoragePath);

        $this->assertTrue($rateLimiter->allow());
        $this->assertTrue($rateLimiter->allow());
        $this->assertTrue($rateLimiter->allow());
    }

    public function testAllowReturnsFalseWhenLimitExceeded(): void
    {
        $rateLimiter = new RateLimiter('test_key', 2, 60, $this->testStoragePath);

        $rateLimiter->reset();

        $this->assertTrue($rateLimiter->allow());
        $this->assertTrue($rateLimiter->allow());
        $this->assertFalse($rateLimiter->allow()); // Should be blocked
    }

    public function testRemainingReturnsCorrectCount(): void
    {
        $rateLimiter = new RateLimiter('test_key', 5, 60, $this->testStoragePath);

        $rateLimiter->reset();

        $this->assertEquals(5, $rateLimiter->remaining());

        $rateLimiter->allow();
        $this->assertEquals(4, $rateLimiter->remaining());

        $rateLimiter->allow();
        $this->assertEquals(3, $rateLimiter->remaining());
    }

    public function testResetClearsAllData(): void
    {
        $rateLimiter = new RateLimiter('test_key', 2, 60, $this->testStoragePath);

        $rateLimiter->reset();

        $rateLimiter->allow();
        $rateLimiter->allow();
        $this->assertFalse($rateLimiter->allow());

        $rateLimiter->reset();
        $this->assertTrue($rateLimiter->allow()); // Should work again
    }

    public function testResetTimeReturnsCorrectValue(): void
    {
        $rateLimiter = new RateLimiter('test_key', 1, 10, $this->testStoragePath);

        $rateLimiter->reset();

        $rateLimiter->allow();
        $resetTime = $rateLimiter->resetTime();

        $this->assertGreaterThan(0, $resetTime);
        $this->assertLessThanOrEqual(10, $resetTime);
    }

    public function testGetConfigReturnsCorrectData(): void
    {
        $rateLimiter = new RateLimiter('test_key', 100, 3600, $this->testStoragePath);

        $rateLimiter->reset();

        $config = $rateLimiter->getConfig();

        $this->assertEquals('test_key', $config['key']);
        $this->assertEquals(100, $config['limit']);
        $this->assertEquals(3600, $config['seconds']);
        $this->assertEquals(rtrim($this->testStoragePath, '/'), $config['storage_path']);
    }

    public function testCleanupRemovesOldEntries(): void
    {
        $rateLimiter = new RateLimiter('test_key', 5, 1, $this->testStoragePath); // 1 second window

        $rateLimiter->allow();
        sleep(2); // Wait for window to expire

        $rateLimiter->cleanup();

        // Should have full quota again
        $this->assertEquals(5, $rateLimiter->remaining());
    }

    public function testCleanupAllProcessesMultipleFiles(): void
    {
        $rateLimiter1 = new RateLimiter('key1', 5, 1, $this->testStoragePath);
        $rateLimiter2 = new RateLimiter('key2', 5, 1, $this->testStoragePath);

        $rateLimiter1->allow();
        $rateLimiter2->allow();

        sleep(2); // Wait for windows to expire

        $results = RateLimiter::cleanupAll($this->testStoragePath);

        $this->assertArrayHasKey('cleaned', $results);
        $this->assertArrayHasKey('deleted', $results);
        $this->assertArrayHasKey('errors', $results);
        $this->assertArrayHasKey('files_processed', $results);
    }

    public function testGetStatsReturnsCorrectInformation(): void
    {
        $rateLimiter = new RateLimiter('test_key', 10, 60, $this->testStoragePath);

        $rateLimiter->allow();
        $rateLimiter->allow();

        $stats = $rateLimiter->getStats();

        $this->assertEquals('test_key', $stats['key']);
        $this->assertEquals(10, $stats['limit']);
        $this->assertEquals(60, $stats['window_seconds']);
        $this->assertEquals(2, $stats['current_requests']);
        $this->assertEquals(8, $stats['remaining_requests']);
        $this->assertTrue($stats['file_exists']);
    }

    public function testDifferentKeysHaveSeparateLimits(): void
    {
        $rateLimiter1 = new RateLimiter('key1', 2, 60, $this->testStoragePath);
        $rateLimiter2 = new RateLimiter('key2', 2, 60, $this->testStoragePath);

        $rateLimiter1->reset();
        $rateLimiter2->reset();

        $this->assertTrue($rateLimiter1->allow());
        $this->assertTrue($rateLimiter1->allow());
        $this->assertFalse($rateLimiter1->allow());

        // key2 should still have full quota
        $this->assertTrue($rateLimiter2->allow());
        $this->assertTrue($rateLimiter2->allow());
        $this->assertFalse($rateLimiter2->allow());
    }

    public function testDirectoryCreation(): void
    {
        $nonExistentPath = sys_get_temp_dir() . '/non_existent_' . uniqid();

        $rateLimiter = new RateLimiter('test_key', 5, 60, $nonExistentPath);

        $rateLimiter->reset();

        $filePath = $rateLimiter->getFilePath();

        $this->assertTrue($rateLimiter->allow());
        $this->assertTrue(is_dir(dirname($filePath)));

        // Cleanup
        $files = glob(dirname($filePath) . '/ratelimit_*');
        if ($files !== false) {
            foreach ($files as $file) {
                unlink($file);
            }
        }
        rmdir(dirname($filePath));
    }
}
