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

#[CommandAttr('mail:purge', 'Delete all jobs (pending and failed)')]
final class MailPurgeCommand extends Command
{
    use MakerHelpers, Cli;

    public function handle(): int
    {
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

        $pendingCount = $queue->size();
        $failedCount = $queue->getFailedJobsCount();
        $totalJobs = $pendingCount + $failedCount;

        if ($totalJobs === 0) {
            $this->cliLine()
                ->info('No jobs to purge')
                ->print();
            return self::SUCCESS;
        }

        $this->cliLine()
            ->warning('This will delete ALL jobs:')
            ->print();
        $this->cliLine()
            ->info('- Pending jobs:')->space()->add((string)$pendingCount, 'yellow')
            ->print();
        $this->cliLine()
            ->info('- Failed jobs:')->space()->add((string)$failedCount, 'red')
            ->print();
        $this->cliLine()
            ->info('- Total:')->space()->add((string)$totalJobs, 'cyan', 'bold')
            ->print();
        echo "\n";

        if ($this->confirm("Are you sure?", false)) {
            $cleared = 0;

            if ($queue->clear()) {
                $cleared += $pendingCount;
                $this->cliLine()
                    ->success('Cleared')->space()->add((string)$pendingCount, 'green')->space()->add('pending jobs', 'green')
                    ->print();
            }

            if ($failedCount > 0) {
                if ($queue->clearFailedJobs()) {
                    $cleared += $failedCount;
                    $this->cliLine()
                        ->success('Cleared')->space()->add((string)$failedCount, 'green')->space()->add('failed jobs', 'green')
                        ->print();
                }
            }

            echo "\n";
            $this->cliLine()
                ->success('Total jobs purged:')->space()->add((string)$cleared, 'green', 'bold')
                ->print();
        } else {
            $this->cliLine()
                ->warning('Operation cancelled')
                ->print();
        }

        return self::SUCCESS;
    }

    private function confirm(string $question, bool $default = false): bool
    {
        $answer = $this->ask($question . ($default ? ' [Y/n]' : ' [y/N]'));
        if ($answer === '') {
            return $default;
        }
        return in_array(strtolower($answer), ['y', 'yes'], true);
    }
}
