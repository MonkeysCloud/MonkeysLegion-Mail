<?php

declare(strict_types=1);

namespace MonkeysLegion\Mail\Provider;

use MonkeysLegion\Cli\Console\Command;
use MonkeysLegion\Logger\Contracts\MonkeysLoggerInterface;
use MonkeysLegion\Core\Provider\ProviderInterface;
use MonkeysLegion\DI\ContainerBuilder;
use MonkeysLegion\Mail\Cli\Command\MailInstallCommand;
use MonkeysLegion\Mail\Cli\Command\MailMakeCommand;
use MonkeysLegion\Mail\Config\RedisConfig;
use MonkeysLegion\Mail\Config\RedisConnectionConfig;
use MonkeysLegion\Mail\Config\RedisQueueConfig;
use MonkeysLegion\Mail\Config\RedisQueueWorkerConfig;
use MonkeysLegion\Mail\Enums\MailDefaults;
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

class MailServiceProvider implements ProviderInterface
{
    public function __construct() {}

    // =================================================================
    // MAIN REGISTRATION METHOD
    // =================================================================

    public static function register(string $root, ContainerBuilder $c): void
    {
        $in_container = ServiceContainer::getInstance();

        /** @var MonkeysLoggerInterface $logger */
        $logger = $in_container->get(MonkeysLoggerInterface::class);

        try {
            // Load configurations
            $configs = self::loadConfigurations($root);
            self::storeConfigurations($in_container, $configs);

            // Build Redis configuration objects
            self::buildRedisConfiguration($in_container, $configs['redis']);

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

            // Build to external container
            self::build($c, $in_container);
        } catch (\Exception $e) {
            $logger->error("Mail service provider registration failed", [
                'exception' => $e,
                'error_message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            // Don't re-throw to prevent blocking the entire application
        }
    }

    // =================================================================
    // INITIALIZATION
    // =================================================================

    /**
     * Load mail, redis, and rate limiter configurations.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function loadConfigurations(string $root): array
    {
        $mailConfigDefaultPath = __DIR__ . '/../../config/mail.php';
        $mailConfigPath = $root . '/config/mail.php';
        $redisConfigPath = __DIR__ . '/../../config/redis.php';
        $rateLimiterConfigPath = __DIR__ . '/../../config/rate_limiter.php';

        /** @var array<string, mixed> $mailConfig */
        $mailConfig = file_exists($mailConfigPath) ? require $mailConfigPath : [];

        /** @var array<string, mixed> $defaults */
        $defaults = file_exists($mailConfigDefaultPath) ? require $mailConfigDefaultPath : [];

        /** @var array<string, mixed> $mergedMailConfig */
        $mergedMailConfig = array_replace_recursive($defaults, $mailConfig);

        /** @var array<string, mixed> $redisConfig */
        $redisConfig = file_exists($redisConfigPath) ? require $redisConfigPath : [];

        /** @var array<string, mixed> $rateLimiterConfig */
        $rateLimiterConfig = file_exists($rateLimiterConfigPath) ? require $rateLimiterConfigPath : [];

        return [
            'mail' => $mergedMailConfig,
            'redis' => $redisConfig,
            'rate_limiter' => $rateLimiterConfig
        ];
    }

    /**
     * Store configurations in the service container.
     *
     * @param ServiceContainer $container
     * @param array<string, array<string, mixed>> $configs
     */
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

        /** @var MonkeysLoggerInterface $logger */
        $logger = $container->get(MonkeysLoggerInterface::class);
        // Register our Mail Renderer that uses the MonkeysLegion\Template\Renderer
        $container->set(Renderer::class, function () use ($mlView, $logger) {
            return new Renderer(
                $mlView,
                $logger
            );
        });
    }

