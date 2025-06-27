<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Dotenv\Dotenv;
use Composer\InstalledVersions;

if (!defined('WORKING_DIRECTORY')) {
    try {
        $all = InstalledVersions::getAllRawData();
        $installPath = null;

        if (isset($all['root']['install_path'])) {
            $installPath = $all['root']['install_path'];
        }

        define('WORKING_DIRECTORY', realpath($installPath));
    } catch (\Throwable $e) {
        define('WORKING_DIRECTORY', realpath(getcwd()));
    }

    if (!$installPath || !is_dir($installPath)) {
        define('WORKING_DIRECTORY', realpath(getcwd()));
    }
}

$envFiles = [
    '.env',
    '.env.local',
    '.env.dev',
    '.env.test',
    '.env.prod',
];
try {
    foreach ($envFiles as $envFile) {
        if (file_exists(WORKING_DIRECTORY . '/' . $envFile)) {
            $dotenv = Dotenv::createImmutable(WORKING_DIRECTORY, $envFile);
            $dotenv->safeLoad();
        }
    }
} catch (Exception $e) {
    throw new RuntimeException("Error loading .env file: " . $e->getMessage());
}

if (!function_exists('dd')) {
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
}
