<?php

declare(strict_types=1);

namespace MonkeysLegion\Mail\Provider;

if (!defined('WORKING_DIRECTORY')) {
    define('WORKING_DIRECTORY', getcwd() . '/..');
}
define('MAIL_CONFIG_PATH', WORKING_DIRECTORY . '/config/mail.php');
define('MAIL_CONFIG_DEFAULT_PATH', WORKING_DIRECTORY . '/config/mail.' . ($_ENV['APP_ENV'] ?? 'default') . '.php');


use MonkeysLegion\DI\ContainerBuilder;
use MonkeysLegion\Mail\Mailer;
use MonkeysLegion\Mail\MailerFactory;
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
            $mailConfig = file_exists(MAIL_CONFIG_PATH) ? require MAIL_CONFIG_PATH : [];
            $defaults = file_exists(MAIL_CONFIG_DEFAULT_PATH) ? require MAIL_CONFIG_DEFAULT_PATH : [];

            // Simple array merge if configMerger doesn't exist
            if (function_exists('configMerger')) {
                $mergedMailConfig = configMerger($mailConfig, $defaults);
            } else {
                $mergedMailConfig = array_merge($defaults, $mailConfig);
            }

            if (empty($mergedMailConfig['driver'])) {
                $mergedMailConfig['driver'] = 'smtp'; // Default driver
            }

            $in_container->setConfig($mergedMailConfig, 'mail');

            $in_container->set(TransportInterface::class, function () use ($mergedMailConfig) {
                try {
                    return MailerFactory::make($mergedMailConfig);
                } catch (\Exception $e) {
                    error_log("Failed to create mail transport: " . $e->getMessage());
                    throw $e;
                }
            });

            $in_container->set(Mailer::class, function () use ($in_container) {
                try {
                    return new Mailer($in_container->get(TransportInterface::class));
                } catch (\Exception $e) {
                    error_log("Failed to create mailer: " . $e->getMessage());
                    throw $e;
                }
            });

            // Register mail services
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
