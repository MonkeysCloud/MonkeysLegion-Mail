<?php

declare(strict_types=1);

namespace MonkeysLegion\Mail\Provider;

define('MAIL_CONFIG_DEFAULT_PATH', __DIR__ . '/../../config/mail.php');
define('MAIL_CONFIG_PATH', WORKING_DIRECTORY . '/config/mail.' . ($_ENV['APP_ENV'] ?? 'dev') . '.php');
define('REDIS_CONFIG_PATH', __DIR__ . '/../../config/redis.php');

use MonkeysLegion\Cli\Console\Command;
use MonkeysLegion\DI\ContainerBuilder;
use MonkeysLegion\Mail\Cli\Command\MailInstallCommand;
use MonkeysLegion\Mail\Cli\Command\MailMakeCommand;
use MonkeysLegion\Mail\Logger\Logger;
use MonkeysLegion\Mail\Mailer;
use MonkeysLegion\Mail\MailerFactory;
use MonkeysLegion\Mail\Queue\QueueInterface;
use MonkeysLegion\Mail\Queue\RedisQueue;
use MonkeysLegion\Mail\Queue\Worker;
use MonkeysLegion\Mail\Service\ServiceContainer;
use MonkeysLegion\Mail\Template\Renderer;
use MonkeysLegion\Mail\TransportInterface;
use Psr\Log\LoggerInterface;

class MailServiceProvider
{
    public function __construct() {}

    /**
     * Register the services provided by this provider.
     * @return array<string, callable>
     */
    public static function register(ContainerBuilder $c): void
    {
        $in_container = ServiceContainer::getInstance();

        $in_container->set(Logger::class, fn() => new Logger());
        $logger = $in_container->get(Logger::class);

        $logger->log("Starting mail service registration");

        try {
            // Load Mail configurations
            $mailConfig = file_exists(MAIL_CONFIG_PATH) ? require MAIL_CONFIG_PATH : [];
            $defaults = file_exists(MAIL_CONFIG_DEFAULT_PATH) ? require MAIL_CONFIG_DEFAULT_PATH : [];
            $mergedMailConfig = array_replace_recursive($defaults, $mailConfig);

            $logger->log("Mail configuration loaded", [
                'has_custom_config' => file_exists(MAIL_CONFIG_PATH),
                'has_defaults' => file_exists(MAIL_CONFIG_DEFAULT_PATH),
                'driver' => $mergedMailConfig['driver'] ?? 'not_set'
            ]);

            // Load Redis configurations
            $redisConfig = file_exists(REDIS_CONFIG_PATH) ? require REDIS_CONFIG_PATH : [];

            // Store configurations
            $in_container->setConfig($mergedMailConfig, 'mail');
            $in_container->setConfig($redisConfig, 'redis');

            $in_container->set(Renderer::class, function () use ($in_container) {
                $viewsPath = WORKING_DIRECTORY . '/resources/views';
                $cachePath = WORKING_DIRECTORY . '/storage/cache/views';

                return new Renderer(
                    $viewsPath,
                    $cachePath,
                    $in_container->get(Logger::class)
                );
            });

            // Register Transport Interface with proper driver config
            $in_container->set(TransportInterface::class, function () use ($mergedMailConfig, $logger) {
                try {
                    $driver = $mergedMailConfig['driver'] ?? 'null';
                    $driverConfig = $mergedMailConfig['drivers'][$driver] ?? [];

                    // Merge driver-specific config with global config
                    $fullConfig = array_merge($mergedMailConfig, $driverConfig, ['driver' => $driver]);

                    $logger->log("Creating transport", [
                        'driver' => $driver,
                        'config_keys' => array_keys($fullConfig)
                    ]);

                    return MailerFactory::make($fullConfig, $logger);
                } catch (\Exception $e) {
                    $logger->log("Failed to create mail transport", [
                        'exception' => $e,
                        'error_message' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                    throw $e;
                }
            });

            // Register Queue Interface (Redis Queue) - internal only
            $in_container->set(QueueInterface::class, function () use ($in_container) {
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
            });

            // Register Redis Queue specifically (for failed job handling) - internal only
            $in_container->set(RedisQueue::class, function () use ($in_container) {
                return $in_container->get(QueueInterface::class);
            });

            // Register Queue Worker - internal only
            $in_container->set(Worker::class, function () use ($in_container) {
                $queue = $in_container->get(QueueInterface::class);
                $worker = new Worker($queue, $in_container->get(Logger::class));

                $redisConfig = $in_container->getConfig('redis');
                $workerConfig = $redisConfig['queue']['worker'] ?? [];

                $worker->setSleep($workerConfig['sleep'] ?? 3);
                $worker->setMaxTries($workerConfig['max_tries'] ?? 3);
                $worker->setMemory($workerConfig['memory'] ?? 128);
                $worker->setJobTimeout($workerConfig['timeout'] ?? 60);

                return $worker;
            });

            // Register Mailer Factory - internal only
            $in_container->set(MailerFactory::class, function () use ($in_container) {
                return new MailerFactory($in_container);
            });

            // Register Mailer - internal, but also expose to external container
            $in_container->set(Mailer::class, function () use ($in_container) {
                return new Mailer($in_container->get(TransportInterface::class), $in_container);
            });
            // Register ONLY the public API that users should access
            $c->addDefinitions([
                Mailer::class => fn() => $in_container->get(Mailer::class),
                Command::class => [
                    MailInstallCommand::class,
                    MailMakeCommand::class,
                ]
            ]);
        } catch (\Exception $e) {
            $logger->log("Mail service provider registration failed", [
                'exception' => $e,
                'error_message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            error_log("Mail service provider registration failed: " . $e->getMessage());
            // Don't re-throw to prevent blocking the entire application
        }
    }

    public static function setLogger(LoggerInterface $logger): void
    {
        $container = ServiceContainer::getInstance();
        $container->get(Logger::class)->setLogger($logger);
    }
}
