<?php

declare(strict_types=1);

namespace MonkeysLegion\Mail\Queue;

use MonkeysLegion\Mail\Event\MessageFailed;
use MonkeysLegion\Mail\Event\MessageSent;

/**
 * Queue worker for processing jobs
 * Handles job execution, retries, and failure management
 */
class Worker
{
    private QueueInterface $queue;
    private int $sleep = 3;
    private int $maxTries = 3;
    private int $memory = 128; // MB
    private int $timeout = 60; // seconds

    public function __construct(QueueInterface $queue)
    {
        $this->queue = $queue;
    }

    /**
     * Start processing jobs from the queue
     * Main worker loop that continues until stopped
     * 
     * @param string|null $queueName Specific queue to work on
     * @return void
     */
    public function work(?string $queueName = null): void
    {
        echo "Worker started. Listening for jobs...\n\n";

        while (true) {
            // Process pending signals if available
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            // Check memory usage
            if ($this->memoryExceeded()) {
                echo "Memory limit exceeded. Stopping worker.\n";
                break;
            }

            try {
                $job = $this->queue->pop($queueName);

                if ($job === null) {
                    sleep($this->sleep);
                    continue;
                }

                $this->processJob($job);
            } catch (\Exception $e) {
                echo "Error in worker loop: " . $e->getMessage() . "\n";
                // Sleep a bit before retrying to avoid tight error loops
                sleep(5);
            }
        }

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

        try {
            echo "Processing job {$job->getId()}...\n";

            // Set time limit for job execution
            set_time_limit($this->timeout);

            // Execute the job
            $job->handle();

            $duration = round((microtime(true) - $startTime) * 1000, 2); // Convert to milliseconds
            echo "✓ Job {$job->getId()} completed ({$duration}ms)\n\n";

            // Create and log success event
            $sentEvent = new MessageSent($job->getId(), $job->getData(), (int)$duration);
            error_log("MessageSent: Job {$job->getId()} completed successfully in {$duration}ms");
        } catch (\Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2); // Convert to milliseconds
            $attempts = $job->attempts() + 1;

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

        // Create failed event
        $failedEvent = new MessageFailed($job->getId(), $job->getData(), $exception, $attempts, $willRetry);

        if ($willRetry) {
            // Re-queue the job with incremented attempts
            $this->retryJob($job, $attempts, $exception);
        } else {
            // Move to failed queue after max attempts
            echo "✗ Job {$job->getId()} failed permanently after {$attempts} attempts\n";

            if ($this->queue instanceof RedisQueue) {
                $jobData = $job->getData();
                $jobData['attempts'] = $attempts;
                $this->queue->pushToFailed($jobData, $exception);
            }

            // Log final failure
            error_log("MessageFailed: Job {$job->getId()} failed permanently: " . $exception->getMessage());
        }
    }

    /**
     * Retry a failed job by re-queuing it
     */
    private function retryJob(JobInterface $job, int $attempts, \Exception $exception): void
    {
        try {
            // Get the original job data structure
            $originalJobData = $job->getData();

            // Preserve the original job ID and increment attempts
            $retryJobData = [
                'id' => $originalJobData['id'], // Keep original job ID
                'job' => $originalJobData['job'],
                'data' => $originalJobData['data'],
                'attempts' => $attempts, // Update attempts count
                'created_at' => $originalJobData['created_at'], // Keep original creation time
                'retried_at' => microtime(true), // Add retry timestamp
            ];

            // Push the complete job structure directly to Redis instead of using push()
            if ($this->queue instanceof RedisQueue) {
                $queueKey = 'queue:' . ($this->queue->getDefaultQueue() ?? 'emails');
                $redis = $this->queue->getRedis();
                $redis->rPush($queueKey, json_encode($retryJobData));
            }

            // Log retry with original job ID
            error_log("Job {$job->getId()} retry queued, attempt {$attempts}/{$this->maxTries}");
        } catch (\Exception $e) {
            // If retry fails, move to failed queue
            echo "✗ Failed to retry job {$job->getId()}: " . $e->getMessage() . "\n";

            if ($this->queue instanceof RedisQueue) {
                $jobData = $job->getData();
                $jobData['attempts'] = $attempts;
                $this->queue->pushToFailed($jobData, $exception);
            }

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
        return $memoryUsage >= $this->memory;
    }

    /**
     * Stop the worker gracefully
     * 
     * @return void
     */
    public function stop(): void
    {
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
        $this->timeout = $timeout;
    }
}
