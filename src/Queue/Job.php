<?php

declare(strict_types=1);

namespace MonkeysLegion\Mail\Queue;

use MonkeysLegion\Mail\Logger\Logger;

class Job implements JobInterface
{
    private array $fullJobData; // Store the complete Redis job structure
    private int $attempts = 0;

    public function __construct(
        private array $data,
        private QueueInterface $queue,
        private Logger $logger
    ) {
        try {
            $this->fullJobData = $data; // Store complete structure
            $this->data = $data['data']; // Extract inner data for job execution
            $this->attempts = $data['attempts'] ?? 0;

            $this->logger->log("Job constructed", [
                'job_id' => $this->getId(),
                'job_class' => $this->fullJobData['job'] ?? 'unknown',
                'attempts' => $this->attempts
            ]);
        } catch (\Throwable $e) {
            throw new \RuntimeException("Failed to construct job: " . $e->getMessage(), 0, $e);
        }
    }

    public function handle(): void
    {
        $jobClass = $this->fullJobData['job'];

        $this->logger->log("Starting job execution", [
            'job_id' => $this->getId(),
            'job_class' => $jobClass,
            'attempts' => $this->attempts
        ]);

        if (!$jobClass || !class_exists($jobClass)) {
            $this->logger->log("Job class does not exist", [
                'job_id' => $this->getId(),
                'job_class' => $jobClass
            ]);
            throw new \InvalidArgumentException("Job class {$jobClass} does not exist");
        }

        try {
            $jobInstance = new $jobClass($this->data);

            if (!method_exists($jobInstance, 'handle')) {
                $this->logger->log("Job class missing handle method", [
                    'job_id' => $this->getId(),
                    'job_class' => $jobClass
                ]);
                throw new \InvalidArgumentException("Job class {$jobClass} must have a handle method");
            }

            $jobInstance->handle();

            $this->logger->log("Job executed successfully", [
                'job_id' => $this->getId(),
                'job_class' => $jobClass,
                'attempts' => $this->attempts
            ]);
        } catch (\Exception $e) {
            // Increment attempts and re-throw
            $this->attempts++;

            $this->logger->log("Job execution failed", [
                'job_id' => $this->getId(),
                'job_class' => $jobClass,
                'attempts' => $this->attempts,
                'exception' => $e,
                'error_message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
    }

    public function getData(): array
    {
        return $this->fullJobData; // Return complete job structure for retry
    }

    public function getId(): string
    {
        return $this->fullJobData['id'] ?? '';
    }

    public function attempts(): int
    {
        return $this->attempts;
    }

    public function fail(\Exception $exception): void
    {
        $this->logger->log("Job marked as failed", [
            'job_id' => $this->getId(),
            'job_class' => $this->fullJobData['job'] ?? 'unknown',
            'attempts' => $this->attempts,
            'exception' => $exception,
            'error_message' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);

        // push to failed jobs queue
        $this->queue->pushToFailed($this->getData(), $exception);
    }
}