    /**
     * Register Transport based on thee provided config
     * 
     * @param ServiceContainer $container
     * @param array<string, mixed> $mailConfig
     * @param MonkeysLoggerInterface $logger
     */
    private static function registerTransport(ServiceContainer $container, array $mailConfig, MonkeysLoggerInterface $logger): void
    {
        $container->set(TransportInterface::class, function () use ($mailConfig, $logger) {
            try {
                return MailerFactory::make($mailConfig, $logger);
            } catch (\Exception $e) {
                $logger->error("Failed to create mail transport", [
                    'exception' => $e,
                    'error_message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                throw $e;
            }
        });
    }

    // =================================================================
    // REDIS CONFIGURATION BUILDING
    // =================================================================

    /**
     * Build Redis configuration
     *
     * @param ServiceContainer $container
     * @param array<string, mixed> $redisConfig
     * @return void
     * @throws \RuntimeException
     */
    private static function buildRedisConfiguration(ServiceContainer $container, array $redisConfig): void
    {
        try {
            if (
                !isset($redisConfig['connections']) ||
                !is_array($redisConfig['connections']) ||
                empty($redisConfig['connections'])
            ) {
                throw new \RuntimeException('No Redis Connections are provided, provide at least one');
            }

            /** @var array<string, mixed> $configConnections */
            $configConnections = $redisConfig['connections'];

            // Validate each connection config
            $connections = [];
            foreach ($configConnections as $name => $connectionData) {
                if (
                    !is_array($connectionData) ||
                    !isset($connectionData['host'], $connectionData['port'], $connectionData['password'], $connectionData['database'], $connectionData['timeout'])
                ) {
                    throw new \RuntimeException("Invalid Redis connection configuration for: {$name}");
                }

                $connections[$name] = new RedisConnectionConfig(
                    safeString($connectionData['host'], MailDefaults::REDIS_HOST),
                    (int)safeString($connectionData['port'], (string)MailDefaults::REDIS_PORT),
                    safeString($connectionData['password'], MailDefaults::REDIS_PASSWORD),
                    (int)safeString($connectionData['database'], (string)MailDefaults::REDIS_DB),
                    (int)safeString($connectionData['timeout'], (string)MailDefaults::REDIS_TIMEOUT)
                );
            }

            if (
                !isset($redisConfig['queue']) ||
                !is_array($redisConfig['queue']) ||
                !isset($redisConfig['queue']['worker']) ||
                !is_array($redisConfig['queue']['worker']) ||
                !isset($redisConfig['queue']['connection'], $redisConfig['queue']['default_queue'], $redisConfig['queue']['key_prefix'], $redisConfig['queue']['failed_jobs_key'])
            ) {
                throw new \RuntimeException("Invalid or incomplete Redis queue configuration");
            }

            $worker = $redisConfig['queue']['worker'];

            if (
                !isset($worker['sleep'], $worker['max_tries'], $worker['memory'], $worker['timeout'])
            ) {
                throw new \RuntimeException("Incomplete Redis worker configuration");
            }

            $workerConfig = new RedisQueueWorkerConfig(
                (int) safeString($worker['sleep'], (string)MailDefaults::QUEUE_WORKER_SLEEP),
                (int) safeString($worker['max_tries'], (string)MailDefaults::QUEUE_WORKER_MAX_TRIES),
                (int) safeString($worker['memory'], (string)MailDefaults::QUEUE_WORKER_MEMORY),
                (int) safeString($worker['timeout'], (string)MailDefaults::QUEUE_WORKER_TIMEOUT)
            );

            $queueConfig = new RedisQueueConfig(
                safeString($redisConfig['queue']['connection'], MailDefaults::QUEUE_CONNECTION),
                safeString($redisConfig['queue']['default_queue'], MailDefaults::QUEUE_NAME),
                safeString($redisConfig['queue']['key_prefix'], MailDefaults::QUEUE_PREFIX),
                safeString($redisConfig['queue']['failed_jobs_key'], MailDefaults::QUEUE_FAILED_KEY),
                $workerConfig
            );

            if (!isset($redisConfig['default']) || !is_string($redisConfig['default'])) {
                throw new \RuntimeException("Missing or invalid Redis default connection key");
            }

            $redisConfigObject = new RedisConfig(
                safeString($redisConfig['default'], MailDefaults::QUEUE_CONNECTION),
                $connections,
                $queueConfig
            );

            $container->set(RedisConfig::class, fn() => $redisConfigObject);
        } catch (\Exception $e) {
            /** @var MonkeysLoggerInterface $logger */
            $logger = $container->get(MonkeysLoggerInterface::class);
            $logger->error("Failed to build Redis configuration", [
                'exception' => $e,
                'error_message' => $e->getMessage(),
                'config' => $redisConfig
            ]);
            throw $e;
        }
    }

    private static function registerQueueSystem(ServiceContainer $container): void
    {
        // Redis Queue Interface
        $container->set(QueueInterface::class, function () use ($container) {
            /** @var RedisConfig $redisConfig */
            $redisConfig = $container->get(RedisConfig::class);
            $connectionConfig = $redisConfig->connections[$redisConfig->queue->connection];

            return new RedisQueue(
                $connectionConfig->host,
                $connectionConfig->port,
                $redisConfig->queue->defaultQueue,
                $redisConfig->queue->keyPrefix
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
            /** @var MonkeysLoggerInterface $logger */
            $logger = $container->get(MonkeysLoggerInterface::class);
            /** @var QueueInterface $queue */
            $queue = $container->get(QueueInterface::class);
            $worker = new Worker($queue, $logger);

            /** @var RedisConfig $redisConfig */
            $redisConfig = $container->get(RedisConfig::class);
            $workerConfig = $redisConfig->queue->worker;

            $worker->setSleep($workerConfig->sleep);
            $worker->setMaxTries($workerConfig->maxTries);
            $worker->setMemory($workerConfig->memory);
            $worker->setJobTimeout($workerConfig->timeout);

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
            /** @var TransportInterface $transport */
            $transport = $container->get(TransportInterface::class);

            /** @var RateLimiter $rateLimiter */
            $rateLimiter = $container->get(RateLimiter::class);

            return new Mailer(
                $transport,
                $rateLimiter,
                $container
            );
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

    public static function setLogger(MonkeysLoggerInterface $logger): void
    {
        $container = ServiceContainer::getInstance();
        $container->set(MonkeysLoggerInterface::class, fn() => $logger);
    }

    // =================================================================
    // RATE LIMITER REGISTRATION
    // =================================================================

    private static function registerRateLimiter(ServiceContainer $container): void
    {
        /** @var array<string, string|int> $config */
        $config = $container->getConfig('rate_limiter');

        $container->set(RateLimiter::class, function () use ($config) {
            return new RateLimiter(
                safeString($config['key'], MailDefaults::RATE_LIMITER_KEY),
                (int)safeString($config['limit'], (string)MailDefaults::RATE_LIMITER_LIMIT),
                (int)safeString($config['seconds'], (string)MailDefaults::RATE_LIMITER_SECONDS),
                safeString($config['storage_path'], MailDefaults::RATE_LIMITER_STORAGE_PATH)
            );
        });
    }
}
