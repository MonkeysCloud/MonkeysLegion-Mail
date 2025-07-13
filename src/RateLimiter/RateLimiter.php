<?php

namespace MonkeysLegion\Mail\RateLimiter;

class RateLimiter
{
    private string $filePath;

    public function __construct(private string $key, private int $limit, private int $seconds, private string $storagePath = '/tmp')
    {
        $this->storagePath = rtrim($storagePath, '/');
        $this->filePath = WORKING_DIRECTORY . "/storage{$this->storagePath}/ratelimit_{$this->key}.json";
        $this->ensureDirectoryExists();
    }

    public function allow(): bool
    {
        $lockFile = $this->filePath . '.lock';

        // Use file locking to prevent race conditions
        $lockHandle = fopen($lockFile, 'c+');
        if (!$lockHandle || !flock($lockHandle, LOCK_EX)) {
            return false; // Could not acquire lock
        }

        try {
            $timestamps = $this->readTimestamps();
            $now = microtime(true);
            $windowStart = $now - $this->seconds;

            // Filter timestamps within window
            $timestamps = array_filter($timestamps, fn($ts) => $ts >= $windowStart);

            if (count($timestamps) < $this->limit) {
                // Allowed - add current timestamp
                $timestamps[] = $now;
                $this->writeTimestamps($timestamps);
                return true;
            }

            // Rate limit exceeded
            return false;
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
            @unlink($lockFile); // Clean up lock file
        }
    }

    /**
     * Get remaining requests in current window
     */
    public function remaining(): int
    {
        $timestamps = $this->readTimestamps();
        $now = microtime(true);
        $windowStart = $now - $this->seconds;

        // Filter timestamps within window
        $timestamps = array_filter($timestamps, fn($ts) => $ts >= $windowStart);

        return max(0, $this->limit - count($timestamps));
    }

    /**
     * Get seconds until window resets
     */
    public function resetTime(): int
    {
        $timestamps = $this->readTimestamps();
        if (empty($timestamps)) {
            return 0;
        }

        $now = microtime(true);
        $windowStart = $now - $this->seconds;

        // Filter timestamps within window
        $validTimestamps = array_filter($timestamps, fn($ts) => $ts >= $windowStart);

        if (empty($validTimestamps)) {
            return 0;
        }

        // Time until oldest timestamp expires
        $oldestTimestamp = min($validTimestamps);
        return (int) max(0, ($oldestTimestamp + $this->seconds) - $now);
    }

    /**
     * Clear all rate limit data for this key
     */
    public function reset(): bool
    {
        if (file_exists($this->filePath)) {
            return unlink($this->filePath);
        }
        return true;
    }

    /**
     * Clean up old timestamps that are outside the current window
     * This method can be called periodically to keep files small
     */
    public function cleanup(): bool
    {
        $lockFile = $this->filePath . '.lock';

        // Use file locking to prevent race conditions
        $lockHandle = fopen($lockFile, 'c+');
        if (!$lockHandle || !flock($lockHandle, LOCK_EX)) {
            return false; // Could not acquire lock
        }

        try {
            $timestamps = $this->readTimestamps();
            if (empty($timestamps)) {
                return true; // Nothing to clean
            }

            $now = microtime(true);
            $windowStart = $now - $this->seconds;

            // Filter timestamps within window (keep only recent ones)
            $validTimestamps = array_filter($timestamps, fn($ts) => $ts >= $windowStart);

            // If we removed old timestamps, write the cleaned data back
            if (count($validTimestamps) < count($timestamps)) {
                if (empty($validTimestamps)) {
                    // No valid timestamps left, delete the file
                    return $this->reset();
                } else {
                    // Write back only the valid timestamps
                    return $this->writeTimestamps(array_values($validTimestamps));
                }
            }

            return true; // No cleanup was needed
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
            @unlink($lockFile); // Clean up lock file
        }
    }

    /**
     * Static method to clean up all rate limiter files in the storage directory
     * This can be called by a scheduled task to clean up old files
     */
    public static function cleanupAll(string $storagePath = '/tmp'): array
    {
        $storagePath = rtrim($storagePath, '/');
        $directory = WORKING_DIRECTORY . "/storage{$storagePath}";
        $results = [
            'cleaned' => 0,
            'deleted' => 0,
            'errors' => 0,
            'files_processed' => 0
        ];

        if (!is_dir($directory)) {
            return $results;
        }

        $files = glob($directory . '/ratelimit_*.json');

        foreach ($files as $file) {
            $results['files_processed']++;

            try {
                // Extract key from filename
                $filename = basename($file);
                if (preg_match('/ratelimit_(.+)\.json$/', $filename, $matches)) {
                    $key = $matches[1];

                    // Create a temporary RateLimiter instance for cleanup
                    // We'll use default values since we only need cleanup functionality
                    $rateLimiter = new self($key, 100, 3600, $storagePath);

                    if ($rateLimiter->cleanup()) {
                        if (!file_exists($file)) {
                            $results['deleted']++;
                        } else {
                            $results['cleaned']++;
                        }
                    } else {
                        $results['errors']++;
                    }
                }
            } catch (\Exception $e) {
                $results['errors']++;
                error_log("RateLimiter cleanup error for file {$file}: " . $e->getMessage());
            }
        }

        return $results;
    }

    /**
     * Get statistics about the current rate limiter state
     */
    public function getStats(): array
    {
        $timestamps = $this->readTimestamps();
        $now = microtime(true);
        $windowStart = $now - $this->seconds;

        // Filter timestamps within window
        $validTimestamps = array_filter($timestamps, fn($ts) => $ts >= $windowStart);
        $expiredCount = count($timestamps) - count($validTimestamps);

        return [
            'key' => $this->key,
            'limit' => $this->limit,
            'window_seconds' => $this->seconds,
            'current_requests' => count($validTimestamps),
            'remaining_requests' => $this->remaining(),
            'expired_records' => $expiredCount,
            'reset_in_seconds' => $this->resetTime(),
            'file_exists' => file_exists($this->filePath),
            'file_size_bytes' => file_exists($this->filePath) ? filesize($this->filePath) : 0,
        ];
    }

    /**
     * Ensure the storage directory exists
     */
    private function ensureDirectoryExists(): void
    {
        $directory = dirname($this->filePath);

        if (!is_dir($directory)) {
            if (!mkdir($directory, 0755, true)) {
                throw new \RuntimeException("Failed to create rate limiter storage directory: {$directory}");
            }
        }

        if (!is_writable($directory)) {
            throw new \RuntimeException("Rate limiter storage directory is not writable: {$directory}");
        }
    }

    /**
     * Read timestamps from file
     */
    private function readTimestamps(): array
    {
        if (!file_exists($this->filePath)) {
            return [];
        }

        $content = file_get_contents($this->filePath);
        if ($content === false) {
            return [];
        }

        $timestamps = json_decode($content, true);
        return is_array($timestamps) ? $timestamps : [];
    }

    /**
     * Write timestamps to file
     */
    private function writeTimestamps(array $timestamps): bool
    {
        $content = json_encode($timestamps, JSON_THROW_ON_ERROR);
        return file_put_contents($this->filePath, $content, LOCK_EX) !== false;
    }

    public function getConfig(): array
    {
        return [
            'key' => $this->key,
            'limit' => $this->limit,
            'seconds' => $this->seconds,
            'storage_path' => $this->storagePath,
        ];
    }

    public function getFilePath(): string
    {
        return $this->filePath;
    }
}
