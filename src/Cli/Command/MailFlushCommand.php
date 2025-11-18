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

#[CommandAttr('mail:flush', 'Delete all failed jobs')]
final class MailFlushCommand extends Command
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

        $count = $queue->getFailedJobsCount();

        if ($count === 0) {
            $this->cliLine()
                ->info('No failed jobs to flush')
                ->print();
            return self::SUCCESS;
        }

        if ($this->confirm("Are you sure you want to delete $count failed jobs?", false)) {
            if ($queue->clearFailedJobs()) {
                $this->cliLine()
                    ->success('Flushed')->space()->add((string)$count, 'green', 'bold')->space()->add('failed jobs', 'green')
                    ->print();
            } else {
                $this->cliLine()
                    ->error('Failed to flush jobs')
                    ->print();
                return self::FAILURE;
            }
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
