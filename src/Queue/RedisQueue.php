<?php

declare(strict_types=1);

namespace MonkeysLegion\Mail\Queue;

use Redis;
use RedisException;
use MonkeysLegion\Mail\Event\MessageQueued;

class RedisQueue implements QueueInterface
{
    private Redis $redis;
    private string $defaultQueue;
    private string $keyPrefix;

    public function __construct(
        string $host = '127.0.0.1',
        int $port = 6379,
        string $defaultQueue = 'default',
        string $keyPrefix = 'queue:'
    ) {
        $this->redis = new Redis();
        $this->defaultQueue = $defaultQueue;
        $this->keyPrefix = $keyPrefix;

        $this->connect($host, $port);
    }

    private function connect(string $host, int $port): void
    {
        try {
            $connected = $this->redis->connect($host, $port, 30);
            if (!$connected) {
                throw new \RuntimeException("Failed to connect to Redis at {$host}:{$port}");
            }
        } catch (RedisException $e) {
            throw new \RuntimeException("Redis connection error: " . $e->getMessage(), 0, $e);
        }
    }

    public function push(string $job, array $data = [], ?string $queue = null): mixed
    {
        $queue = $queue ?? $this->defaultQueue;
        $queueKey = $this->keyPrefix . $queue;

        $jobData = [
            'id' => uniqid('job_', true),
            'job' => $job,
            'data' => $data,
            'attempts' => 0,
            'created_at' => microtime(true),
        ];

        try {
            $result = $this->redis->rPush($queueKey, json_encode($jobData));

            if ($result) {
                // Create and log queued event
                $queuedEvent = new MessageQueued($jobData['id'], $jobData);
                error_log("MessageQueued: Job {$jobData['id']} queued successfully");

                return $jobData['id'];
            }

            return false;
        } catch (RedisException $e) {
            throw new \RuntimeException("Failed to push job to queue: " . $e->getMessage(), 0, $e);
        }
    }

    public function pop(?string $queue = null): ?JobInterface
    {
        $queue = $queue ?? $this->defaultQueue;
        $queueKey = $this->keyPrefix . $queue;

        try {
            $jobJson = $this->redis->lPop($queueKey);
            if (!$jobJson) {
                return null;
            }

            $jobData = json_decode($jobJson, true);
            if (!$jobData) {
                return null;
            }

            return new Job($jobData, $this);
        } catch (RedisException $e) {
            throw new \RuntimeException("Failed to pop job from queue: " . $e->getMessage(), 0, $e);
        }
    }

    public function size(?string $queue = null): int
    {
        $queue = $queue ?? $this->defaultQueue;
        $queueKey = $this->keyPrefix . $queue;

        try {
            return $this->redis->lLen($queueKey);
        } catch (RedisException $e) {
            throw new \RuntimeException("Failed to get queue size: " . $e->getMessage(), 0, $e);
        }
    }

    public function clear(?string $queue = null): bool
    {
        $queue = $queue ?? $this->defaultQueue;
        $queueKey = $this->keyPrefix . $queue;

        try {
            return $this->redis->del($queueKey) > 0;
        } catch (RedisException $e) {
            throw new \RuntimeException("Failed to clear queue: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Get the Redis instance for advanced operations.
     *
     * @return Redis
     */
    public function getRedis(): Redis
    {
        return $this->redis;
    }

    /**
     * Close the Redis connection.
     *
     * @return void
     */
    public function disconnect(): void
    {
        try {
            $this->redis->close();
        } catch (RedisException $e) {
            // Ignore close errors
        }
    }

    public function __destruct()
    {
        $this->disconnect();
    }

    /**
     * Push a job to the failed queue
     * 
     * @param array $jobData Original job data
     * @param \Exception $exception The exception that caused the failure
     * @return bool Success status
     */
    public function pushToFailed(array $jobData, \Exception $exception): bool
    {
        $failedKey = $this->keyPrefix . 'failed';

        $failedJobData = [
            'id' => $jobData['id'] ?? uniqid('failed_', true),
            'original_job' => $jobData,
            'exception' => [
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString()
            ],
            'failed_at' => microtime(true),
        ];

        try {
            return $this->redis->rPush($failedKey, json_encode($failedJobData)) > 0;
        } catch (RedisException $e) {
            error_log("Failed to push job to failed queue: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get failed jobs
     * 
     * @param int $limit Maximum number of jobs to retrieve
     * @return array Array of failed jobs
     */
    public function getFailedJobs(int $limit = 100): array
    {
        $failedKey = $this->keyPrefix . 'failed';

        try {
            $jobs = $this->redis->lRange($failedKey, 0, $limit - 1);
            return array_map(fn($job) => json_decode($job, true), $jobs);
        } catch (RedisException $e) {
            throw new \RuntimeException("Failed to get failed jobs: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Retry a failed job by moving it back to the main queue
     * 
     * @param string $jobId The failed job ID
     * @return bool Success status
     */
    public function retryFailedJob(string $jobId): bool
    {
        $failedKey = $this->keyPrefix . 'failed';

        try {
            $failedJobs = $this->redis->lRange($failedKey, 0, -1);

            foreach ($failedJobs as $index => $jobJson) {
                $failedJobData = json_decode($jobJson, true);

                if ($failedJobData['id'] === $jobId) {
                    // Remove from failed queue
                    $this->redis->lRem($failedKey, $jobJson, 1);

                    // Add back to main queue with incremented attempts
                    $originalJob = $failedJobData['original_job'];
                    $originalJob['attempts'] = ($originalJob['attempts'] ?? 0) + 1;
                    $originalJob['retried_at'] = microtime(true);

                    return $this->push(
                        $originalJob['job'],
                        $originalJob['data'],
                        $this->defaultQueue
                    ) !== false;
                }
            }

            return false; // Job not found

        } catch (RedisException $e) {
            throw new \RuntimeException("Failed to retry job: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Clear failed jobs
     * 
     * @return bool Success status
     */
    public function clearFailedJobs(): bool
    {
        $failedKey = $this->keyPrefix . 'failed';

        try {
            return $this->redis->del($failedKey) >= 0;
        } catch (RedisException $e) {
            throw new \RuntimeException("Failed to clear failed jobs: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Get failed jobs count
     * 
     * @return int Number of failed jobs
     */
    public function getFailedJobsCount(): int
    {
        $failedKey = $this->keyPrefix . 'failed';

        try {
            return $this->redis->lLen($failedKey);
        } catch (RedisException $e) {
            return 0;
        }
    }

    /**
     * Get the default queue name
     *
     * @return string
     */
    public function getDefaultQueue(): string
    {
        return $this->defaultQueue;
    }
}
