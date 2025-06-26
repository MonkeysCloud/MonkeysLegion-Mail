<?php

declare(strict_types=1);

namespace MonkeysLegion\Mail\Event;

class MessageFailed
{
    private string $jobId;
    private array $jobData;
    private \Exception $exception;
    private int $attempts;
    private int $failedAt;
    private bool $willRetry;

    public function __construct(string $jobId, array $jobData, \Exception $exception, int $attempts, bool $willRetry)
    {
        $this->jobId = $jobId;
        $this->jobData = $jobData;
        $this->exception = $exception;
        $this->attempts = $attempts;
        $this->willRetry = $willRetry;
        $this->failedAt = time();
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
