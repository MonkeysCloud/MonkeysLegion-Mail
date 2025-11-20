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
use MonkeysLegion\Mail\Queue\Worker;
use MonkeysLegion\Mail\Service\ServiceContainer;

#[CommandAttr('mail:work', 'Start processing jobs from the queue')]
final class MailWorkCommand extends Command
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

        // Enable CLI mode for colored output
        $queue->setCliMode(true);

        $worker = new Worker($queue, $logger);

        // Enable CLI mode for worker
        $worker->setCliMode(true);

        $workerConfig = $redisConfig->queue->worker;
        $worker->setSleep($workerConfig->sleep);
        $worker->setMaxTries($workerConfig->maxTries);
        $worker->setMemory($workerConfig->memory);
        $worker->setJobTimeout($workerConfig->timeout);

        $this->cliLine()
            ->success('Mail Queue Worker')
            ->print();
        $this->cliLine()
            ->info('Queue:')->space()->add($queueName ?? 'default', 'cyan')
            ->print();
        $this->cliLine()
            ->muted('Press Ctrl+C to stop')
            ->print();
        echo "\n";

        $worker->work($queueName);

        return self::SUCCESS;
    }
}
