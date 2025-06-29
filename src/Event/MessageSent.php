<?php

declare(strict_types=1);

namespace MonkeysLegion\Mail\Event;

use MonkeysLegion\Mail\Logger\Logger;

class MessageSent
{
    private string $jobId;
    private array $jobData;
    private int $sentAt;
    private int $duration;
    private Logger $logger;

    public function __construct(string $jobId, array $jobData, int $duration, Logger $logger)
    {
        $this->jobId = $jobId;
        $this->jobData = $jobData;
        $this->duration = $duration;
        $this->sentAt = time();
        $this->logger = $logger;
        $this->log();
    }

    private function log()
    {
        $this->logger->log("MessageSent event created", [
            'job_id' => $this->jobId,
            'job_class' => $this->jobData['job'] ?? 'unknown',
            'duration_ms' => $this->duration,
            'sent_at' => $this->sentAt
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

    public function getSentAt(): int
    {
        return $this->sentAt;
    }

    public function getDuration(): int
    {
        return $this->duration;
    }
}
