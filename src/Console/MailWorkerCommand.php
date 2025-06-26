<?php

declare(strict_types=1);

namespace MonkeysLegion\Mail\Console;

use MonkeysLegion\Mail\Provider\MailServiceProvider;
use MonkeysLegion\Mail\Queue\RedisQueue;
use MonkeysLegion\Mail\Queue\Worker;
use MonkeysLegion\Mail\Service\ServiceContainer;
use MonkeysLegion\DI\ContainerBuilder;

/**
 * Mail Queue Worker CLI Command Class
 * 
 * Handles all mail queue operations through a clean OOP interface
 */
class MailWorkerCommand
{
    private ServiceContainer $container;
    private RedisQueue $queue;
    private array $redisConfig;
    private ?Worker $worker = null;

    public function __construct()
    {
        $this->bootstrapServices();
        $this->container = ServiceContainer::getInstance();
        $this->initializeQueue();
        $this->setupSignalHandlers();
    }

    /**
     * Bootstrap all mail services before initializing
     */
    private function bootstrapServices(): void
    {
        try {

            // Register mail services (this loads configurations and registers services)
            MailServiceProvider::register(new ContainerBuilder());
        } catch (\Exception $e) {
            throw new \RuntimeException("Failed to bootstrap mail services: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Initialize the Redis queue from configuration
     */
    private function initializeQueue(): void
    {
        try {
            // Get Redis configuration from container
            $this->redisConfig = $this->container->getConfig('redis');

            $queueConfig = $this->redisConfig['queue'] ?? [];
            $connectionName = $queueConfig['connection'] ?? 'default';
            $connectionConfig = $this->redisConfig['connections'][$connectionName] ??
                $this->redisConfig['connections']['default'] ?? [];

            $this->queue = new RedisQueue(
                $connectionConfig['host'] ?? '127.0.0.1',
                $connectionConfig['port'] ?? 6379,
                $queueConfig['default_queue'] ?? 'emails',
                $queueConfig['key_prefix'] ?? 'queue:'
            );
        } catch (\Exception $e) {
            throw new \RuntimeException("Failed to initialize queue: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Setup signal handlers for graceful shutdown
     */
    private function setupSignalHandlers(): void
    {
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, [$this, 'stop']);
            pcntl_signal(SIGINT, [$this, 'stop']);
        }
    }

    /**
     * Execute a command
     * 
     * @param string $command The command to execute
     * @param string|null $argument Optional command argument
     */
    public function execute(string $command, ?string $argument = null): void
    {
        switch ($command) {
            case 'mail:work':
                $this->workCommand($argument);
                break;
            case 'mail:list':
                $this->listCommand($argument);
                break;
            case 'mail:failed':
                $this->failedCommand();
                break;
            case 'mail:retry':
                $this->retryCommand($argument);
                break;
            case 'mail:flush':
                $this->flushCommand();
                break;
            case 'mail:clear':
                $this->clearCommand($argument);
                break;
            case 'mail:purge':
                $this->purgeCommand();
                break;
            case 'mail:test':
                $this->testCommand($argument);
                break;
            case 'help':
            default:
                $this->helpCommand();
                break;
        }
    }

    /**
     * Start processing jobs from the queue
     */
    private function workCommand(?string $queueName = null): void
    {
        $this->worker = new Worker($this->queue);

        // Configure worker from Redis config
        $workerConfig = $this->redisConfig['queue']['worker'] ?? [];
        $this->worker->setSleep($workerConfig['sleep'] ?? 3);
        $this->worker->setMaxTries($workerConfig['max_tries'] ?? 3);
        $this->worker->setMemory($workerConfig['memory'] ?? 128);
        $this->worker->setJobTimeout($workerConfig['timeout'] ?? 60);

        $this->worker->work($queueName);
    }

    /**
     * List pending jobs in queue
     */
    private function listCommand(?string $queueName = null): void
    {
        $size = $this->queue->size($queueName);

        echo "Queue: " . ($queueName ?? 'default') . "\n";
        echo "Pending jobs: $size\n";

        if ($size > 0) {
            echo "\nNote: Use 'mail:work' to process these jobs\n";
        }
    }

    /**
     * List failed jobs
     */
    private function failedCommand(): void
    {
        $failedJobs = $this->queue->getFailedJobs(50);
        $totalFailed = $this->queue->getFailedJobsCount();

        echo "Failed jobs: $totalFailed\n";

        if (!empty($failedJobs)) {
            echo "\nRecent failed jobs:\n";
            echo str_repeat('-', 80) . "\n";

            foreach ($failedJobs as $job) {
                $failedAt = date('Y-m-d H:i:s', $job['failed_at']);
                echo "ID: {$job['id']}\n";
                echo "Failed at: $failedAt\n";
                echo "Error: {$job['exception']['message']}\n";
                echo "Job: {$job['original_job']['job']}\n";
                echo str_repeat('-', 40) . "\n";
            }

            echo "\nUse 'mail:retry <job_id>' to retry a specific job\n";
            echo "Use 'mail:retry --all' to retry all failed jobs\n";
        }
    }

    /**
     * Retry failed jobs
     */
    private function retryCommand(?string $argument = null): void
    {
        if ($argument === '--all') {
            $this->retryAllFailedJobs();
        } elseif ($argument) {
            $this->retrySpecificJob($argument);
        } else {
            echo "Usage: mail:retry <job_id> or mail:retry --all\n";
            exit(1);
        }
    }

    /**
     * Retry all failed jobs
     */
    private function retryAllFailedJobs(): void
    {
        $failedJobs = $this->queue->getFailedJobs();
        $retried = 0;

        foreach ($failedJobs as $job) {
            if ($this->queue->retryFailedJob($job['id'])) {
                $retried++;
                echo "Retried job: {$job['id']}\n";
            }
        }

        echo "Retried $retried failed jobs\n";
    }

    /**
     * Retry a specific failed job
     */
    private function retrySpecificJob(string $jobId): void
    {
        if ($this->queue->retryFailedJob($jobId)) {
            echo "Job $jobId has been queued for retry\n";
        } else {
            echo "Failed to retry job $jobId (job not found or error occurred)\n";
            exit(1);
        }
    }

    /**
     * Delete all failed jobs
     */
    private function flushCommand(): void
    {
        $count = $this->queue->getFailedJobsCount();

        if ($count === 0) {
            echo "No failed jobs to flush\n";
            return;
        }

        if ($this->confirmAction("Are you sure you want to delete $count failed jobs?")) {
            if ($this->queue->clearFailedJobs()) {
                echo "Flushed $count failed jobs\n";
            } else {
                echo "Failed to flush jobs\n";
                exit(1);
            }
        } else {
            echo "Operation cancelled\n";
        }
    }

    /**
     * Clear pending jobs from queue
     */
    private function clearCommand(?string $queueName = null): void
    {
        $size = $this->queue->size($queueName);

        if ($size === 0) {
            echo "No pending jobs to clear\n";
            return;
        }

        $queueDisplayName = $queueName ?? 'default';
        if ($this->confirmAction("Are you sure you want to clear $size pending jobs from queue '$queueDisplayName'?")) {
            if ($this->queue->clear($queueName)) {
                echo "Cleared $size pending jobs\n";
            } else {
                echo "Failed to clear jobs\n";
                exit(1);
            }
        } else {
            echo "Operation cancelled\n";
        }
    }

    /**
     * Delete all jobs (pending and failed)
     */
    private function purgeCommand(): void
    {
        $pendingCount = $this->queue->size();
        $failedCount = $this->queue->getFailedJobsCount();
        $totalJobs = $pendingCount + $failedCount;

        if ($totalJobs === 0) {
            echo "No jobs to purge\n";
            return;
        }

        echo "This will delete ALL jobs:\n";
        echo "- Pending jobs: $pendingCount\n";
        echo "- Failed jobs: $failedCount\n";
        echo "- Total: $totalJobs\n";

        if ($this->confirmAction("Are you sure?")) {
            $cleared = 0;

            if ($this->queue->clear()) {
                $cleared += $pendingCount;
                echo "Cleared $pendingCount pending jobs\n";
            }

            if ($failedCount > 0) {
                if ($this->queue->clearFailedJobs()) {
                    $cleared += $failedCount;
                    echo "Cleared $failedCount failed jobs\n";
                }
            }

            echo "Total jobs purged: $cleared\n";
        } else {
            echo "Operation cancelled\n";
        }
    }

    /**
     * Send a test email
     */
    private function testCommand(?string $email = null): void
    {
        if (!$email) {
            echo "Usage: mail:test <email_address>\n";
            echo "Example: mail:test user@example.com\n";
            exit(1);
        }

        // Validate email
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo "Error: Invalid email address '$email'\n";
            exit(1);
        }

        try {
            echo "Sending test email to: $email\n";

            $mailer = $this->container->get(\MonkeysLegion\Mail\Mailer::class);

            $mailer->send(
                $email,
                '[TEST] Mailer setup is working',
                'This is a test message from your mail system.',
                'text/plain'
            );

            echo "✓ Test email sent successfully!\n";
        } catch (\Exception $e) {
            echo "✗ Failed to send test email: " . $e->getMessage() . "\n";
            exit(1);
        }
    }

    /**
     * Show help information
     */
    private function helpCommand(): void
    {
        echo "Mail Queue Worker\n";
        echo "=================\n";

        // Show current driver
        try {
            $mailConfig = $this->container->getConfig('mail');
            $currentDriver = $mailConfig['driver'] ?? 'unknown';
            echo "Current mail driver: {$currentDriver}\n";
            echo str_repeat('-', 40) . "\n";
        } catch (\Exception $e) {
            // Ignore if we can't get the driver info
        }

        echo "Available commands:\n";
        echo "  mail:work [queue]     - Start processing jobs\n";
        echo "  mail:list [queue]     - List pending jobs\n";
        echo "  mail:failed           - List failed jobs\n";
        echo "  mail:retry <job_id>   - Retry a specific failed job\n";
        echo "  mail:retry --all      - Retry all failed jobs\n";
        echo "  mail:flush            - Delete all failed jobs\n";
        echo "  mail:clear [queue]    - Clear pending jobs from queue\n";
        echo "  mail:purge            - Delete all jobs (pending and failed)\n";
        echo "  mail:test <email>     - Send a test email\n";
        echo "  help                  - Show this help message\n";
        echo "\nExamples:\n";
        echo "  php worker.php mail:work\n";
        echo "  php worker.php mail:work high_priority\n";
        echo "  php worker.php mail:list\n";
        echo "  php worker.php mail:failed\n";
        echo "  php worker.php mail:retry job_12345\n";
        echo "  php worker.php mail:test user@example.com\n";
    }

    /**
     * Stop the worker gracefully
     */
    public function stop(): void
    {
        if ($this->worker) {
            $this->worker->stop();
        }
        exit(0);
    }

    /**
     * Ask for user confirmation
     */
    private function confirmAction(string $message): bool
    {
        echo "$message (y/N): ";
        $handle = fopen("php://stdin", "r");
        $line = fgets($handle);
        fclose($handle);

        return trim(strtolower($line)) === 'y';
    }

    /**
     * Get available commands
     */
    public static function getAvailableCommands(): array
    {
        return [
            'mail:work',
            'mail:list',
            'mail:failed',
            'mail:retry',
            'mail:flush',
            'mail:clear',
            'mail:purge',
            'mail:test',
            'help'
        ];
    }
}
