<?php

declare(strict_types=1);

namespace MonkeysLegion\Mail\Queue;

use MonkeysLegion\Core\Contracts\FrameworkLoggerInterface;
use MonkeysLegion\Mail\Message;

class Job implements JobInterface
{
    private array $fullJobData; // Store the complete Redis job structure
    private int $attempts = 0;
    private Message $message;

    public function __construct(
        private array $data,
        private QueueInterface $queue,
        private FrameworkLoggerInterface $logger
    ) {
        try {
            $this->fullJobData = $data; // Store complete structure
            $this->attempts = $data['attempts'] ?? 0;

            // Reconstruct Message object from serialized data
            if (!isset($data['message'])) {
                throw new \RuntimeException("Missing message data in job");
            }else {
                $this->message = $data['message'];
            }

            $this->logger->smartLog("Job constructed", [
                'job_id' => $this->getId(),
                'job_class' => $this->fullJobData['job'] ?? 'unknown',
                'attempts' => $this->attempts,
                'message_to' => $this->message->getTo(),
                'message_subject' => $this->message->getSubject()
            ]);
        } catch (\Throwable $e) {
            throw new \RuntimeException("Failed to construct job: " . $e->getMessage(), 0, $e);
        }
    }

    public function handle(): void
    {
        $jobClass = $this->fullJobData['job'];

        $this->logger->smartLog("Starting job execution", [
            'job_id' => $this->getId(),
            'job_class' => $jobClass,
            'attempts' => $this->attempts,
            'message_to' => $this->message->getTo()
        ]);

        if (!$jobClass || !class_exists($jobClass)) {
            $this->logger->error("Job class does not exist", [
                'job_id' => $this->getId(),
                'job_class' => $jobClass
            ]);
            throw new \InvalidArgumentException("Job class {$jobClass} does not exist");
        }

        try {
            $jobInstance = new $jobClass($this->message);

            if (!method_exists($jobInstance, 'handle')) {
                $this->logger->error("Job class missing handle method", [
                    'job_id' => $this->getId(),
                    'job_class' => $jobClass
                ]);
                throw new \InvalidArgumentException("Job class {$jobClass} must have a handle method");
            }

            $jobInstance->handle();

            $this->logger->smartLog("Job executed successfully", [
                'job_id' => $this->getId(),
                'job_class' => $jobClass,
                'attempts' => $this->attempts,
                'message_to' => $this->message->getTo()
            ]);
        } catch (\Exception $e) {
            // Increment attempts and re-throw
            $this->attempts++;

            $this->logger->error("Job execution failed", [
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
        $this->logger->error("Job marked as failed", [
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
