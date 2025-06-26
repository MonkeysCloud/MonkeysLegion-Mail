#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Mail Queue Worker CLI Script
 * 
 * Available commands:
 * - mail:work [queue] - Start processing jobs
 * - mail:list [queue] - List pending jobs
 * - mail:failed - List failed jobs
 * - mail:retry [job_id] - Retry a failed job or all failed jobs
 * - mail:flush - Delete all failed jobs
 * - mail:clear [queue] - Clear pending jobs from queue
 * - mail:purge - Delete all jobs (pending and failed)
 * 
 * Usage examples:
 * php worker.php mail:work
 * php worker.php mail:work high_priority
 * php worker.php mail:list
 * php worker.php mail:failed
 * php worker.php mail:retry job_12345
 * php worker.php mail:retry --all
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Template/helpers.php';

use MonkeysLegion\Mail\Console\MailWorkerCommand;

$command = $argv[1] ?? 'help';
$argument = $argv[2] ?? null;

try {
    $workerCommand = new MailWorkerCommand();
    $workerCommand->execute($command, $argument);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
