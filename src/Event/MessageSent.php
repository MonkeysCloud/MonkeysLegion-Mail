<?php

declare(strict_types=1);

namespace MonkeysLegion\Mail\Event;

use MonkeysLegion\Logger\Contracts\MonkeysLoggerInterface;

class MessageSent
{
    private int $sentAt;

    /**
     * MessageSent constructor.
     *
     * @param string $jobId Unique identifier for the job
     * @param array<string, mixed> $jobData Data associated with the job
     * @param int $duration Duration in milliseconds it took to send the message
     * @param MonkeysLoggerInterface|null $logger Logger instance for logging the event
     */
    public function __construct(
        private string $jobId,
        private array $jobData,
        private int $duration,
        private ?MonkeysLoggerInterface $logger
    ) {
        $this->sentAt = time();
        $this->log();
    }

    private function log(): void
    {
        $this->logger?->smartLog("MessageSent event created", [
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

    /**
     * Get the job data associated with the sent message.
     *
     * @return array<string, mixed> The job data
     */
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
