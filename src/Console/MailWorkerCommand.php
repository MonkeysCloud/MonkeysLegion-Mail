<?php

declare(strict_types=1);

namespace MonkeysLegion\Mail\Console;

use MonkeysLegion\Core\Contracts\FrameworkLoggerInterface;
use MonkeysLegion\Core\Logger\MonkeyLogger;
use MonkeysLegion\Mail\Config\RedisConfig;
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
    private RedisConfig $redisConfig;
    private ?Worker $worker = null;
    private FrameworkLoggerInterface $logger;

    public function __construct()
    {
        $this->bootstrapServices();
        $this->container = ServiceContainer::getInstance();
        /** @var FrameworkLoggerInterface $logger */
        $logger = $this->container->get(FrameworkLoggerInterface::class);
        $this->logger = $logger;
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
            MailServiceProvider::setLogger(new MonkeyLogger());
            MailServiceProvider::register(base_path(), new ContainerBuilder());
        } catch (\Exception $e) {
            $this->logger->error("Failed to bootstrap mail services: " . $e->getMessage(), [
                'exception' => $e,
                'trace' => $e->getTraceAsString()
            ]);
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
            /** @var RedisConfig $redisConfig */
            $redisConfig = $this->container->get(RedisConfig::class);
            $this->redisConfig = $redisConfig;

            $connectionConfig = $redisConfig->connections[$redisConfig->queue->connection];

            $this->queue = new RedisQueue(
                $connectionConfig->host,
                $connectionConfig->port,
                $redisConfig->queue->defaultQueue,
                $redisConfig->queue->keyPrefix
            );

            $this->logger->info("Redis queue initialized", [
                'host' => $connectionConfig->host,
                'port' => $connectionConfig->port,
                'queue' => $redisConfig->queue->defaultQueue
            ]);
        } catch (\Exception $e) {
            $this->logger->error("Failed to initialize queue: " . $e->getMessage(), [
                'exception' => $e,
                'trace' => $e->getTraceAsString()
            ]);
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
        $this->logger->info("Executing command: $command", ['argument' => $argument]);
        try {
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
        } catch (\Exception $e) {
            $this->logger->error("Command execution failed: $command", [
                'exception' => $e,
                'argument' => $argument,
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Start processing jobs from the queue
     */
    private function workCommand(?string $queueName = null): void
    {
        try {
            $this->worker = new Worker($this->queue, $this->logger);

            // Configure worker from Redis config
            $workerConfig = $this->redisConfig->queue->worker;
            $this->worker->setSleep($workerConfig->sleep);
            $this->worker->setMaxTries($workerConfig->maxTries);
            $this->worker->setMemory($workerConfig->memory);
            $this->worker->setJobTimeout($workerConfig->timeout);

            $this->logger->notice("Worker started", [
                'queue' => $queueName ?? 'default',
                'config' => [
                    'sleep' => $workerConfig->sleep,
                    'max_tries' => $workerConfig->maxTries,
                    'memory' => $workerConfig->memory,
                    'timeout' => $workerConfig->timeout
                ]
            ]);

            $this->worker->work($queueName);
        } catch (\Exception $e) {
            $this->logger->error("Worker command failed", [
                'exception' => $e,
                'queue' => $queueName,
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * List pending jobs in queue
     */
    private function listCommand(?string $queueName = null): void
    {
        try {
            $size = $this->queue->size($queueName);

            $this->logger->info("Queue jobs listed", [
                'queue' => $queueName ?? 'default',
                'count' => $size
            ]);

            echo "Queue: " . ($queueName ?? 'default') . "\n";
            echo "Pending jobs: $size\n";

            if ($size > 0) {
                echo "\nNote: Use 'mail:work' to process these jobs\n";
            }
        } catch (\Exception $e) {
            $this->logger->error("List command failed", [
                'exception' => $e,
                'queue' => $queueName,
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * List failed jobs
     */
    private function failedCommand(): void
    {
        try {
            $failedJobs = $this->queue->getFailedJobs(50);
            $totalFailed = $this->queue->getFailedJobsCount();

            $this->logger->notice("Failed jobs listed", ['count' => $totalFailed]);

            echo "Failed jobs: $totalFailed\n";

            if (!empty($failedJobs)) {
                echo "\nRecent failed jobs:\n";
                echo str_repeat('-', 80) . "\n";

                /**
                 * @var array<int, array<string, mixed>> $failedJobs
                 */
                foreach ($failedJobs as $job) {
                    $id = is_scalar($job['id']) ? $job['id'] : 'Unknown';
                    $failedAt = (isset($job['failed_at']) && is_numeric($job['failed_at']))
                        ? date('Y-m-d H:i:s', (int)$job['failed_at'])
                        : 'Unknown';

                    $error = (isset($job['exception']) && is_array($job['exception']) && isset($job['exception']['message']) && is_string($job['exception']['message']))
                        ? $job['exception']['message']
                        : 'Unknown';

                    $jobName = (isset($job['original_job']) && is_array($job['original_job']) && isset($job['original_job']['job']) && is_string($job['original_job']['job']))
                        ? $job['original_job']['job']
                        : 'Unknown';

                    echo "ID: $id\n";
                    echo "Failed at: $failedAt\n";
                    echo "Error: $error\n";
                    echo "Job: $jobName\n";
                    echo str_repeat('-', 40) . "\n";
                }

                echo "\nUse 'mail:retry <job_id>' to retry a specific job\n";
                echo "Use 'mail:retry --all' to retry all failed jobs\n";
            }
        } catch (\Exception $e) {
            $this->logger->error("Failed command failed", [
                'exception' => $e,
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Retry failed jobs
     */
    private function retryCommand(?string $argument = null): void
    {
        try {
            if ($argument === '--all') {
                $this->retryAllFailedJobs();
            } elseif ($argument) {
                $this->retrySpecificJob($argument);
            } else {
                echo "Usage: mail:retry <job_id> or mail:retry --all\n";
                exit(1);
            }
        } catch (\Exception $e) {
            $this->logger->error("Retry command failed", [
                'exception' => $e,
                'argument' => $argument,
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Retry all failed jobs
     */
    private function retryAllFailedJobs(): void
    {
        try {
            $failedJobs = $this->queue->getFailedJobs();
            $retried = 0;

            /**
             * @var array<int, array<string, mixed>> $failedJobs
             */
            foreach ($failedJobs as $job) {
                $id = (isset($job['id']) && is_scalar($job['id'])) ? (string)$job['id'] : null;

                if ($id !== null && $this->queue->retryFailedJob($id)) {
                    $retried++;
                    echo "Retried job: $id\n";
                }
            }

            $this->logger->notice("Retried all failed jobs", ['count' => $retried]);
            echo "Retried $retried failed jobs\n";
        } catch (\Exception $e) {
            $this->logger->error("Retry all failed jobs failed", [
                'exception' => $e,
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Retry a specific failed job
     */
    private function retrySpecificJob(string $jobId): void
    {
        try {
            if ($this->queue->retryFailedJob($jobId)) {
                $this->logger->info("Retried specific job", ['job_id' => $jobId]);
                echo "Job $jobId has been queued for retry\n";
            } else {
                $this->logger->warning("Failed to retry specific job", ['job_id' => $jobId]);
                echo "Failed to retry job $jobId (job not found or error occurred)\n";
                exit(1);
            }
        } catch (\Exception $e) {
            $this->logger->error("Retry specific job failed", [
                'exception' => $e,
                'job_id' => $jobId,
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Delete all failed jobs
     */
    private function flushCommand(): void
    {
        try {
            $count = $this->queue->getFailedJobsCount();

            if ($count === 0) {
                echo "No failed jobs to flush\n";
                return;
            }

            if ($this->confirmAction("Are you sure you want to delete $count failed jobs?")) {
                if ($this->queue->clearFailedJobs()) {
                    $this->logger->notice("Flushed failed jobs", ['count' => $count]);
                    echo "Flushed $count failed jobs\n";
                } else {
                    $this->logger->warning("Failed to flush jobs", ['count' => $count]);
                    echo "Failed to flush jobs\n";
                    exit(1);
                }
            } else {
                echo "Operation cancelled\n";
            }
        } catch (\Exception $e) {
            $this->logger->error("Flush command failed", [
                'exception' => $e,
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Clear pending jobs from queue
     */
    private function clearCommand(?string $queueName = null): void
    {
        try {
            $size = $this->queue->size($queueName);

            if ($size === 0) {
                echo "No pending jobs to clear\n";
                return;
            }

            $queueDisplayName = $queueName ?? 'default';
            if ($this->confirmAction("Are you sure you want to clear $size pending jobs from queue '$queueDisplayName'?")) {
                if ($this->queue->clear($queueName)) {
                    $this->logger->notice("Cleared pending jobs", [
                        'queue' => $queueName,
                        'count' => $size
                    ]);
                    echo "Cleared $size pending jobs\n";
                } else {
                    $this->logger->warning("Failed to clear jobs", [
                        'queue' => $queueName,
                        'count' => $size
                    ]);
                    echo "Failed to clear jobs\n";
                    exit(1);
                }
            } else {
                echo "Operation cancelled\n";
            }
        } catch (\Exception $e) {
            $this->logger->error("Clear command failed", [
                'exception' => $e,
                'queue' => $queueName,
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Delete all jobs (pending and failed)
     */
    private function purgeCommand(): void
    {
        try {
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

                $this->logger->notice("Purged all jobs", [
                    'pending_count' => $pendingCount,
                    'failed_count' => $failedCount,
                    'total_cleared' => $cleared
                ]);

                echo "Total jobs purged: $cleared\n";
            } else {
                echo "Operation cancelled\n";
            }
        } catch (\Exception $e) {
            $this->logger->error("Purge command failed", [
                'exception' => $e,
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Send a test email
     */
    private function testCommand(?string $email = null): void
    {
        try {
            if (!$email) {
                echo "Usage: mail:test <email_address>\n";
                echo "Example: mail:test user@example.com\n";
                exit(1);
            }

            // Validate email
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->logger->warning("Invalid email address provided for test", ['email' => $email]);
                echo "Error: Invalid email address '$email'\n";
                exit(1);
            }

            echo "Sending test email to: $email\n";

            /** @var \MonkeysLegion\Mail\Mailer $mailer */
            $mailer = $this->container->get(\MonkeysLegion\Mail\Mailer::class);

            $mailer->send(
                $email,
                '[TEST] Mailer setup is working',
                'This is a test message from your mail system.',
                'text/plain'
            );

            $this->logger->info("Test email sent", ['email' => $email]);
            echo "✓ Test email sent successfully!\n";
        } catch (\Exception $e) {
            $this->logger->error("Test email failed", [
                'exception' => $e,
                'email' => $email,
                'trace' => $e->getTraceAsString()
            ]);
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
            $driver = $mailConfig['driver'] ?? null;
            $currentDriver = is_array($driver) ? 'unknown' : $driver;
            $currentDriver = (is_scalar($currentDriver) || (is_object($currentDriver) && method_exists($currentDriver, '__toString')))
                ? (string) $currentDriver
                : 'unknown';

            echo "Current mail driver: {$currentDriver}\n";

            // Show Redis connection info
            echo "Redis connection: {$this->redisConfig->default}\n";
            $defaultConnection = $this->redisConfig->connections[$this->redisConfig->default];
            echo "Redis host: {$defaultConnection->host}:{$defaultConnection->port}\n";
            echo "Queue: {$this->redisConfig->queue->defaultQueue}\n";
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
        $this->logger->notice("Worker stopping gracefully");
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

        // Could not open stdin, assume no
        if ($handle === false) return false;
        $line = fgets($handle);
        fclose($handle);
        // Could not read input, assume no
        if ($line === false) return false;

        return trim(strtolower($line)) === 'y';
    }

    /**
     * Get available commands
     * @return array<string> List of available commands
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
