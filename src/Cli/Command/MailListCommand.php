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

#[CommandAttr('mail:list', 'List pending jobs in queue')]
final class MailListCommand extends Command
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

        $this->cliLine()
            ->info('Queue:')->space()->add($queueName ?? 'default', 'cyan')
            ->print();
        $this->cliLine()
            ->info('Pending jobs:')->space()->add((string)$size, 'yellow')
            ->print();

        if ($size > 0) {
            echo "\n";
            $this->cliLine()
                ->muted("Use 'mail:work' to process these jobs")
                ->print();
        }

        return self::SUCCESS;
    }
}
