<?php
require __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;

$projectRoot = getcwd();
$dotenv = Dotenv::createImmutable($projectRoot);
$dotenv->load();

$appEnv = $_ENV['APP_ENV'] ?? 'dev';

$source = __DIR__ . '/config/mail.php';

if (!file_exists($source)) {
    echo "❌ Source config file does not exist: $source\n";
    exit(1);
}

$destination = $projectRoot . '/config/mail/mail.' . ($appEnv) . '.php';

// Ensure the destination directory exists
if (!is_dir(dirname($destination))) {
    mkdir(dirname($destination), 0755, true);
}

// Remove the existing destination file if it exists
if (file_exists($destination)) {
    echo "⚠️  Config file already exists at: $destination\n";
    echo "Do you want to overwrite it? (y/N): ";
    $confirm = trim(fgets(STDIN));
    if (strtolower($confirm) !== 'y') {
        echo "⏭️  Skipped.\n";
        exit(0);
    }
    unlink($destination); // Remove the existing file
}

$output = shell_exec('cp ' . $source . ' ' . $destination);
echo "✅ Config file copied to: $destination\n";
