<?php

declare(strict_types=1);

namespace MonkeysLegion\Mail\Event;

use MonkeysLegion\Mail\Logger\Logger;
use MonkeysLegion\Mail\Service\ServiceContainer;

class MessageFailed
{
    private string $jobId;
    private array $jobData;
    private \Exception $exception;
    private int $attempts;
    private int $failedAt;
    private bool $willRetry;
    private Logger $logger;

    public function __construct(string $jobId, array $jobData, \Exception $exception, int $attempts, bool $willRetry, Logger $logger)
    {
        $this->jobId = $jobId;
        $this->jobData = $jobData;
        $this->exception = $exception;
        $this->attempts = $attempts;
        $this->willRetry = $willRetry;
        $this->failedAt = time();
        $this->logger = $logger;
        $this->log();
    }

    private function log()
    {
        $this->logger->log("MessageFailed event created", [
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
