<?php

declare(strict_types=1);

namespace MonkeysLegion\Mail\Event;

use MonkeysLegion\Logger\Contracts\MonkeysLoggerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\StoppableEventInterface;

class MessageFailed implements StoppableEventInterface
{
    private int $failedAt;
    private bool $propagationStopped = false;

    /**
     * MessageFailed constructor.
     *
     * @param string $jobId Unique identifier for the job
     * @param array<string, mixed> $jobData Data associated with the job
     * @param \Exception $exception Exception that caused the job to fail
     * @param int $attempts Number of attempts made to process the job
     * @param bool $willRetry Whether the job will be retried
     * @param ?MonkeysLoggerInterface $logger Logger instance for logging the event
     * @param ?EventDispatcherInterface $eventDispatcher Optional PSR-14 event dispatcher
     * @param string|null $mailableClass FQCN of the Mailable that triggered this event (null for direct sends)
     */
    public function __construct(
        private string $jobId,
        private array $jobData,
        private \Exception $exception,
        private int $attempts,
        private bool $willRetry,
        private ?MonkeysLoggerInterface $logger,
        private ?EventDispatcherInterface $eventDispatcher = null,
        private ?string $mailableClass = null,
    ) {
        $this->failedAt = time();
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
        $this->logger?->error("MessageFailed event created", [
            'job_id'        => $this->jobId,
            'job_class'     => $this->jobData['job'] ?? 'unknown',
            'attempts'      => $this->attempts,
            'will_retry'    => $this->willRetry,
            'error_message' => $this->exception->getMessage(),
            'failed_at'     => $this->failedAt,
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
     * Get the job data associated with the failed message.
     *
     * @return array<string, mixed> The job data
     */
    public function getJobData(): array
    {
        return $this->jobData;
    }

    public function getException(): \Exception
    {
        return $this->exception;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function getFailedAt(): int
    {
        return $this->failedAt;
    }

    public function willRetry(): bool
    {
        return $this->willRetry;
    }
}
