<?php

declare(strict_types=1);

namespace MonkeysLegion\Mail\Queue;

use MonkeysLegion\Mail\Cli\Traits\CliOutput;
use MonkeysLegion\Core\Contracts\FrameworkLoggerInterface;
use Redis;
use RedisException;
use MonkeysLegion\Mail\Event\MessageQueued;
use MonkeysLegion\Mail\Message;
use MonkeysLegion\Mail\Service\ServiceContainer;

class RedisQueue implements QueueInterface
{
    use CliOutput;

    private Redis $redis;
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
        $this->setLogger($logger);
        $this->connect($host, $port);
    }

    private function connect(string $host, int $port): void
    {
        try {
            $connected = $this->redis->connect($host, $port, 30);
            if (!$connected) {
                $this->output("Failed to connect to Redis", [
                    'host' => $host,
                    'port' => $port
                ], 'error'); // Keep exceptions in CLI
                throw new \RuntimeException("Failed to connect to Redis at {$host}:{$port}");
            }
        } catch (RedisException $e) {
            $this->output("Redis connection error", [
                'host' => $host,
                'port' => $port,
                'exception' => $e,
                'error_message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 'error'); // Keep exceptions in CLI
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

        $this->output("Pushing job to queue", [
            'job_id' => $jobData['id'],
            'job_class' => $job,
            'queue' => $queue,
            'queue_key' => $queueKey,
            'message_to' => $message->getTo(),
            'message_subject' => $message->getSubject()
        ], ignoreCli: true);

        try {
            $result = $this->redis->rPush($queueKey, json_encode($jobData));

            if ($result) {
                // Create event - logging is handled inside event constructor
                new MessageQueued($jobData['id'], $jobData, $this->logger);

                $this->output("Job pushed successfully", [
                    'job_id' => $jobData['id'],
                    'queue' => $queue,
                    'queue_size' => $result
                ], 'notice', ignoreCli: true);

                return $jobData['id'];
            }

            $this->output("Failed to push job - Redis returned false", [
                'job_id' => $jobData['id'],
                'queue' => $queue
            ], 'warning', ignoreCli: true);

            return false;
        } catch (RedisException $e) {
            $this->output("Redis exception while pushing job", [
                'job_id' => $jobData['id'],
                'queue' => $queue,
                'exception' => $e,
                'error_message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 'error'); // Keep exceptions in CLI
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
                $this->output("Failed to decode job JSON", [
                    'queue' => $queue,
                    'job_json' => $jobJson
                ], 'error'); // Keep exceptions in CLI
                return null;
            }

            $unserializedMessage = unserialize($jobData['message']);
            if (!($unserializedMessage instanceof Message)) {
                $this->output("Failed to unserialize message or invalid message type", [
                    'job_id' => $jobData['id'] ?? 'unknown',
                    'queue' => $queue
                ], 'error'); // Keep exceptions in CLI
                return null;
            }

            $jobData['message'] = $unserializedMessage;

            $this->output("Job received", [
                'job_id' => $jobData['id'] ?? 'unknown',
                'job_class' => $jobData['job'] ?? 'unknown',
                'queue' => $queue,
                'attempts' => $jobData['attempts'] ?? 0
            ], 'notice', ignoreCli: true);

            return new Job($jobData, $this, $this->logger);
        } catch (RedisException $e) {
            $this->output("Redis exception while popping job", [
                'queue' => $queue,
                'exception' => $e,
                'error_message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 'error'); // Keep exceptions in CLI
            throw new \RuntimeException("Failed to pop job from queue: " . $e->getMessage(), 0, $e);
        }
    }

    public function size(?string $queue = null): int
    {
        $queue = $queue ?? $this->defaultQueue;
        $queueKey = $this->keyPrefix . $queue;

        try {
            $size = $this->redis->lLen($queueKey);

            $this->output("Queue size retrieved", [
                'queue' => $queue,
                'size' => $size
            ], ignoreCli: true);

            return $size;
        } catch (RedisException $e) {
            $this->output("Redis exception while getting queue size", [
                'queue' => $queue,
                'exception' => $e,
                'error_message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 'error'); // Keep exceptions in CLI
            throw new \RuntimeException("Failed to get queue size: " . $e->getMessage(), 0, $e);
        }
    }

    public function clear(?string $queue = null): bool
    {
        $queue = $queue ?? $this->defaultQueue;
        $queueKey = $this->keyPrefix . $queue;

        $this->output("Clearing queue", [
            'queue' => $queue,
            'queue_key' => $queueKey
        ], ignoreCli: true);

        try {
            $delResult = $this->redis->del($queueKey);
            $result = is_int($delResult) ? $delResult > 0 : false;

            $this->output($result ? "Queue cleared successfully" : "Queue was already empty", [
                'queue' => $queue,
                'success' => $result
            ], $result ? 'notice' : 'info', ignoreCli: true);

            return $result;
        } catch (RedisException $e) {
            $this->output("Redis exception while clearing queue", [
                'queue' => $queue,
                'exception' => $e,
                'error_message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 'error'); // Keep exceptions in CLI
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

        $this->output("Moving job to failed queue", [
            'job_id' => $failedJobData['id'],
            'original_job_class' => $jobData['job'] ?? 'unknown',
            'attempts' => $jobData['attempts'] ?? 0,
            'error_message' => $exception->getMessage()
        ], 'warning', ignoreCli: true);

        try {
            $op = $this->redis->rPush($failedKey, json_encode($failedJobData));
            $result = is_int($op) ? $op > 0 : false;

            if ($result) {
                $this->output("Job moved to failed queue", [
                    'job_id' => $failedJobData['id']
                ], 'warning', ignoreCli: true);
            } else {
                $this->output("Failed to move job to failed queue", [
                    'job_id' => $failedJobData['id']
                ], 'error'); // Keep exceptions in CLI
            }

            return $result;
        } catch (RedisException $e) {
            $this->output("Redis exception while pushing to failed queue", [
                'job_id' => $failedJobData['id'],
                'exception' => $e,
                'error_message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 'error'); // Keep exceptions in CLI
            error_log("Failed to push job to failed queue: " . $e->getMessage());
            return false;
        }
    }

    public function getFailedJobs(int $limit = 100): array
    {
        $failedKey = $this->keyPrefix . 'failed';

        $this->output("Retrieving failed jobs", [
            'limit' => $limit
        ], ignoreCli: true);

        try {
            $jobs = $this->redis->lRange($failedKey, 0, $limit - 1);
            if (!is_array($jobs)) $jobs = [];

            $decodedJobs = array_map(
                fn($job) => is_string($job) ? json_decode($job, true) : null,
                $jobs
            );
            $decodedJobs = array_filter($decodedJobs, fn($job) => $job !== null);

            $this->output("Failed jobs retrieved", [
                'count' => count($decodedJobs),
                'limit' => $limit
            ], ignoreCli: true);

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
            $this->output("Redis exception while getting failed jobs", [
                'limit' => $limit,
                'exception' => $e,
                'error_message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 'error'); // Keep exceptions in CLI
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

        $this->output("Attempting to retry failed job", [
            'job_id' => $jobId
        ], ignoreCli: true);

        try {
            $failedJobs = $this->redis->lRange($failedKey, 0, -1);

            foreach ($failedJobs as $index => $jobJson) {
                if (!is_string($jobJson)) {
                    $this->output("Invalid job JSON type", [
                        'job_json_type' => gettype($jobJson),
                        'index' => $index
                    ], 'warning', ignoreCli: true);
                    continue;
                }

                $failedJobData = json_decode($jobJson, true);

                // Validate decoded JSON data
                if (!is_array($failedJobData) || !isset($failedJobData['id']) || !is_string($failedJobData['id'])) {
                    $this->output("Invalid failed job data structure", [
                        'job_json' => $jobJson
                    ], 'warning', ignoreCli: true);
                    continue;
                }

                if ($failedJobData['id'] === $jobId) {
                    // Remove from failed queue
                    $this->redis->lRem($failedKey, $jobJson, 1);

                    // Validate original job data structure
                    if (!isset($failedJobData['original_job']) || !is_array($failedJobData['original_job'])) {
                        $this->output("Missing or invalid original_job data in failed job", [
                            'job_id' => $jobId
                        ], 'error'); // Keep exceptions in CLI
                        return false;
                    }

                    $originalJob = $failedJobData['original_job'];

                    // Validate required fields in original job
                    if (
                        !isset($originalJob['id']) || !is_string($originalJob['id']) ||
                        !isset($originalJob['job']) || !is_string($originalJob['job']) ||
                        !isset($originalJob['message'])
                    ) {
                        $this->output("Missing required fields in original job data", [
                            'job_id' => $jobId,
                            'original_job_keys' => array_keys($originalJob)
                        ], 'error'); // Keep exceptions in CLI
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

                    $this->output($result ? "Failed job retried successfully" : "Failed to retry job", [
                        'job_id' => $jobId,
                        'new_attempts' => $retryJobData['attempts'],
                        'success' => $result
                    ], $result ? 'notice' : 'error', ignoreCli: !$result);

                    return $result;
                }
            }

            $this->output("Failed job not found for retry", [
                'job_id' => $jobId
            ], 'warning', ignoreCli: true);

            return false;
        } catch (RedisException $e) {
            $this->output("Redis exception while retrying job", [
                'job_id' => $jobId,
                'exception' => $e,
                'error_message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 'error'); // Keep exceptions in CLI
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

        $this->output("Clearing failed jobs", ignoreCli: true);

        try {
            $delResult = $this->redis->del($failedKey);
            $result = is_int($delResult) && $delResult >= 0;

            $this->output($result ? "Failed jobs cleared successfully" : "Failed jobs were already empty", [
                'success' => $result
            ], $result ? 'notice' : 'info', ignoreCli: true);

            return $result;
        } catch (RedisException $e) {
            $this->output("Redis exception while clearing failed jobs", [
                'exception' => $e,
                'error_message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 'error'); // Keep exceptions in CLI
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

            $this->output("Failed jobs count retrieved", [
                'count' => $count
            ], ignoreCli: true);

            return $count;
        } catch (RedisException $e) {
            $this->output("Redis exception while getting failed jobs count", [
                'exception' => $e,
                'error_message' => $e->getMessage()
            ], 'error'); // Keep exceptions in CLI
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
