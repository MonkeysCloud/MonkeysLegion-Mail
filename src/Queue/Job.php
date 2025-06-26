<?php

declare(strict_types=1);

namespace MonkeysLegion\Mail\Queue;

class Job implements JobInterface
{
    private array $data;
    private array $fullJobData; // Store the complete Redis job structure
    private QueueInterface $queue;
    private int $attempts = 0;

    public function __construct(array $data, QueueInterface $queue)
    {
        $this->fullJobData = $data; // Store complete structure
        $this->data = $data['data']; // Extract inner data for job execution
        $this->queue = $queue;
        $this->attempts = $data['attempts'] ?? 0;
    }

    public function handle(): void
    {
        $jobClass = $this->fullJobData['job'];

        if (!$jobClass || !class_exists($jobClass)) {
            throw new \InvalidArgumentException("Job class {$jobClass} does not exist");
        }

        try {
            $jobInstance = new $jobClass($this->data);

            if (!method_exists($jobInstance, 'handle')) {
                throw new \InvalidArgumentException("Job class {$jobClass} must have a handle method");
            }

            $jobInstance->handle();
        } catch (\Exception $e) {
            // Increment attempts and re-throw
            $this->attempts++;
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
        error_log("Job {$this->getId()} failed: " . $exception->getMessage());
        // Optionally push to failed jobs queue
    }
}
