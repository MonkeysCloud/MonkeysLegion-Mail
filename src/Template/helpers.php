<?php

declare(strict_types=1);

use Dotenv\Dotenv;

if (!defined('WORKING_DIRECTORY')) {
    $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);
    $autoloaderPath = '';

    foreach ($backtrace as $trace) {
        if (isset($trace['file']) && str_contains($trace['file'], 'vendor/autoload.php')) {
            $autoloaderPath = $trace['file'];
            break;
        }
    }

    if ($autoloaderPath) {
        $root = dirname(dirname($autoloaderPath));
    } else {
        $currentDir = __DIR__;
        while ($currentDir !== dirname($currentDir)) {
            if (file_exists($currentDir . '/composer.json')) {
                // Check if this is the skeleton app (not the package)
                $composerData = json_decode(file_get_contents($currentDir . '/composer.json'), true);
                if (isset($composerData['type']) && $composerData['type'] !== 'library') {
                    $root = $currentDir;
                    break;
                }
                if (!isset($composerData['type']) || $composerData['type'] !== 'library') {
                    $root = $currentDir;
                    break;
                }
            }
            $currentDir = dirname($currentDir);
        }

        // Final fallback
        if (!isset($root)) {
            $root = getcwd();
        }
    }

    define('WORKING_DIRECTORY', realpath($root));
}

try {
    $dotenv = Dotenv::createImmutable(WORKING_DIRECTORY);
    $dotenv->load();
    $dotenv = Dotenv::createImmutable(WORKING_DIRECTORY, '.env.' . ($_ENV['APP_ENV'] ?? 'dev'));
    $dotenv->load();
} catch (Exception $e) {
    $dotenv = Dotenv::createImmutable(WORKING_DIRECTORY . '/..');
    $dotenv->load();
    $dotenv = Dotenv::createImmutable(WORKING_DIRECTORY . '/..', '.env.' . ($_ENV['APP_ENV'] ?? 'dev'));
    $dotenv->load();
}

if (!function_exists('configMerger')) {
    /**
     * Merges the configuration from the vendor and app.
     * @param array $vendor The vendor configuration array.
     * @param array $app The app configuration array.
     * @return array The merged configuration.
     */
    function configMerger(array $vendor, array $app): array
    {
        foreach ($app as $k => $v) {
            if (is_array($v) && isset($vendor[$k]) && is_array($vendor[$k])) {
                $vendor[$k] = configMerger($vendor[$k], $v);
            } else {
                $vendor[$k] = $v;
            }
        }
        return $vendor;
    }
}

function extractEmail(string $input): ?string
{
    // Use regex to extract email between < and >
    if (preg_match('/<([^>]+)>/', $input, $matches)) {
        return $matches[1];
    }
    // If no <>, assume input itself might be an email
    return $input;
}

function isValidEmail(string $email): bool
{
    $email = extractEmail($email);
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function dd(...$args): void
{
    foreach ($args as $arg) {
        if (is_array($arg) || is_object($arg)) {
            echo '<pre>' . print_r($arg, true) . '</pre>';
        } else {
            echo htmlspecialchars((string)$arg, ENT_QUOTES, 'UTF-8');
        }
    }
    exit(1);
}
