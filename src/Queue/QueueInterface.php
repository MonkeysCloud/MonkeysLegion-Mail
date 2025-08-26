<?php

declare(strict_types=1);

namespace MonkeysLegion\Mail\Queue;

use MonkeysLegion\Mail\Message;

interface QueueInterface
{
    /**
     * Push a job onto the queue.
     *
     * @param string $job The job class name or identifier
     * @param Message $message The message object to queue
     * @param string|null $queue The queue name (optional)
     * @return mixed Job ID or confirmation
     */
    public function push(string $job, Message $message, ?string $queue = null): mixed;

    /**
     * Pop a job from the queue.
     *
     * @param string|null $queue The queue name (optional)
     * @return JobInterface|null The job instance or null if no jobs available
     */
    public function pop(?string $queue = null): ?JobInterface;

    /**
     * Get the size of the queue.
     *
     * @param string|null $queue The queue name (optional)
     * @return int The number of jobs in the queue
     */
    public function size(?string $queue = null): int;

    /**
     * Clear all jobs from the queue.
     *
     * @param string|null $queue The queue name (optional)
     * @return bool Success status
     */
    public function clear(?string $queue = null): bool;

    /**
     * Push a job to the failed queue.
     *
     * @param array<string, mixed> $jobData Original job data
     * @param \Exception $exception The exception that caused the failure
     * @return bool Success status
     */
    public function pushToFailed(array $jobData, \Exception $exception): bool;

    /**
     * @return array<int, array{
     *     id: string|null,
     *     original_job: array<string, mixed>,
     *     exception?: array{
     *         message: string,
     *         file: string,
     *         line: int,
     *         trace: string
     *     },
     *     failed_at?: float
     * }>
     */
    public function getFailedJobs(int $limit = 100): array;

    /**
     * Retry a failed job by moving it back to the main queue.
     *
     * @param string $jobId The failed job ID
     * @return bool Success status
     */
    public function retryFailedJob(string $jobId): bool;

    /**
     * Clear failed jobs.
     *
     * @return bool Success status
     */
    public function clearFailedJobs(): bool;

    /**
     * Get failed jobs count.
     *
     * @return int Number of failed jobs
     */
    public function getFailedJobsCount(): int;
}
