<?php

declare(strict_types=1);

namespace MonkeysLegion\Mail\Event;

use MonkeysLegion\Core\Contracts\FrameworkLoggerInterface;

class MessageQueued
{
    private int $queuedAt;

    public function __construct(
        private string $jobId,
        private array $jobData,
        private FrameworkLoggerInterface $logger
    ) {
        $this->queuedAt = time();
        $this->log();
    }

    private function log()
    {
        $this->logger->smartLog("MessageQueued event created", [
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
