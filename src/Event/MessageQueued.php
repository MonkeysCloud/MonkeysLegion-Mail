<?php

declare(strict_types=1);

namespace MonkeysLegion\Mail\Event;

use MonkeysLegion\Mail\Logger\Logger;

class MessageQueued
{
    private string $jobId;
    private array $jobData;
    private int $queuedAt;
    private Logger $logger;

    public function __construct(string $jobId, array $jobData, Logger $logger)
    {
        $this->jobId = $jobId;
        $this->jobData = $jobData;
        $this->queuedAt = time();
        $this->logger = $logger;
        $this->log();
    }

    private function log()
    {
        $this->logger->log("MessageQueued event created", [
            'job_id' => $this->jobId,
            'job_class' => $this->jobData['job'] ?? 'unknown',
            'queued_at' => $this->queuedAt
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

    public function getQueuedAt(): int
    {
        return $this->queuedAt;
    }
}
