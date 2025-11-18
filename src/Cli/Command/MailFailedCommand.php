<?php

declare(strict_types=1);

namespace MonkeysLegion\Mail\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;
use MonkeysLegion\Cli\Command\MakerHelpers;
use MonkeysLegion\Cli\Console\Traits\Cli;
use MonkeysLegion\Core\Contracts\FrameworkLoggerInterface;
use MonkeysLegion\Mail\Config\RedisConfig;
use MonkeysLegion\Mail\Queue\RedisQueue;
use MonkeysLegion\Mail\Service\ServiceContainer;

#[CommandAttr('mail:failed', 'List failed jobs')]
final class MailFailedCommand extends Command
{
    use MakerHelpers, Cli;

    public function handle(): int
    {
        $container = ServiceContainer::getInstance();
        /** @var FrameworkLoggerInterface $logger */
        $logger = $container->get(FrameworkLoggerInterface::class);
        /** @var RedisConfig $redisConfig */
        $redisConfig = $container->get(RedisConfig::class);

        $connectionConfig = $redisConfig->connections[$redisConfig->queue->connection];

        $queue = new RedisQueue(
            $connectionConfig->host,
            $connectionConfig->port,
            $redisConfig->queue->defaultQueue,
            $redisConfig->queue->keyPrefix
        );

        $queue->setCliMode(true);

        $failedJobs = $queue->getFailedJobs(50);
        $totalFailed = $queue->getFailedJobsCount();

        $this->cliLine()
            ->error('Failed jobs:')->space()->add((string)$totalFailed, 'red', 'bold')
            ->print();

        if (!empty($failedJobs)) {
            echo "\n";
            $this->cliLine()->add(str_repeat('─', 80), 'gray')->print();

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

                $this->cliLine()
                    ->info('ID:')->space()->add((string)$id, 'cyan')
                    ->print();
                $this->cliLine()
                    ->info('Failed at:')->space()->add($failedAt, 'yellow')
                    ->print();
                $this->cliLine()
                    ->error('Error:')->space()->add($error, 'red')
                    ->print();
                $this->cliLine()
                    ->info('Job:')->space()->add($jobName, 'white')
                    ->print();
                $this->cliLine()->add(str_repeat('─', 40), 'gray')->print();
            }

            echo "\n";
            $this->cliLine()
                ->muted("Use 'mail:retry <job_id>' to retry a specific job")
                ->print();
            $this->cliLine()
                ->muted("Use 'mail:retry --all' to retry all failed jobs")
                ->print();
        }

        return self::SUCCESS;
    }
}
