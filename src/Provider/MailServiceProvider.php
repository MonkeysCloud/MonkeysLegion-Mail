<?php

declare(strict_types=1);

namespace MonkeysLegion\Mail\Provider;

// =================================================================
// CONFIGURATION CONSTANTS
// =================================================================
define('MAIL_CONFIG_DEFAULT_PATH', __DIR__ . '/../../config/mail.php');
define('MAIL_CONFIG_PATH', WORKING_DIRECTORY . '/config/mail.' . ($_ENV['APP_ENV'] ?? 'dev') . '.php');
define('REDIS_CONFIG_PATH', __DIR__ . '/../../config/redis.php');
define('RATELIMITER_CONFIG_PATH', __DIR__ . '/../../config/rate_limiter.php');

use MonkeysLegion\Cli\Console\Command;
use MonkeysLegion\Core\Contracts\FrameworkLoggerInterface;
use MonkeysLegion\Core\Logger\MonkeyLogger;
use MonkeysLegion\DI\ContainerBuilder;
use MonkeysLegion\Mail\Cli\Command\MailInstallCommand;
use MonkeysLegion\Mail\Cli\Command\MailMakeCommand;
use MonkeysLegion\Mail\Mailer;
use MonkeysLegion\Mail\MailerFactory;
use MonkeysLegion\Mail\Queue\QueueInterface;
use MonkeysLegion\Mail\Queue\RedisQueue;
use MonkeysLegion\Mail\Queue\Worker;
use MonkeysLegion\Mail\RateLimiter\RateLimiter;
use MonkeysLegion\Mail\Service\ServiceContainer;
use MonkeysLegion\Mail\Template\Renderer;
use MonkeysLegion\Mail\TransportInterface;
use MonkeysLegion\Template\MLView;

class MailServiceProvider
{
    public function __construct() {}

    // =================================================================
    // MAIN REGISTRATION METHOD
    // =================================================================

    public static function register(ContainerBuilder $c): void
    {
        $in_container = ServiceContainer::getInstance();
        $logger = self::initializeLogger($in_container);

        try {
            // Load configurations
            $configs = self::loadConfigurations($logger);
            self::storeConfigurations($in_container, $configs);

            // Register rate limiter
            self::registerRateLimiter($in_container);

            // Register core services
            self::registerCoreServices($in_container);

            // Register transport layer
            self::registerTransport($in_container, $configs['mail'], $logger);

            // Register queue system
            self::registerQueueSystem($in_container);

            // Register worker
            self::registerWorker($in_container);

            // Register factories
            self::registerFactories($in_container);

            // Register main services
            self::registerMainServices($in_container);

            // Register CLI commands
            self::registerCommands($in_container);

            // Build to external container
            self::build($c, $in_container);
        } catch (\Exception $e) {
            error_log("Mail service provider registration failed: " . $e->getMessage());
            // Don't re-throw to prevent blocking the entire applicationn
        }
    }

    // =================================================================
    // INITIALIZATION
    // =================================================================

    private static function initializeLogger(ServiceContainer $container): FrameworkLoggerInterface
    {
        $container->set(FrameworkLoggerInterface::class, fn() => new MonkeyLogger());
        return $container->get(FrameworkLoggerInterface::class);
    }

    private static function loadConfigurations(FrameworkLoggerInterface $logger): array
    {
        $mailConfig = [];

        if (file_exists(MAIL_CONFIG_PATH)) {
            $mailConfig = require MAIL_CONFIG_PATH;
        } else {
            $fallback = base_path('/config/mail.' . ($_ENV['APP_ENV'] ?? 'dev') . '.php');
            if (file_exists($fallback)) {
                $mailConfig = require $fallback;
            }
        }

        $defaults = file_exists(MAIL_CONFIG_DEFAULT_PATH) ? require MAIL_CONFIG_DEFAULT_PATH : [];
        $mergedMailConfig = array_replace_recursive($defaults, $mailConfig);

        $redisConfig = file_exists(REDIS_CONFIG_PATH) ? require REDIS_CONFIG_PATH : [];

        $rateLimiterConfig = file_exists(RATELIMITER_CONFIG_PATH) ? require RATELIMITER_CONFIG_PATH : [];

        return [
            'mail' => $mergedMailConfig,
            'redis' => $redisConfig,
            'rate_limiter' => $rateLimiterConfig
        ];
    }

    private static function storeConfigurations(ServiceContainer $container, array $configs): void
    {
        $container->setConfig($configs['mail'], 'mail');
        $container->setConfig($configs['redis'], 'redis');
        $container->setConfig($configs['rate_limiter'], 'rate_limiter');
    }

    // =================================================================
    // SERVICE REGISTRATION
    // =================================================================

