<?php

declare(strict_types=1);

require_once __DIR__ . '/src/Template/helpers.php';

$source = __DIR__ . '/config/mail.php';

if (!file_exists($source)) {
    echo "❌ Source config file does not exist: $source\n";
    exit(1);
}

$destination = WORKING_DIRECTORY . '/config/mail.' . ($_ENV['APP_ENV'] ?? 'dev') . '.php';

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

if (copy($source, $destination)) {
    echo "✅ Config file copied to: $destination\n";
} else {
    echo "❌ Failed to copy config file to: $destination\n";
    exit(1);
}
