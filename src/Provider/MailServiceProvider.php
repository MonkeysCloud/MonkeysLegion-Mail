<?php

declare(strict_types=1);

namespace MonkeysLegion\Mail\Provider;

define('MAIL_CONFIG_DEFAULT_PATH', __DIR__ . '/../../config/mail.php');
define('MAIL_CONFIG_PATH', WORKING_DIRECTORY . '/config/mail.' . ($_ENV['APP_ENV'] ?? 'dev') . '.php');
define('REDIS_CONFIG_PATH', __DIR__ . '/../../config/redis.php');

use MonkeysLegion\DI\ContainerBuilder;
use MonkeysLegion\Mail\Mailer;
use MonkeysLegion\Mail\MailerFactory;
use MonkeysLegion\Mail\Queue\QueueInterface;
use MonkeysLegion\Mail\Queue\RedisQueue;
use MonkeysLegion\Mail\Queue\Worker;
use MonkeysLegion\Mail\Service\ServiceContainer;
use MonkeysLegion\Mail\TransportInterface;
// use Psr\Log\LoggerInterface;

class MailServiceProvider
{
    public function __construct() {}

    /**
     * Register the services provided by this provider.
     * @return array<string, callable>
     */
    public static function register(ContainerBuilder $c): void
    {
        try {
            $in_container = ServiceContainer::getInstance();

            // Load configurations
            $mailConfig = file_exists(MAIL_CONFIG_PATH) ? require MAIL_CONFIG_PATH : [];
            $defaults = file_exists(MAIL_CONFIG_DEFAULT_PATH) ? require MAIL_CONFIG_DEFAULT_PATH : [];
            $redisConfig = file_exists(REDIS_CONFIG_PATH) ? require REDIS_CONFIG_PATH : [];

            // Simple array merge if configMerger doesn't exist
            if (function_exists('configMerger')) {
                $mergedMailConfig = configMerger($mailConfig, $defaults);
            } else {
                $mergedMailConfig = array_merge($defaults, $mailConfig);
            }

            // Store configurations
            $in_container->setConfig($mergedMailConfig, 'mail');
            $in_container->setConfig($redisConfig, 'redis');

            // Register Transport Interface
            $in_container->set(TransportInterface::class, function () use ($mergedMailConfig) {
                try {
                    return MailerFactory::make($mergedMailConfig);
                } catch (\Exception $e) {
                    error_log("Failed to create mail transport: " . $e->getMessage());
                    throw $e;
                }
            });

            // Register Queue Interface (Redis Queue) - internal only
            $in_container->set(QueueInterface::class, function () use ($in_container) {
                try {
                    $redisConfig = $in_container->getConfig('redis');
                    $queueConfig = $redisConfig['queue'] ?? [];
                    $connectionName = $queueConfig['connection'] ?? 'default';
                    $connectionConfig = $redisConfig['connections'][$connectionName] ?? $redisConfig['connections']['default'] ?? [];

                    return new RedisQueue(
                        $connectionConfig['host'] ?? '127.0.0.1',
                        $connectionConfig['port'] ?? 6379,
                        $queueConfig['default_queue'] ?? 'emails',
                        $queueConfig['key_prefix'] ?? 'queue:'
                    );
                } catch (\Exception $e) {
                    error_log("Failed to create Redis queue: " . $e->getMessage());
                    throw $e;
                }
            });

            // Register Redis Queue specifically (for failed job handling) - internal only
            $in_container->set(RedisQueue::class, function () use ($in_container) {
                return $in_container->get(QueueInterface::class);
            });

            // Register Queue Worker - internal only
            $in_container->set(Worker::class, function () use ($in_container) {
                try {
                    $queue = $in_container->get(QueueInterface::class);
                    $worker = new Worker($queue);

                    $redisConfig = $in_container->getConfig('redis');
                    $workerConfig = $redisConfig['queue']['worker'] ?? [];

                    $worker->setSleep($workerConfig['sleep'] ?? 3);
                    $worker->setMaxTries($workerConfig['max_tries'] ?? 3);
                    $worker->setMemory($workerConfig['memory'] ?? 128);
                    $worker->setJobTimeout($workerConfig['timeout'] ?? 60);

                    return $worker;
                } catch (\Exception $e) {
                    error_log("Failed to create queue worker: " . $e->getMessage());
                    throw $e;
                }
            });

            // Register Mailer Factory - internal only
            $in_container->set(MailerFactory::class, function () use ($in_container) {
                return new MailerFactory($in_container);
            });

            // Register Mailer - internal, but also expose to external container
            $in_container->set(Mailer::class, function () use ($in_container) {
                try {
                    return new Mailer($in_container->get(TransportInterface::class), $in_container);
                } catch (\Exception $e) {
                    error_log("Failed to create mailer: " . $e->getMessage());
                    throw $e;
                }
            });

            // Register ONLY the public API that users should access
            $c->addDefinitions([
                Mailer::class => fn() => $in_container->get(Mailer::class),
            ]);
        } catch (\Exception $e) {
            error_log("Mail service provider registration failed: " . $e->getMessage());
            // Don't re-throw to prevent blocking the entire application
        }
    }

    // public static function setLogger(LoggerInterface $logger): void {}
}
