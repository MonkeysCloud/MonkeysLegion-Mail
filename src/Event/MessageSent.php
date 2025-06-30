<?php

declare(strict_types=1);

namespace MonkeysLegion\Mail\Event;

use MonkeysLegion\Mail\Logger\Logger;

class MessageSent
{
    private int $sentAt;

    public function __construct(
        private string $jobId,
        private array $jobData,
        private int $duration,
        private Logger $logger
    ) {
        $this->sentAt = time();
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
