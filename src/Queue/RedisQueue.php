<?php

declare(strict_types=1);

namespace MonkeysLegion\Mail\Queue;

use Redis;
use RedisException;
use MonkeysLegion\Mail\Event\MessageQueued;
use MonkeysLegion\Mail\Logger\Logger;
use MonkeysLegion\Mail\Message;
use MonkeysLegion\Mail\Service\ServiceContainer;

class RedisQueue implements QueueInterface
{
    private Redis $redis;
    private Logger $logger;
    private ServiceContainer $container;

    public function __construct(
        private string $host = '127.0.0.1',
        private int $port = 6379,
        private string $defaultQueue = 'default',
        private string $keyPrefix = 'queue:'
    ) {
        $this->redis = new Redis();
        $this->container = ServiceContainer::getInstance();
        $this->logger = $this->container->get(Logger::class);
        $this->connect($host, $port);
    }

    private function connect(string $host, int $port): void
    {
        try {
            $this->logger->log("Attempting to connect to Redis", [
                'host' => $host,
                'port' => $port
            ]);

            $connected = $this->redis->connect($host, $port, 30);
            if (!$connected) {
                $this->logger->log("Failed to connect to Redis", [
                    'host' => $host,
                    'port' => $port
                ]);
                throw new \RuntimeException("Failed to connect to Redis at {$host}:{$port}");
            }

            $this->logger->log("Successfully connected to Redis", [
                'host' => $host,
                'port' => $port
            ]);
        } catch (RedisException $e) {
            $this->logger->log("Redis connection error", [
                'host' => $host,
                'port' => $port,
                'exception' => $e,
                'error_message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw new \RuntimeException("Redis connection error: " . $e->getMessage(), 0, $e);
        }
    }

    public function push(string $job, Message $message, ?string $queue = null): mixed
    {
        $queue = $queue ?? $this->defaultQueue;
        $queueKey = $this->keyPrefix . $queue;

        // Serialize Message object for storage
        $jobData = [
            'id' => uniqid('job_', true),
            'job' => $job,
            'message' => serialize($message),
            'attempts' => 0,
            'created_at' => microtime(true),
        ];

        $this->logger->log("Pushing job to queue", [
            'job_id' => $jobData['id'],
            'job_class' => $job,
            'queue' => $queue,
            'queue_key' => $queueKey,
            'message_to' => $message->getTo(),
            'message_subject' => $message->getSubject()
        ]);

        try {
            $result = $this->redis->rPush($queueKey, json_encode($jobData));

            if ($result) {
                // Create event - logging is handled inside event constructor
                $queuedEvent = new MessageQueued($jobData['id'], $jobData, $this->logger);

                $this->logger->log("Job pushed successfully", [
                    'job_id' => $jobData['id'],
                    'queue' => $queue,
                    'queue_size' => $result
                ]);

                return $jobData['id'];
            }

            $this->logger->log("Failed to push job - Redis returned false", [
                'job_id' => $jobData['id'],
                'queue' => $queue
            ]);

            return false;
        } catch (RedisException $e) {
            $this->logger->log("Redis exception while pushing job", [
                'job_id' => $jobData['id'],
                'queue' => $queue,
                'exception' => $e,
                'error_message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw new \RuntimeException("Failed to push job to queue: " . $e->getMessage(), 0, $e);
        }
    }

    public function pop(?string $queue = null): ?JobInterface
    {
        $queue = $queue ?? $this->defaultQueue;
        $queueKey = $this->keyPrefix . $queue;

        $this->logger->log("Attempting to pop job from queue", [
            'queue' => $queue,
            'queue_key' => $queueKey
        ]);

        try {
            $jobJson = $this->redis->lPop($queueKey);
            if (!$jobJson) {
                $this->logger->log("No jobs available in queue", [
                    'queue' => $queue
                ]);
                return null;
            }

            $jobData = json_decode($jobJson, true);
            if (!$jobData) {
                $this->logger->log("Failed to decode job JSON", [
                    'queue' => $queue,
                    'job_json' => $jobJson
                ]);
                return null;
            }

            $jobData['message'] = unserialize($jobData['message'] ?? '');
            echo $jobData['message'] instanceof Message ? "Message deserialized successfully.\n" : "Failed to deserialize message.\n";

            $this->logger->log("Job popped successfully", [
                'job_id' => $jobData['id'] ?? 'unknown',
                'job_class' => $jobData['job'] ?? 'unknown',
                'queue' => $queue,
                'attempts' => $jobData['attempts'] ?? 0
            ]);

            return new Job($jobData, $this, $this->logger);
        } catch (RedisException $e) {
            $this->logger->log("Redis exception while popping job", [
                'queue' => $queue,
                'exception' => $e,
                'error_message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw new \RuntimeException("Failed to pop job from queue: " . $e->getMessage(), 0, $e);
        }
    }

    public function size(?string $queue = null): int
    {
        $queue = $queue ?? $this->defaultQueue;
        $queueKey = $this->keyPrefix . $queue;

        try {
            $size = $this->redis->lLen($queueKey);

            $this->logger->log("Queue size retrieved", [
                'queue' => $queue,
                'size' => $size
            ]);

            return $size;
        } catch (RedisException $e) {
            $this->logger->log("Redis exception while getting queue size", [
                'queue' => $queue,
                'exception' => $e,
                'error_message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw new \RuntimeException("Failed to get queue size: " . $e->getMessage(), 0, $e);
        }
    }

    public function clear(?string $queue = null): bool
    {
        $queue = $queue ?? $this->defaultQueue;
        $queueKey = $this->keyPrefix . $queue;

        $this->logger->log("Clearing queue", [
            'queue' => $queue,
            'queue_key' => $queueKey
        ]);

        try {
            $result = $this->redis->del($queueKey) > 0;

            $this->logger->log($result ? "Queue cleared successfully" : "Queue was already empty", [
                'queue' => $queue,
                'success' => $result
            ]);

            return $result;
        } catch (RedisException $e) {
            $this->logger->log("Redis exception while clearing queue", [
                'queue' => $queue,
                'exception' => $e,
                'error_message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
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
            $this->logger->log("Disconnecting from Redis");
            $this->redis->close();
            $this->logger->log("Redis connection closed successfully");
        } catch (RedisException $e) {
            $this->logger->log("Error while disconnecting from Redis", [
                'exception' => $e,
                'error_message' => $e->getMessage()
            ]);
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

        $this->logger->log("Pushing job to failed queue", [
            'job_id' => $failedJobData['id'],
            'original_job_class' => $jobData['job'] ?? 'unknown',
            'attempts' => $jobData['attempts'] ?? 0,
            'error_message' => $exception->getMessage()
        ]);

        try {
            $result = $this->redis->rPush($failedKey, json_encode($failedJobData)) > 0;

            if ($result) {
                $this->logger->log("Job pushed to failed queue successfully", [
                    'job_id' => $failedJobData['id']
                ]);
            } else {
                $this->logger->log("Failed to push job to failed queue", [
                    'job_id' => $failedJobData['id']
                ]);
            }

            return $result;
        } catch (RedisException $e) {
            $this->logger->log("Redis exception while pushing to failed queue", [
                'job_id' => $failedJobData['id'],
                'exception' => $e,
                'error_message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
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

        $this->logger->log("Retrieving failed jobs", [
            'limit' => $limit
        ]);

        try {
            $jobs = $this->redis->lRange($failedKey, 0, $limit - 1);
            $decodedJobs = array_map(fn($job) => json_decode($job, true), $jobs);

            $this->logger->log("Failed jobs retrieved", [
                'count' => count($decodedJobs),
                'limit' => $limit
            ]);

            return $decodedJobs;
        } catch (RedisException $e) {
            $this->logger->log("Redis exception while getting failed jobs", [
                'limit' => $limit,
                'exception' => $e,
                'error_message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
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

        $this->logger->log("Attempting to retry failed job", [
            'job_id' => $jobId
        ]);

        try {
            $failedJobs = $this->redis->lRange($failedKey, 0, -1);

            foreach ($failedJobs as $index => $jobJson) {
                $failedJobData = json_decode($jobJson, true);

                if ($failedJobData['id'] === $jobId) {
                    // Remove from failed queue
                    $this->redis->lRem($failedKey, $jobJson, 1);

                    // Get the original job data
                    $originalJob = $failedJobData['original_job'];
                    
                    // Extract the Message object from the original job data
                    $message = $originalJob['message']; // This should be the serialized Message

                    // Add back to main queue with incremented attempts using the same format as push()
                    $retryJobData = [
                        'id' => $originalJob['id'], // Keep original job ID
                        'job' => $originalJob['job'],
                        'message' => $message, // Keep the serialized Message object
                        'attempts' => ($originalJob['attempts'] ?? 0) + 1,
                        'created_at' => $originalJob['created_at'], // Keep original creation time
                        'retried_at' => microtime(true), // Add retry timestamp
                    ];

                    // Push directly to the main queue
                    $queueKey = $this->keyPrefix . $this->defaultQueue;
                    $result = $this->redis->rPush($queueKey, json_encode($retryJobData)) > 0;

                    $this->logger->log($result ? "Failed job retried successfully" : "Failed to retry job", [
                        'job_id' => $jobId,
                        'new_attempts' => $retryJobData['attempts'],
                        'success' => $result
                    ]);

                    return $result;
                }
            }

            $this->logger->log("Failed job not found for retry", [
                'job_id' => $jobId
            ]);

            return false; // Job not found

        } catch (RedisException $e) {
            $this->logger->log("Redis exception while retrying job", [
                'job_id' => $jobId,
                'exception' => $e,
                'error_message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
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

        $this->logger->log("Clearing failed jobs");

        try {
            $result = $this->redis->del($failedKey) >= 0;

            $this->logger->log($result ? "Failed jobs cleared successfully" : "Failed jobs were already empty", [
                'success' => $result
            ]);

            return $result;
        } catch (RedisException $e) {
            $this->logger->log("Redis exception while clearing failed jobs", [
                'exception' => $e,
                'error_message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
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
            $count = $this->redis->lLen($failedKey);

            $this->logger->log("Failed jobs count retrieved", [
                'count' => $count
            ]);

            return $count;
        } catch (RedisException $e) {
            $this->logger->log("Redis exception while getting failed jobs count", [
                'exception' => $e,
                'error_message' => $e->getMessage()
            ]);
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
        return $this->defaultQueue;
    }
}
