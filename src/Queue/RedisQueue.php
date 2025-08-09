<?php

declare(strict_types=1);

namespace MonkeysLegion\Mail\Queue;

use MonkeysLegion\Core\Contracts\FrameworkLoggerInterface;
use Redis;
use RedisException;
use MonkeysLegion\Mail\Event\MessageQueued;
use MonkeysLegion\Mail\Message;
use MonkeysLegion\Mail\Service\ServiceContainer;

class RedisQueue implements QueueInterface
{
    private Redis $redis;
    private FrameworkLoggerInterface $logger;
    private ServiceContainer $container;

    public function __construct(
        string $host = '127.0.0.1',
        int $port = 6379,
        private string $defaultQueue = 'default',
        private string $keyPrefix = 'queue:'
    ) {
        $this->redis = new Redis();
        $this->container = ServiceContainer::getInstance();
        /** @var FrameworkLoggerInterface $logger */
        $logger = $this->container->get(FrameworkLoggerInterface::class);
        $this->logger = $logger;
        $this->connect($host, $port);
    }

    private function connect(string $host, int $port): void
    {
        try {
            $this->logger->smartLog("Attempting to connect to Redis", [
                'host' => $host,
                'port' => $port
            ]);

            $connected = $this->redis->connect($host, $port, 30);
            if (!$connected) {
                $this->logger->error("Failed to connect to Redis", [
                    'host' => $host,
                    'port' => $port
                ]);
                throw new \RuntimeException("Failed to connect to Redis at {$host}:{$port}");
            }

            $this->logger->smartLog("Successfully connected to Redis", [
                'host' => $host,
                'port' => $port
            ]);
        } catch (RedisException $e) {
            $this->logger->error("Redis connection error", [
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

        $this->logger->smartLog("Pushing job to queue", [
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
                new MessageQueued($jobData['id'], $jobData, $this->logger);

                $this->logger->smartLog("Job pushed successfully", [
                    'job_id' => $jobData['id'],
                    'queue' => $queue,
                    'queue_size' => $result
                ]);

                return $jobData['id'];
            }

            $this->logger->warning("Failed to push job - Redis returned false", [
                'job_id' => $jobData['id'],
                'queue' => $queue
            ]);

            return false;
        } catch (RedisException $e) {
            $this->logger->error("Redis exception while pushing job", [
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

        $this->logger->smartLog("Attempting to pop job from queue", [
            'queue' => $queue,
            'queue_key' => $queueKey
        ]);

        try {
            $jobJson = $this->redis->lPop($queueKey);
            if (!$jobJson) {
                $this->logger->smartLog("No jobs available in queue", [
                    'queue' => $queue
                ]);
                return null;
            }

            /**
             * Decode the job JSON and validate its structure
             * @var array{
             *   id?: string,
             *   job?: string,
             *   message?: string,
             *   attempts?: int,
             *   created_at?: float
             * } $jobData
             */
            $jobData = json_decode($jobJson, true);
            if (!$jobData) {
                $this->logger->error("Failed to decode job JSON", [
                    'queue' => $queue,
                    'job_json' => $jobJson
                ]);
                return null;
            }

            $unserializedMessage = unserialize($jobData['message']);
            if (!($unserializedMessage instanceof Message)) {
                $this->logger->error("Failed to unserialize message or invalid message type", [
                    'job_id' => $jobData['id'] ?? 'unknown',
                    'queue' => $queue
                ]);
                return null;
            }

            $jobData['message'] = $unserializedMessage;

            $this->logger->smartLog("Job popped successfully", [
                'job_id' => $jobData['id'] ?? 'unknown',
                'job_class' => $jobData['job'] ?? 'unknown',
                'queue' => $queue,
                'attempts' => $jobData['attempts'] ?? 0
            ]);

            return new Job($jobData, $this, $this->logger);
        } catch (RedisException $e) {
            $this->logger->error("Redis exception while popping job", [
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

            $this->logger->smartLog("Queue size retrieved", [
                'queue' => $queue,
                'size' => $size
            ]);

            return $size;
        } catch (RedisException $e) {
            $this->logger->error("Redis exception while getting queue size", [
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

        $this->logger->smartLog("Clearing queue", [
            'queue' => $queue,
            'queue_key' => $queueKey
        ]);

        try {
            $delResult = $this->redis->del($queueKey);
            $result = is_int($delResult) ? $delResult > 0 : false;


            $this->logger->smartLog($result ? "Queue cleared successfully" : "Queue was already empty", [
                'queue' => $queue,
                'success' => $result
            ]);

            return $result;
        } catch (RedisException $e) {
            $this->logger->error("Redis exception while clearing queue", [
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
            $this->logger->smartLog("Disconnecting from Redis");
            $this->redis->close();
            $this->logger->smartLog("Redis connection closed successfully");
        } catch (RedisException $e) {
            $this->logger->error("Error while disconnecting from Redis", [
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
     * @param array{
     *   id?: string|null,
     *   job?: string|null,
     *   message?: string|null,
     *   attempts?: int|null,
     *   created_at?: float|null
     * } $jobData
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

        $this->logger->smartLog("Pushing job to failed queue", [
            'job_id' => $failedJobData['id'],
            'original_job_class' => $jobData['job'] ?? 'unknown',
            'attempts' => $jobData['attempts'] ?? 0,
            'error_message' => $exception->getMessage()
        ]);

        try {
            $op = $this->redis->rPush($failedKey, json_encode($failedJobData));
            $result = is_int($op) ? $op > 0 : false;

            if ($result) {
                $this->logger->smartLog("Job pushed to failed queue successfully", [
                    'job_id' => $failedJobData['id']
                ]);
            } else {
                $this->logger->error("Failed to push job to failed queue", [
                    'job_id' => $failedJobData['id']
                ]);
            }

            return $result;
        } catch (RedisException $e) {
            $this->logger->error("Redis exception while pushing to failed queue", [
                'job_id' => $failedJobData['id'],
                'exception' => $e,
                'error_message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            error_log("Failed to push job to failed queue: " . $e->getMessage());
            return false;
        }
    }

    public function getFailedJobs(int $limit = 100): array
    {
        $failedKey = $this->keyPrefix . 'failed';

        $this->logger->smartLog("Retrieving failed jobs", [
            'limit' => $limit
        ]);

        try {
            $jobs = $this->redis->lRange($failedKey, 0, $limit - 1);
            if (!is_array($jobs)) $jobs = [];

            $decodedJobs = array_map(
                fn($job) => is_string($job) ? json_decode($job, true) : null,
                $jobs
            );
            $decodedJobs = array_filter($decodedJobs, fn($job) => $job !== null);

            $this->logger->smartLog("Failed jobs retrieved", [
                'count' => count($decodedJobs),
                'limit' => $limit
            ]);

            /** @var array<int, array{
             * id: string|null,
             * original_job: array<string, mixed>,
             * exception?: array{
             *     message: string,
             *     file: string,
             *     line: int,
             *     trace: string
             * },
             * failed_at?: float
             * }> $decodedJobs */
            return $decodedJobs;
        } catch (RedisException $e) {
            $this->logger->error("Redis exception while getting failed jobs", [
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

        $this->logger->smartLog("Attempting to retry failed job", [
            'job_id' => $jobId
        ]);

        try {
            $failedJobs = $this->redis->lRange($failedKey, 0, -1);

            foreach ($failedJobs as $index => $jobJson) {
                if (!is_string($jobJson)) {
                    $this->logger->warning("Invalid job JSON type", [
                        'job_json_type' => gettype($jobJson),
                        'index' => $index
                    ]);
                    continue;
                }

                $failedJobData = json_decode($jobJson, true);

                // Validate decoded JSON data
                if (!is_array($failedJobData) || !isset($failedJobData['id']) || !is_string($failedJobData['id'])) {
                    $this->logger->warning("Invalid failed job data structure", [
                        'job_json' => $jobJson
                    ]);
                    continue;
                }

                if ($failedJobData['id'] === $jobId) {
                    // Remove from failed queue
                    $this->redis->lRem($failedKey, $jobJson, 1);

                    // Validate original job data structure
                    if (!isset($failedJobData['original_job']) || !is_array($failedJobData['original_job'])) {
                        $this->logger->error("Missing or invalid original_job data in failed job", [
                            'job_id' => $jobId
                        ]);
                        return false;
                    }

                    $originalJob = $failedJobData['original_job'];

                    // Validate required fields in original job
                    if (
                        !isset($originalJob['id']) || !is_string($originalJob['id']) ||
                        !isset($originalJob['job']) || !is_string($originalJob['job']) ||
                        !isset($originalJob['message'])
                    ) {
                        $this->logger->error("Missing required fields in original job data", [
                            'job_id' => $jobId,
                            'original_job_keys' => array_keys($originalJob)
                        ]);
                        return false;
                    }

                    // Extract the Message object from the original job data
                    $message = $originalJob['message']; // This should be the serialized Message

                    // Validate and normalize attempts field
                    $attempts = 0;
                    if (isset($originalJob['attempts']) && is_numeric($originalJob['attempts'])) {
                        $attempts = (int)$originalJob['attempts'];
                    }

                    // Validate and normalize created_at field
                    $createdAt = microtime(true);
                    if (isset($originalJob['created_at']) && is_numeric($originalJob['created_at'])) {
                        $createdAt = (float)$originalJob['created_at'];
                    }

                    // Add back to main queue with incremented attempts using the same format as push()
                    $retryJobData = [
                        'id' => $originalJob['id'], // Keep original job ID
                        'job' => $originalJob['job'],
                        'message' => $message, // Keep the serialized Message object
                        'attempts' => $attempts + 1,
                        'created_at' => $createdAt, // Keep original creation time
                        'retried_at' => microtime(true), // Add retry timestamp
                    ];

                    // Push directly to the main queue
                    $queueKey = $this->keyPrefix . $this->defaultQueue;
                    $pushResult = $this->redis->rPush($queueKey, json_encode($retryJobData));
                    $result = is_int($pushResult) && $pushResult > 0;

                    $this->logger->smartLog($result ? "Failed job retried successfully" : "Failed to retry job", [
                        'job_id' => $jobId,
                        'new_attempts' => $retryJobData['attempts'],
                        'success' => $result
                    ]);

                    return $result;
                }
            }

            $this->logger->warning("Failed job not found for retry", [
                'job_id' => $jobId
            ]);

            return false; // Job not found

        } catch (RedisException $e) {
            $this->logger->error("Redis exception while retrying job", [
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

        $this->logger->smartLog("Clearing failed jobs");

        try {
            $delResult = $this->redis->del($failedKey);
            $result = is_int($delResult) && $delResult >= 0;

            $this->logger->smartLog($result ? "Failed jobs cleared successfully" : "Failed jobs were already empty", [
                'success' => $result
            ]);

            return $result;
        } catch (RedisException $e) {
            $this->logger->error("Redis exception while clearing failed jobs", [
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

            $this->logger->smartLog("Failed jobs count retrieved", [
                'count' => $count
            ]);

            return $count;
        } catch (RedisException $e) {
            $this->logger->error("Redis exception while getting failed jobs count", [
                'exception' => $e,
                'error_message' => $e->getMessage()
            ]);
            return 0;
        }
    }

    /**
     * Get the default queue name
     *
     * @return string|null
     */
    public function getDefaultQueue(): string|null
    {
        return $this->defaultQueue;
    }
}
