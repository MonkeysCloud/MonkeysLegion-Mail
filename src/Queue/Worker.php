<?php

declare(strict_types=1);

namespace MonkeysLegion\Mail\Queue;

use MonkeysLegion\Mail\Cli\Traits\CliOutput;
use MonkeysLegion\Core\Contracts\FrameworkLoggerInterface;
use MonkeysLegion\Mail\Event\MessageFailed;
use MonkeysLegion\Mail\Event\MessageSent;

/**
 * Queue worker for processing jobs
 * Handles job execution, retries, and failure management
 */
class Worker
{
    use CliOutput;

    private int $sleep = 3;
    private int $maxTries = 3;
    private int $memory = 128; // MB
    private int $timeout = 60; // seconds

    public function __construct(
        private QueueInterface $queue,
        FrameworkLoggerInterface $logger
    ) {
        $this->setLogger($logger);
    }

    /**
     * Enable CLI mode for colored output instead of logging
     */
    public function setCliMode(bool $enabled = true): self
    {
        $this->cliMode = $enabled;
        return $this;
    }

    /**
     * Output a message - either log or print based on mode
     */
    private function output(string $message, array $context = [], string $level = 'info'): void
    {
        if ($this->cliMode) {
            $this->printCliMessage($message, $context, $level);
        } else {
            match ($level) {
                'error' => $this->logger->error($message, $context),
                'warning' => $this->logger->warning($message, $context),
                'notice' => $this->logger->notice($message, $context),
                default => $this->logger->smartLog($message, $context),
            };
        }
    }

    /**
     * Print a CLI-friendly colored message
     */
    private function printCliMessage(string $message, array $context = [], string $level = 'info'): void
    {
        $line = $this->cliLine();

        // Add timestamp prefix
        $line->muted('[' . date('H:i:s') . ']')->space();

        // Add level indicator with color
        match ($level) {
            'error' => $line->error('✗')->space()->add($message, 'red'),
            'warning' => $line->warning('⚠')->space()->add($message, 'yellow'),
            'notice' => $line->success('✓')->space()->add($message, 'green'),
            'processing' => $line->info('→')->space()->add($message, 'cyan'),
            default => $line->info('•')->space()->add($message, 'white'),
        };

        // Add important context details inline
        if (!empty($context)) {
            $importantKeys = ['job_id', 'attempts', 'max_tries', 'duration_ms', 'memory_usage_mb', 'error_message'];
            $details = [];

            foreach ($importantKeys as $key) {
                if (isset($context[$key])) {
                    $value = is_scalar($context[$key]) ? (string)$context[$key] : json_encode($context[$key]);
                    $details[] = "$key=$value";
                }
            }

            if (!empty($details)) {
                $line->space()->muted('(' . implode(', ', $details) . ')');
            }
        }

        $line->print();
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
        if ($this->cliMode) {
            $this->cliLine()
                ->success('Worker started')
                ->space()
                ->muted('Listening for jobs on queue: ' . ($queueName ?? 'default'))
                ->print();
            echo "\n";
        } else {
            echo "Worker started. Listening for jobs...\n\n";
        }

        while (true) {
            // Process pending signals if available
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            // Check memory usage
            if ($this->memoryExceeded()) {
                $memoryUsage = memory_get_usage(true) / 1024 / 1024;
                $this->output("Memory limit exceeded - stopping worker", [
                    'memory_usage_mb' => round($memoryUsage, 2),
                    'memory_limit_mb' => $this->memory
                ], 'warning');

                if (!$this->cliMode) {
                    echo "Memory limit exceeded. Stopping worker.\n";
                }
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
                $this->output("Error in worker loop", [
                    'exception' => $e,
                    'error_message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'queue' => $queueName
                ], 'error');

                if (!$this->cliMode) {
                    echo "Error in worker loop: " . $e->getMessage() . "\n";
                }
                // Sleep a bit before retrying to avoid tight error loops
                sleep(5);
            }
        }

        if ($this->cliMode) {
            $this->cliLine()
                ->warning('Worker stopped')
                ->print();
        } else {
            echo "Worker stopped.\n";
        }
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

        $this->output("Processing job", [
            'job_id' => $job->getId(),
            'job_class' => $job->getData()['job'] ?? 'unknown',
            'attempts' => $job->attempts(),
            'timeout' => $this->timeout
        ], 'processing');

        try {
            // Set time limit for job execution
            set_time_limit($this->timeout);

            // Execute the job
            $job->handle();

            $duration = round((microtime(true) - $startTime) * 1000, 2); // Convert to milliseconds

            $this->output("Job completed", [
                'job_id' => $job->getId(),
                'duration_ms' => $duration,
                'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2)
            ], 'notice');

            // Create event - logging is handled inside event constructor
            new MessageSent($job->getId(), $job->getData(), (int)$duration, $this->logger);
        } catch (\Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2); // Convert to milliseconds
            $attempts = $job->attempts() + 1;

            $this->output("Job failed", [
                'job_id' => $job->getId(),
                'exception' => $e,
                'error_message' => $e->getMessage(),
                'duration_ms' => $duration,
                'attempts' => $attempts,
                'max_tries' => $this->maxTries,
                'trace' => $e->getTraceAsString()
            ], 'error');

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

        $this->output($willRetry ? "Job will be retried" : "Job failed permanently", [
            'job_id' => $job->getId(),
            'attempts' => $attempts,
            'max_tries' => $this->maxTries,
            'will_retry' => $willRetry,
            'error_message' => $exception->getMessage()
        ], $willRetry ? 'warning' : 'error');

        // Create event - logging is handled inside event constructor
        new MessageFailed($job->getId(), $job->getData(), $exception, $attempts, $willRetry, $this->logger);

        if ($willRetry) {
            // Re-queue the job with incremented attempts
            $this->retryJob($job, $attempts, $exception);
        } else {
            // Job has reached max attempts - call fail() to push to failed queue
            $job->fail($exception);
        }
    }

    /**
     * Retry a failed job by re-queuing it
     */
    private function retryJob(JobInterface $job, int $attempts, \Exception $exception): void
    {
        $this->output("Retrying job", [
            'job_id' => $job->getId(),
            'attempts' => $attempts,
            'max_tries' => $this->maxTries
        ], 'processing');

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

            $this->output("Job retry queued", [
                'job_id' => $job->getId(),
                'attempts' => $attempts,
                'max_tries' => $this->maxTries,
                'retried_at' => $retryJobData['retried_at']
            ], 'notice');

            if (!$this->cliMode) {
                error_log("Job {$job->getId()} retry queued, attempt {$attempts}/{$this->maxTries}");
            }
        } catch (\Exception $e) {
            // If retry fails, move to failed queue using job->fail()
            $this->output("Failed to retry job", [
                'job_id' => $job->getId(),
                'original_exception' => $exception,
                'retry_exception' => $e,
                'retry_error_message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 'error');

            $job->fail($exception);

            if (!$this->cliMode) {
                error_log("MessageFailed: Retry failed for job {$job->getId()}: " . $e->getMessage());
            }
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

        if ($exceeded && !$this->cliMode) {
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
        if ($this->cliMode) {
            $this->cliLine()
                ->warning('Stopping worker gracefully...')
                ->print();
        } else {
            echo "Stopping worker gracefully...\n";
        }
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
