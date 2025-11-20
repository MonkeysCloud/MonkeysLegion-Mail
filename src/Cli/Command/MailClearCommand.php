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

#[CommandAttr('mail:clear', 'Clear pending jobs from queue')]
final class MailClearCommand extends Command
{
    use MakerHelpers, Cli;

    public function handle(): int
    {
        $queueName = $this->argument(0);

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

        $size = $queue->size($queueName);

        if ($size === 0) {
            $this->cliLine()
                ->info('No pending jobs to clear')
                ->print();
            return self::SUCCESS;
        }

        $queueDisplayName = $queueName ?? 'default';
        if ($this->confirm("Are you sure you want to clear $size pending jobs from queue '$queueDisplayName'?", false)) {
            if ($queue->clear($queueName)) {
                $this->cliLine()
                    ->success('Cleared')->space()->add((string)$size, 'green', 'bold')->space()->add('pending jobs', 'green')
                    ->print();
            } else {
                $this->cliLine()
                    ->error('Failed to clear jobs')
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
