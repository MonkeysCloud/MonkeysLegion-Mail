<?php

declare(strict_types=1);

namespace MonkeysLegion\Mail\Event;

class MessageQueued
{
    private string $jobId;
    private array $jobData;
    private int $queuedAt;

    public function __construct(string $jobId, array $jobData)
    {
        $this->jobId = $jobId;
        $this->jobData = $jobData;
        $this->queuedAt = time();
    }

    public function getJobId(): string
    {
        return $this->jobId;
    }

    public function getJobData(): array
    {
        return $this->jobData;
    }

    public function getQueuedAt(): int
    {
        return $this->queuedAt;
    }
}
