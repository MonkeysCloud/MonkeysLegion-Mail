<?php

declare(strict_types=1);

namespace MonkeysLegion\Mail\Event;

use MonkeysLegion\Mail\Logger\Logger;

class MessageQueued
{
    private int $queuedAt;

    public function __construct(
        private string $jobId,
        private array $jobData,
        private Logger $logger
    ) {
        $this->queuedAt = time();
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
