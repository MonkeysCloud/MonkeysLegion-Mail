<?php

declare(strict_types=1);

namespace MonkeysLegion\Mail\Queue;

use MonkeysLegion\Core\Contracts\FrameworkLoggerInterface;
use MonkeysLegion\Mail\Event\MessageFailed;
use MonkeysLegion\Mail\Event\MessageSent;

/**
 * Queue worker for processing jobs
 * Handles job execution, retries, and failure management
 */
class Worker
{
    private int $sleep = 3;
    private int $maxTries = 3;
    private int $memory = 128; // MB
    private int $timeout = 60; // seconds

    public function __construct(
        private QueueInterface $queue,
        private FrameworkLoggerInterface $logger
    ) {}

    /**
     * Start processing jobs from the queue
     * Main worker loop that continues until stopped
     * 
     * @param string|null $queueName Specific queue to work on
     * @return void
     */
    public function work(?string $queueName = null): void
    {
        $this->logger->smartLog("Worker started", [
            'queue' => $queueName ?? 'default',
            'sleep' => $this->sleep,
            'max_tries' => $this->maxTries,
            'memory_limit' => $this->memory,
            'timeout' => $this->timeout
        ]);

        echo "Worker started. Listening for jobs...\n\n";

        while (true) {
            // Process pending signals if available
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            // Check memory usage
            if ($this->memoryExceeded()) {
                $memoryUsage = memory_get_usage(true) / 1024 / 1024;
                $this->logger->warning("Memory limit exceeded - stopping worker", [
                    'memory_usage_mb' => round($memoryUsage, 2),
                    'memory_limit_mb' => $this->memory
                ]);
                echo "Memory limit exceeded. Stopping worker.\n";
                break;
            }

            try {
                $jobData = $this->queue->pop($queueName);

                if (!$jobData) {
                    sleep($this->sleep);
                    continue;
                }

                // $jobData is now ready for processing
                $this->processJob($jobData);
            } catch (\Exception $e) {
                $this->logger->error("Error in worker loop", [
                    'exception' => $e,
                    'error_message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'queue' => $queueName
                ]);
                echo "Error in worker loop: " . $e->getMessage() . "\n";
                // Sleep a bit before retrying to avoid tight error loops
                sleep(5);
            }
        }

        $this->logger->smartLog("Worker stopped");
        echo "Worker stopped.\n";
    }

