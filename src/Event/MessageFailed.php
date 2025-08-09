<?php

declare(strict_types=1);

namespace MonkeysLegion\Mail\Event;

use MonkeysLegion\Core\Contracts\FrameworkLoggerInterface;

class MessageFailed
{
    private int $failedAt;

    /**
     * MessageFailed constructor.
     *
     * @param string $jobId Unique identifier for the job
     * @param array<string, mixed> $jobData Data associated with the job
     * @param \Exception $exception Exception that caused the job to fail
     * @param int $attempts Number of attempts made to process the job
     * @param bool $willRetry Whether the job will be retried
     * @param FrameworkLoggerInterface $logger Logger instance for logging the event
     */
    public function __construct(
        private string $jobId,
        private array $jobData,
        private \Exception $exception,
        private int $attempts,
        private bool $willRetry,
        private FrameworkLoggerInterface $logger
    ) {
        $this->failedAt = time();
        $this->logger = $logger;
        $this->log();
    }

    private function log(): void
    {
        $this->logger->error("MessageFailed event created", [
            'job_id' => $this->jobId,
            'job_class' => $this->jobData['job'] ?? 'unknown',
            'attempts' => $this->attempts,
            'will_retry' => $this->willRetry,
            'error_message' => $this->exception->getMessage(),
            'failed_at' => $this->failedAt
        ]);
    }

    public function getJobId(): string
    {
        return $this->jobId;
    }

    /**
     * Get the job data associated with the failed message.
     *
     * @return array<string, mixed> The job data
     */
    public function getJobData(): array
    {
        return $this->jobData;
    }

    public function getException(): \Exception
    {
        return $this->exception;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function getFailedAt(): int
    {
        return $this->failedAt;
    }

    public function willRetry(): bool
    {
        return $this->willRetry;
    }
}
