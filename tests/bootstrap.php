<?php

declare(strict_types=1);

// Set up test environment
$_ENV['APP_ENV'] = 'testing';
$_ENV['MAIL_DRIVER'] = 'null';
$_ENV['REDIS_HOST'] = '127.0.0.1';
$_ENV['REDIS_PORT'] = '6379';

// Create test directories with proper separators
$testDirs = [
    __DIR__ . DIRECTORY_SEPARATOR . 'results',
    __DIR__ . DIRECTORY_SEPARATOR . 'coverage',
    __DIR__ . DIRECTORY_SEPARATOR . 'coverage' . DIRECTORY_SEPARATOR . 'html',
    __DIR__  . DIRECTORY_SEPARATOR . 'rate_limiter_tests'
];

foreach ($testDirs as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

// Load autoloader
require_once dirname(__DIR__) . '/vendor/autoload.php';

// Load .env if exists
if (file_exists(dirname(__DIR__) . '/.env')) {
    $dotenv = \Dotenv\Dotenv::createImmutable(dirname(__DIR__));
    $dotenv->load();
}

echo "Test environment initialized\n";