    /**
     * Process a single job with timeout and error handling
     * 
     * @param JobInterface $job The job to process
     * @return void
     */
    private function processJob(JobInterface $job): void
    {
        $startTime = microtime(true);

        $this->logger->smartLog("Processing job", [
            'job_id' => $job->getId(),
            'job_class' => $job->getData()['job'] ?? 'unknown',
            'attempts' => $job->attempts(),
            'timeout' => $this->timeout
        ]);

        try {
            echo "Processing job {$job->getId()}...\n";

            // Set time limit for job execution
            set_time_limit($this->timeout);

            // Execute the job
            $job->handle();

            $duration = round((microtime(true) - $startTime) * 1000, 2); // Convert to milliseconds

            $this->logger->smartLog("Job processed successfully", [
                'job_id' => $job->getId(),
                'duration_ms' => $duration,
                'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2)
            ]);

            echo "✓ Job {$job->getId()} completed ({$duration}ms)\n\n";

            // Create event - logging is handled inside event constructor
            new MessageSent($job->getId(), $job->getData(), (int)$duration, $this->logger);
        } catch (\Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2); // Convert to milliseconds
            $attempts = $job->attempts() + 1;

            $this->logger->error("Job processing failed", [
                'job_id' => $job->getId(),
                'exception' => $e,
                'error_message' => $e->getMessage(),
                'duration_ms' => $duration,
                'attempts' => $attempts,
                'max_tries' => $this->maxTries,
                'trace' => $e->getTraceAsString()
            ]);

            // Handle job failure
            $this->handleFailedJob($job, $e, $attempts);
        }
    }

    /**
     * Handle failed job - retry or move to failed queue
     * 
     * @param JobInterface $job The failed job
     * @param \Exception $exception The exception that caused the failure
     * @param int $attempts Current attempt number
     * @return void
     */
    private function handleFailedJob(JobInterface $job, \Exception $exception, int $attempts): void
    {
        $willRetry = $attempts < $this->maxTries;

        $this->logger->smartLog("Handling failed job", [
            'job_id' => $job->getId(),
            'attempts' => $attempts,
            'max_tries' => $this->maxTries,
            'will_retry' => $willRetry,
            'error_message' => $exception->getMessage()
        ]);

        // Create event - logging is handled inside event constructor
        new MessageFailed($job->getId(), $job->getData(), $exception, $attempts, $willRetry, $this->logger);

        if ($willRetry) {
            // Re-queue the job with incremented attempts
            $this->retryJob($job, $attempts, $exception);
        } else {
            // Job has reached max attempts - call fail() to push to failed queue
            echo "✗ Job {$job->getId()} failed permanently after {$attempts} attempts\n";

            $this->logger->error("Job failed permanently", [
                'job_id' => $job->getId(),
                'final_attempts' => $attempts,
                'error_message' => $exception->getMessage()
            ]);

            $job->fail($exception);
        }
    }

    /**
     * Retry a failed job by re-queuing it
     */
    private function retryJob(JobInterface $job, int $attempts, \Exception $exception): void
    {
        $this->logger->smartLog("Attempting to retry job", [
            'job_id' => $job->getId(),
            'attempts' => $attempts,
            'max_tries' => $this->maxTries
        ]);

        try {
            // Get the original job data structure
            $originalJobData = $job->getData();

            // Preserve the original job ID and increment attempts
            $retryJobData = [
                'id' => $originalJobData['id'], // Keep original job ID
                'job' => $originalJobData['job'],
                'message' => $originalJobData['message'], // Keep the serialized Message object
                'attempts' => $attempts, // Update attempts count
                'created_at' => $originalJobData['created_at'], // Keep original creation time
                'retried_at' => microtime(true), // Add retry timestamp
            ];

            // Push the complete job structure directly to Redis
            if ($this->queue instanceof RedisQueue) {
                $queueKey = 'queue:' . ($this->queue->getDefaultQueue() ?? 'default');
                $redis = $this->queue->getRedis();
                $redis->rPush($queueKey, json_encode($retryJobData));
            }

            $this->logger->smartLog("Job retry queued successfully", [
                'job_id' => $job->getId(),
                'attempts' => $attempts,
                'max_tries' => $this->maxTries,
                'retried_at' => $retryJobData['retried_at']
            ]);

            // Log retry with original job ID
            echo "→ Job {$job->getId()} retry queued, attempt {$attempts}/{$this->maxTries}\n";
            error_log("Job {$job->getId()} retry queued, attempt {$attempts}/{$this->maxTries}");
        } catch (\Exception $e) {
            // If retry fails, move to failed queue using job->fail()
            $this->logger->error("Job retry failed", [
                'job_id' => $job->getId(),
                'original_exception' => $exception,
                'retry_exception' => $e,
                'retry_error_message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            echo "✗ Failed to retry job {$job->getId()}: " . $e->getMessage() . "\n";

            $job->fail($exception);

            error_log("MessageFailed: Retry failed for job {$job->getId()}: " . $e->getMessage());
        }
    }

    /**
     * Check if memory usage exceeds limit
     * 
     * @return bool True if memory limit exceeded
     */
    private function memoryExceeded(): bool
    {
        $memoryUsage = memory_get_usage(true) / 1024 / 1024; // Convert to MB
        $exceeded = $memoryUsage >= $this->memory;

        if ($exceeded) {
            $this->logger->warning("Memory usage check - limit exceeded", [
                'current_usage_mb' => round($memoryUsage, 2),
                'limit_mb' => $this->memory
            ]);
        }

        return $exceeded;
    }

    /**
     * Stop the worker gracefully
     * 
     * @return void
     */
    public function stop(): void
    {
        $this->logger->smartLog("Worker stop requested");
        echo "Stopping worker gracefully...\n";
    }

    /**
     * Set the sleep time between job checks.
     *
     * @param int $seconds
     * @return void
     */
    public function setSleep(int $seconds): void
    {
        $this->logger->smartLog("Worker sleep time updated", [
            'old_sleep' => $this->sleep,
            'new_sleep' => $seconds
        ]);
        $this->sleep = $seconds;
    }

    /**
     * Set the maximum number of tries for a job.
     *
     * @param int $tries
     * @return void
     */
    public function setMaxTries(int $tries): void
    {
        $this->logger->smartLog("Worker max tries updated", [
            'old_max_tries' => $this->maxTries,
            'new_max_tries' => $tries
        ]);
        $this->maxTries = $tries;
    }

    /**
     * Set memory limit in MB
     * 
     * @param int $memory Memory limit in MB
     * @return void
     */
    public function setMemory(int $memory): void
    {
        $this->logger->smartLog("Worker memory limit updated", [
            'old_memory_mb' => $this->memory,
            'new_memory_mb' => $memory
        ]);
        $this->memory = $memory;
    }

    /**
     * Set job timeout in seconds
     * 
     * @param int $timeout Timeout in seconds
     * @return void
     */
    public function setJobTimeout(int $timeout): void
    {
        $this->logger->smartLog("Worker job timeout updated", [
            'old_timeout' => $this->timeout,
            'new_timeout' => $timeout
        ]);
        $this->timeout = $timeout;
    }
}
