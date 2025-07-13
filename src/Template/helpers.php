<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Dotenv\Dotenv;
use Composer\InstalledVersions;

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

if (!defined('WORKING_DIRECTORY')) {
    try {
        $all = InstalledVersions::getAllRawData();
        $installPath = $all[1]['root']['install_path'] ?? null;

        if ($installPath && is_dir($installPath)) {
            define('WORKING_DIRECTORY', realpath($installPath));
        } else {
            define('WORKING_DIRECTORY', realpath(getcwd()));
        }
    } catch (\Throwable $e) {
        define('WORKING_DIRECTORY', realpath(getcwd()));
    }
}

$envFiles = [
    '.env',
    '.env.local',
    '.env.dev',
    '.env.dev.local',
    '.env.test',
    '.env.test.local',
    '.env.prod',
    '.env.prod.local',
];

try {
    foreach ($envFiles as $envFile) {
        $path = WORKING_DIRECTORY . "/$envFile";
        if (file_exists($path)) {
            $dotenv = Dotenv::createImmutable(WORKING_DIRECTORY, $envFile);
            $dotenv->safeLoad(); // Loads without overwriting already-loaded variables
        }
    }
} catch (Exception $e) {
    throw new RuntimeException("Error loading .env file: " . $e->getMessage());
}
