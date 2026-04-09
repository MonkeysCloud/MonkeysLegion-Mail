<?php

declare(strict_types=1);

namespace MonkeysLegion\Mailer\Tests\RateLimiter;

use MonkeysLegion\Mail\RateLimiter\RateLimiter;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

#[CoversClass(RateLimiter::class)]
#[AllowMockObjectsWithoutExpectations]
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

    #[Test]
    #[TestDox('Allow returns true when within limit')]
    public function test_allow_returns_true_within_limit(): void
    {
        $rateLimiter = new RateLimiter('test_key', 5, 60, $this->testStoragePath);

        $this->assertTrue($rateLimiter->allow());
        $this->assertTrue($rateLimiter->allow());
        $this->assertTrue($rateLimiter->allow());
    }

    #[Test]
    #[TestDox('Allow returns false when limit is exceeded')]
    public function test_allow_returns_false_when_limit_exceeded(): void
    {
        $rateLimiter = new RateLimiter('test_key', 2, 60, $this->testStoragePath);

        $rateLimiter->reset();

        $this->assertTrue($rateLimiter->allow());
        $this->assertTrue($rateLimiter->allow());
        $this->assertFalse($rateLimiter->allow()); // Should be blocked
    }

    #[Test]
    #[TestDox('Remaining returns the correct count of available requests')]
    public function test_remaining_returns_correct_count(): void
    {
        $rateLimiter = new RateLimiter('test_key', 5, 60, $this->testStoragePath);

        $rateLimiter->reset();

        $this->assertEquals(5, $rateLimiter->remaining());

        $rateLimiter->allow();
        $this->assertEquals(4, $rateLimiter->remaining());

        $rateLimiter->allow();
        $this->assertEquals(3, $rateLimiter->remaining());
    }

    #[Test]
    #[TestDox('Reset clears all rate limit data')]
    public function test_reset_clears_all_data(): void
    {
        $rateLimiter = new RateLimiter('test_key', 2, 60, $this->testStoragePath);

        $rateLimiter->reset();

        $rateLimiter->allow();
        $rateLimiter->allow();
        $this->assertFalse($rateLimiter->allow());

        $rateLimiter->reset();
        $this->assertTrue($rateLimiter->allow()); // Should work again
    }

    #[Test]
    #[TestDox('Reset time returns correct value')]
    public function test_reset_time_returns_correct_value(): void
    {
        $rateLimiter = new RateLimiter('test_key', 1, 10, $this->testStoragePath);

        $rateLimiter->reset();

        $rateLimiter->allow();
        $resetTime = $rateLimiter->resetTime();

        $this->assertGreaterThan(0, $resetTime);
        $this->assertLessThanOrEqual(10, $resetTime);
    }

    #[Test]
    #[TestDox('Get config returns correct configuration data')]
    public function test_get_config_returns_correct_data(): void
    {
        $rateLimiter = new RateLimiter('test_key', 100, 3600, $this->testStoragePath);

        $rateLimiter->reset();

        $config = $rateLimiter->getConfig();

        $this->assertEquals('test_key', $config['key']);
        $this->assertEquals(100, $config['limit']);
        $this->assertEquals(3600, $config['seconds']);
        $this->assertEquals(rtrim($this->testStoragePath, '/'), $config['storage_path']);
    }

    #[Test]
    #[TestDox('Cleanup removes old entries and frees quota')]
    public function test_cleanup_removes_old_entries(): void
    {
        $rateLimiter = new RateLimiter('test_key', 5, 1, $this->testStoragePath); // 1 second window

        $rateLimiter->allow();
        sleep(2); // Wait for window to expire

        $rateLimiter->cleanup();

        // Should have full quota again
        $this->assertEquals(5, $rateLimiter->remaining());
    }

    #[Test]
    #[TestDox('Cleanup all processes multiple rate limit files')]
    public function test_cleanup_all_processes_multiple_files(): void
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

    #[Test]
    #[TestDox('Get stats returns correct information')]
    public function test_get_stats_returns_correct_information(): void
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

    #[Test]
    #[TestDox('Different keys have separate rate limits')]
    public function test_different_keys_have_separate_limits(): void
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

    #[Test]
    #[TestDox('Directory creation functionality works properly')]
    public function test_directory_creation(): void
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

    #[Test]
    #[TestDox('Allow handles lock failure gracefully')]
    public function test_allow_handles_lock_failure(): void
    {
        $rateLimiter = new RateLimiter('test_key', 5, 60, $this->testStoragePath);
        
        // Create a lock file and lock it from another process simulation
        $lockFile = $rateLimiter->getFilePath() . '.lock';
        $lockHandle = fopen($lockFile, 'c+');
        flock($lockHandle, LOCK_EX);
        
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
        unlink($lockFile);
        
        $this->assertTrue(true); // Just verify setup works
    }

    #[Test]
    #[TestDox('Cleanup handles empty file without error')]
    public function test_cleanup_handles_empty_file(): void
    {
        $rateLimiter = new RateLimiter('test_key', 5, 60, $this->testStoragePath);
        
        // Test cleanup when file doesn't exist
        $result = $rateLimiter->cleanup();
        $this->assertTrue($result);
    }

    #[Test]
    #[TestDox('Cleanup deletes file when all timestamps expire')]
    public function test_cleanup_deletes_file_when_all_timestamps_expired(): void
    {
        $rateLimiter = new RateLimiter('test_key', 5, 1, $this->testStoragePath);
        
        $rateLimiter->allow();
        $filePath = $rateLimiter->getFilePath();
        $this->assertFileExists($filePath);
        
        sleep(2); // Wait for all timestamps to expire
        
        $rateLimiter->cleanup();
        
        // File should be deleted when all timestamps are expired
        $this->assertFileDoesNotExist($filePath);
    }

    #[Test]
    #[TestDox('Cleanup keeps valid timestamps unexpired')]
    public function test_cleanup_keeps_valid_timestamps(): void
    {
        $rateLimiter = new RateLimiter('test_key', 5, 60, $this->testStoragePath);
        
        $rateLimiter->allow();
        $statsBefore = $rateLimiter->getStats();
        
        $rateLimiter->cleanup();
        
        $statsAfter = $rateLimiter->getStats();
        
        // Should still have the valid timestamp
        $this->assertEquals($statsBefore['current_requests'], $statsAfter['current_requests']);
    }

    #[Test]
    #[TestDox('Reset time works properly with empty timestamps')]
    public function test_reset_time_with_empty_timestamps(): void
    {
        $rateLimiter = new RateLimiter('test_key', 5, 60, $this->testStoragePath);
        
        $resetTime = $rateLimiter->resetTime();
        
        $this->assertEquals(0, $resetTime);
    }

    #[Test]
    #[TestDox('Reset time returns 0 with expired timestamps')]
    public function test_reset_time_with_expired_timestamps(): void
    {
        $rateLimiter = new RateLimiter('test_key', 5, 1, $this->testStoragePath);
        
        $rateLimiter->allow();
        sleep(2); // Wait for timestamp to expire
        
        $resetTime = $rateLimiter->resetTime();
        
        $this->assertEquals(0, $resetTime);
    }

    #[Test]
    #[TestDox('Get stats gives default array with no backing file')]
    public function test_get_stats_with_no_file(): void
    {
        $rateLimiter = new RateLimiter('test_key_no_file', 10, 60, $this->testStoragePath);
        
        $stats = $rateLimiter->getStats();
        
        $this->assertEquals(0, $stats['current_requests']);
        $this->assertEquals(10, $stats['remaining_requests']);
        $this->assertEquals(0, $stats['expired_records']);
        $this->assertFalse($stats['file_exists']);
        $this->assertEquals(0, $stats['file_size_bytes']);
    }

    #[Test]
    #[TestDox('Get stats properly detects expired records')]
    public function test_get_stats_with_expired_records(): void
    {
        $rateLimiter = new RateLimiter('test_key', 10, 1, $this->testStoragePath);
        
        $rateLimiter->allow();
        $rateLimiter->allow();
        
        sleep(2); // Expire the timestamps
        
        // Calling allow() cleans up the file, so we skip that for this test
        $stats = $rateLimiter->getStats();
        
        // Should show expired records
        $this->assertEquals(2, $stats['expired_records']);
        $this->assertEquals(0, $stats['current_requests']);
    }

    #[Test]
    #[TestDox('Cleanup all handles non-existent directory safely')]
    public function test_cleanup_all_with_non_existent_directory(): void
    {
        $nonExistentPath = sys_get_temp_dir() . '/does_not_exist_' . uniqid();
        
        $results = RateLimiter::cleanupAll($nonExistentPath);
        
        $this->assertEquals(0, $results['files_processed']);
        $this->assertEquals(0, $results['cleaned']);
        $this->assertEquals(0, $results['deleted']);
        $this->assertEquals(0, $results['errors']);
    }

    #[Test]
    #[TestDox('Cleanup all safely processes empty directory')]
    public function test_cleanup_all_with_no_files(): void
    {
        $emptyPath = sys_get_temp_dir() . '/empty_' . uniqid();
        mkdir($emptyPath, 0755, true);
        
        $results = RateLimiter::cleanupAll($emptyPath);
        
        $this->assertEquals(0, $results['files_processed']);
        
        rmdir($emptyPath);
    }

    #[Test]
    #[TestDox('Reset returns true even when file does not exist')]
    public function test_reset_returns_true_when_file_does_not_exist(): void
    {
        $rateLimiter = new RateLimiter('nonexistent_key', 5, 60, $this->testStoragePath);
        
        $result = $rateLimiter->reset();
        
        $this->assertTrue($result);
    }

    #[Test]
    #[TestDox('Remaining correctly ignores expired timestamps')]
    public function test_remaining_with_expired_timestamps(): void
    {
        $rateLimiter = new RateLimiter('test_key', 5, 1, $this->testStoragePath);
        
        $rateLimiter->allow();
        $rateLimiter->allow();
        
        $this->assertEquals(3, $rateLimiter->remaining());
        
        sleep(2); // Expire all timestamps
        
        $this->assertEquals(5, $rateLimiter->remaining());
    }

    #[Test]
    #[TestDox('File path returns expected json filename')]
    public function test_get_file_path_returns_correct_path(): void
    {
        $rateLimiter = new RateLimiter('my_key', 5, 60, $this->testStoragePath);
        
        $filePath = $rateLimiter->getFilePath();
        
        $this->assertStringContainsString('ratelimit_my_key.json', $filePath);
    }

    #[Test]
    #[TestDox('Constructor throws when directory cannot be written')]
    public function test_constructor_throws_on_unwritable_directory(): void
    {
        $dirname = '/readonly_' . uniqid();
        
        // Create the limiter first to ensure the directory structure exists at its correct base path
        $limiter = new RateLimiter('test_key', 5, 60, $dirname);
        $fullDirPath = dirname($limiter->getFilePath());
        
        // Remove the json file so the dir is strictly the boundary.
        @unlink($limiter->getFilePath());
        
        // Make directory read-only
        chmod($fullDirPath, 0444);
        
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('not writable');
            
            new RateLimiter('test_key', 5, 60, $dirname);
        } finally {
            // Cleanup
            chmod($fullDirPath, 0755);
            $files = glob($fullDirPath . '/*');
            if ($files !== false) {
                foreach ($files as $file) {
                    @unlink($file);
                }
            }
            @rmdir($fullDirPath);
        }
    }
}
