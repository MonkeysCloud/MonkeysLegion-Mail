<?php

declare(strict_types=1);

namespace MonkeysLegion\Mail\Event;

class MessageSent
{
    private string $jobId;
    private array $jobData;
    private int $sentAt;
    private int $duration;

    public function __construct(string $jobId, array $jobData, int $duration)
    {
        $this->jobId = $jobId;
        $this->jobData = $jobData;
        $this->duration = $duration;
        $this->sentAt = time();
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
