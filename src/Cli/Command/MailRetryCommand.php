<?php

declare(strict_types=1);

namespace MonkeysLegion\Mail\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;
use MonkeysLegion\Cli\Command\MakerHelpers;
use MonkeysLegion\Cli\Console\Traits\Cli;
use MonkeysLegion\Logger\Contracts\MonkeysLoggerInterface;
use MonkeysLegion\Mail\Config\RedisConfig;
use MonkeysLegion\Mail\Queue\RedisQueue;
use MonkeysLegion\Mail\Service\ServiceContainer;

#[CommandAttr('mail:retry', 'Retry failed jobs')]
final class MailRetryCommand extends Command
{
    use MakerHelpers, Cli;

    public function handle(): int
    {
        $argument = $this->argument(0);

        if (!$argument) {
            $this->cliLine()
                ->error('Please provide a job ID or --all')
                ->print();
            $this->cliLine()
                ->muted('Usage: mail:retry <job_id> or mail:retry --all')
                ->print();
            return self::FAILURE;
        }

        $container = ServiceContainer::getInstance();
        /** @var MonkeysLoggerInterface $logger */
        $logger = $container->get(MonkeysLoggerInterface::class);
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

        if ($argument === '--all') {
            $failedJobs = $queue->getFailedJobs();
            $retried = 0;

            /**
             * @var array<int, array<string, mixed>> $failedJobs
             */
            foreach ($failedJobs as $job) {
                $id = (isset($job['id']) && is_scalar($job['id'])) ? (string)$job['id'] : null;

                if ($id !== null && $queue->retryFailedJob($id)) {
                    $retried++;
                    $this->cliLine()
                        ->success('Retried job:')->space()->add($id, 'cyan')
                        ->print();
                }
            }

            echo "\n";
            $this->cliLine()
                ->success('Total retried:')->space()->add((string)$retried, 'green', 'bold')->space()->add('jobs', 'green')
                ->print();
        } else {
            if ($queue->retryFailedJob($argument)) {
                $this->cliLine()
                    ->success('Job queued for retry:')->space()->add($argument, 'cyan', 'bold')
                    ->print();
            } else {
                $this->cliLine()
                    ->error('Failed to retry job:')->space()->add($argument, 'red', 'bold')
                    ->print();
                $this->cliLine()
                    ->muted('(job not found or error occurred)')
                    ->print();
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }
}