    private static function registerCoreServices(ServiceContainer $container): void
    {
        // views and cache paths
        $viewsPath = base_path('/resources/views');
        $cachePath = base_path('/storage/cache/views');

        // Necessary classes for template rendering
        $parser = new \MonkeysLegion\Template\Parser();
        $compiler = new \MonkeysLegion\Template\Compiler($parser);
        $loader = new \MonkeysLegion\Template\Loader(
            $viewsPath,
            $cachePath
        );
        $templateRenderer = new \MonkeysLegion\Template\Renderer(
            $parser,
            $compiler,
            $loader,
            true, // cache enabled
            $cachePath
        );

        // Initialize MLView with the loader, compiler, and renderer
        // This is the main class that handles template rendering
        $mlView = new MLView(
            $loader,
            $compiler,
            $templateRenderer,
            $cachePath
        );

        // Register our Mail Renderer that uses the MonkeysLegion\Template\Renderer
        $container->set(Renderer::class, function () use ($container, $mlView) {
            return new Renderer(
                $mlView,
                $container->get(FrameworkLoggerInterface::class)
            );
        });
    }

    private static function registerTransport(ServiceContainer $container, array $mailConfig, FrameworkLoggerInterface $logger): void
    {
        $container->set(TransportInterface::class, function () use ($mailConfig, $logger) {
            try {
                $driver = $mailConfig['driver'] ?? 'null';
                $driverConfig = $mailConfig['drivers'][$driver] ?? [];

                // Merge driver-specific config with global config
                $fullConfig = array_merge($mailConfig, $driverConfig, ['driver' => $driver]);

                return MailerFactory::make($fullConfig, $logger);
            } catch (\Exception $e) {
                throw $e;
            }
        });
    }

    private static function registerQueueSystem(ServiceContainer $container): void
    {
        // Redis Queue Interface
        $container->set(QueueInterface::class, function () use ($container) {
            $redisConfig = $container->getConfig('redis');
            $queueConfig = $redisConfig['queue'] ?? [];
            $connectionName = $queueConfig['connection'] ?? 'default';
            $connectionConfig = $redisConfig['connections'][$connectionName] ??
                $redisConfig['connections']['default'] ?? [];

            return new RedisQueue(
                $connectionConfig['host'] ?? '127.0.0.1',
                $connectionConfig['port'] ?? 6379,
                $queueConfig['default_queue'] ?? 'emails',
                $queueConfig['key_prefix'] ?? 'queue:'
            );
        });

        // Redis Queue specifically (for failed job handling)
        $container->set(RedisQueue::class, function () use ($container) {
            return $container->get(QueueInterface::class);
        });
    }

    private static function registerWorker(ServiceContainer $container): void
    {
        $container->set(Worker::class, function () use ($container) {
            $queue = $container->get(QueueInterface::class);
            $worker = new Worker($queue, $container->get(FrameworkLoggerInterface::class));

            $redisConfig = $container->getConfig('redis');
            $workerConfig = $redisConfig['queue']['worker'] ?? [];

            $worker->setSleep($workerConfig['sleep'] ?? 3);
            $worker->setMaxTries($workerConfig['max_tries'] ?? 3);
            $worker->setMemory($workerConfig['memory'] ?? 128);
            $worker->setJobTimeout($workerConfig['timeout'] ?? 60);

            return $worker;
        });
    }

    private static function registerFactories(ServiceContainer $container): void
    {
        $container->set(MailerFactory::class, fn() => new MailerFactory($container));
    }

    private static function registerMainServices(ServiceContainer $container): void
    {
        $container->set(Mailer::class, function () use ($container) {
            return new Mailer(
                $container->get(TransportInterface::class),
                $container->get(RateLimiter::class),
                $container
            );
        });
    }

    private static function registerCommands(ServiceContainer $container): void
    {
        $container->set(Command::class, function () {
            return [
                MailInstallCommand::class,
                MailMakeCommand::class,
            ];
        });
    }

    // =================================================================
    // Service Container Build
    // =================================================================

    private static function build(ContainerBuilder $c, ServiceContainer $container): void
    {
        $c->addDefinitions([
            Mailer::class => fn() => $container->get(Mailer::class),
            Command::class => fn() => [
                MailInstallCommand::class,
                MailMakeCommand::class,
            ],
        ]);
    }

    // =================================================================
    // EXTERNAL LOGGER SUPPORT
    // =================================================================

    public static function setLogger(MonkeyLogger $logger): void
    {
        $container = ServiceContainer::getInstance();
        $container->get(FrameworkLoggerInterface::class)
            ->setEnvironment($logger->getEnvironment())
            ->setLogger($logger->getLogger());
    }

    // =================================================================
    // RATE LIMITER REGISTRATION
    // =================================================================

    private static function registerRateLimiter(ServiceContainer $container): void
    {
        $config = $container->getConfig('rate_limiter');

        $container->set(RateLimiter::class, function () use ($config) {
            return new RateLimiter(
                $config['key'],
                $config['limit'],
                $config['seconds'],
                $config['storage_path']
            );
        });
    }
}
