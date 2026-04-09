<?php

declare(strict_types=1);

namespace MonkeysLegion\Mail\Event;

use MonkeysLegion\Logger\Contracts\MonkeysLoggerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\StoppableEventInterface;

class MessageSent implements StoppableEventInterface
{
    private int $sentAt;
    private bool $propagationStopped = false;

    /**
     * MessageSent constructor.
     *
     * @param string $jobId Unique identifier for the job
     * @param array<string, mixed> $jobData Data associated with the job
     * @param int $duration Duration in milliseconds it took to send the message
     * @param MonkeysLoggerInterface|null $logger Logger instance for logging the event
     * @param ?EventDispatcherInterface $eventDispatcher Optional PSR-14 event dispatcher
     * @param string|null $mailableClass FQCN of the Mailable that triggered this event (null for direct sends)
     */
    public function __construct(
        private string $jobId,
        private array $jobData,
        private int $duration,
        private ?MonkeysLoggerInterface $logger,
        private ?EventDispatcherInterface $eventDispatcher = null,
        private ?string $mailableClass = null,
    ) {
        $this->sentAt = time();
        $this->log();
        $this->eventDispatcher?->dispatch($this);
    }

    /**
     * Returns the FQCN of the Mailable that triggered this event, or null for direct sends.
     */
    public function getMailableClass(): ?string
    {
        return $this->mailableClass;
    }

    private function log(): void
    {
        $this->logger?->smartLog("MessageSent event created", [
            'job_id'      => $this->jobId,
            'job_class'   => $this->jobData['job'] ?? 'unknown',
            'duration_ms' => $this->duration,
            'sent_at'     => $this->sentAt,
        ]);
    }

    public function isPropagationStopped(): bool
    {
        return $this->propagationStopped;
    }

    public function stopPropagation(): void
    {
        $this->propagationStopped = true;
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
