<?php

declare(strict_types=1);

namespace MonkeysLegion\Mail\Queue;

use MonkeysLegion\Logger\Contracts\MonkeysLoggerInterface;
use MonkeysLegion\Mail\Message;

/**
 * @phpstan-type JobData array{
 *     id: string,
 *     job: string,
 *     message: Message,
 *     attempts: int,
 *     created_at: float,
 *     retried_at?: float
 * }
 */
class Job implements JobInterface
{
    /** @var JobData */
    private array $fullJobData;
    private int $attempts = 0;
    private Message $message;

    /**
     * Job constructor.
     * @param array<string, mixed> $data The job data from Redis
     * @param QueueInterface $queue The queue instance to push failed jobs
     * @param ?MonkeysLoggerInterface $logger Logger instance for logging job events
     */
    public function __construct(
        array $data,
        private QueueInterface $queue,
        private ?MonkeysLoggerInterface $logger
    ) {
        try {
            // Validate and normalize job data structure
            $this->fullJobData = $this->validateAndNormalizeJobData($data);
            $this->attempts = $this->fullJobData['attempts'];
            $this->message = $this->fullJobData['message'];

            $this->logger?->smartLog("Job constructed", [
                'job_id' => $this->getId(),
                'job_class' => $this->fullJobData['job'],
                'attempts' => $this->attempts,
                'message_to' => $this->message->getTo(),
                'message_subject' => $this->message->getSubject()
            ]);
        } catch (\Throwable $e) {
            throw new \RuntimeException("Failed to construct job: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Validate and normalize job data structure
     * @param array<string, mixed> $data
     * @return JobData
     * @throws \RuntimeException
     */
    private function validateAndNormalizeJobData(array $data): array
    {
        // Validate required fields
        if (!isset($data['id']) || !is_string($data['id']) || empty($data['id'])) {
            throw new \RuntimeException("Job data must contain a valid 'id' field");
        }

        if (!isset($data['job']) || !is_string($data['job']) || empty($data['job'])) {
            throw new \RuntimeException("Job data must contain a valid 'job' field");
        }

        if (!isset($data['message']) || !($data['message'] instanceof Message)) {
            throw new \RuntimeException("Job data must contain a valid 'message' field of type Message");
        }

        // Normalize and validate optional fields
        $attempts = 0;
        if (isset($data['attempts'])) {
            if (is_numeric($data['attempts'])) {
                $attempts = (int) $data['attempts'];
            } else {
                throw new \RuntimeException("Job data 'attempts' field must be numeric");
            }
        }

        $createdAt = microtime(true);
        if (isset($data['created_at'])) {
            if (is_numeric($data['created_at'])) {
                $createdAt = (float) $data['created_at'];
            } else {
                throw new \RuntimeException("Job data 'created_at' field must be numeric");
            }
        }

        /** @var JobData $normalizedData */
        $normalizedData = [
            'id' => $data['id'],
            'job' => $data['job'],
            'message' => $data['message'],
            'attempts' => $attempts,
            'created_at' => $createdAt,
        ];

        // Add optional retried_at if present
        if (isset($data['retried_at']) && is_numeric($data['retried_at'])) {
            $normalizedData['retried_at'] = (float) $data['retried_at'];
        }

        return $normalizedData;
    }

    public function handle(): void
    {
        $jobClass = $this->fullJobData['job'];

        $this->logger?->smartLog("Starting job execution", [
            'job_id' => $this->getId(),
            'job_class' => $jobClass,
            'attempts' => $this->attempts,
            'message_to' => $this->message->getTo()
        ]);

        if (!class_exists($jobClass)) {
            $this->logger?->error("Job class does not exist", [
                'job_id' => $this->getId(),
                'job_class' => $jobClass
            ]);
            throw new \InvalidArgumentException("Job class {$jobClass} does not exist");
        }

        try {
            $jobInstance = new $jobClass($this->message);

            if (!method_exists($jobInstance, 'handle')) {
                $this->logger?->error("Job class missing handle method", [
                    'job_id' => $this->getId(),
                    'job_class' => $jobClass
                ]);
                throw new \InvalidArgumentException("Job class {$jobClass} must have a handle method");
            }

            $jobInstance->handle();

            $this->logger?->smartLog("Job executed successfully", [
                'job_id' => $this->getId(),
                'job_class' => $jobClass,
                'attempts' => $this->attempts,
                'message_to' => $this->message->getTo()
            ]);
        } catch (\Exception $e) {
            // Increment attempts and re-throw
            $this->attempts++;

            $this->logger?->error("Job execution failed", [
                'job_id' => $this->getId(),
                'job_class' => $jobClass,
                'attempts' => $this->attempts,
                'exception' => $e,
                'error_message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'message_to' => $this->message->getTo()
            ]);

            throw $e;
        }
    }

    /** @return JobData */
    public function getData(): array
    {
        return $this->fullJobData;
    }

    public function getId(): string
    {
        return $this->fullJobData['id'];
    }

    public function attempts(): int
    {
        return $this->attempts;
    }

    public function fail(\Exception $exception): void
    {
        $this->logger?->error("Job marked as failed", [
            'job_id' => $this->getId(),
            'job_class' => $this->fullJobData['job'],
            'attempts' => $this->attempts,
            'exception' => $exception,
            'error_message' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);

        // push to failed jobs queue
        $this->queue->pushToFailed($this->getData(), $exception);
    }
}
