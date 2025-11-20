<?php

declare(strict_types=1);

namespace MonkeysLegion\Mail\Event;

use MonkeysLegion\Logger\Contracts\MonkeysLoggerInterface;

class MessageQueued
{
    private int $queuedAt;

    /**
     * MessageQueued constructor.
     *
     * @param string $jobId Unique identifier for the job
     * @param array<string, mixed> $jobData Data associated with the job
     * @param ?MonkeysLoggerInterface $logger Logger instance for logging the event
     */
    public function __construct(
        private string $jobId,
        private array $jobData,
        private ?MonkeysLoggerInterface $logger
    ) {
        $this->queuedAt = time();
        $this->log();
    }

    private function log(): void
    {
        $this->logger?->smartLog("MessageQueued event created", [
            'job_id' => $this->jobId,
            'job_class' => $this->jobData['job'] ?? 'unknown',
            'queued_at' => $this->queuedAt
        ]);
    }

    public function getJobId(): string
    {
        return $this->jobId;
    }

    /**
     * Get the job data associated with the queued message.
     *
     * @return array<string, mixed> The job data
     */
    public function getJobData(): array
    {
        return $this->jobData;
    }

    public function getQueuedAt(): int
    {
        return $this->queuedAt;
    }
}
