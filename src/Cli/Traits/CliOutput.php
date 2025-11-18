<?php

declare(strict_types=1);

namespace MonkeysLegion\Mail\Cli\Traits;

use MonkeysLegion\Cli\Console\Traits\Cli;
use MonkeysLegion\Core\Contracts\FrameworkLoggerInterface;

/**
 * Trait for handling CLI output with colored formatting
 * Switches between colored CLI output and logger based on mode
 */
trait CliOutput
{
    use Cli;

    private bool $cliMode = false;
    private ?FrameworkLoggerInterface $logger = null;

    /**
     * Enable CLI mode for colored output instead of logging
     */
    public function setCliMode(bool $enabled = true): self
    {
        $this->cliMode = $enabled;
        return $this;
    }

    /**
     * Set the logger instance
     */
    protected function setLogger(FrameworkLoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * Output a message - either log or print based on mode
     */
    protected function output(string $message, array $context = [], string $level = 'info', bool $ignoreCli = false, bool $ignoreLogger = false): void
    {
        if ($this->cliMode) {
            if ($ignoreCli) return;
            $this->printCliMessage($message, $context, $level);
        } else {
            if ($ignoreLogger) return;
            $this->logMessage($message, $context, $level);
        }
    }

    /**
     * Log a message using the logger
     */
    private function logMessage(string $message, array $context, string $level): void
    {
        if (!$this->logger) {
            return;
        }

        match ($level) {
            'error' => $this->logger->error($message, $context),
            'warning' => $this->logger->warning($message, $context),
            'notice' => $this->logger->notice($message, $context),
            default => $this->logger->smartLog($message, $context),
        };
    }

    /**
     * Print a CLI-friendly colored message
     */
    private function printCliMessage(string $message, array $context = [], string $level = 'info'): void
    {
        $line = $this->cliLine();

        // Add timestamp prefix
        $line->muted('[' . date('H:i:s') . ']')->space();

        // Add level indicator with color
        match ($level) {
            'error' => $line->error('✗')->space()->add($message, 'red'),
            'warning' => $line->warning('⚠')->space()->add($message, 'yellow'),
            'notice' => $line->success('✓')->space()->add($message, 'green'),
            'processing' => $line->info('→')->space()->add($message, 'cyan'),
            default => $line->info('•')->space()->add($message, 'white'),
        };

        // Add important context details inline
        if (!empty($context)) {
            $details = $this->extractImportantContext($context);

            if (!empty($details)) {
                $line->space()->muted('(' . implode(', ', $details) . ')');
            }
        }

        $line->print();
    }

    /**
     * Extract important context fields for display
     */
    private function extractImportantContext(array $context): array
    {
        $importantKeys = [
            'queue',
            'job_id',
            'size',
            'count',
            'attempts',
            'max_tries',
            'duration_ms',
            'memory_usage_mb',
            'error_message'
        ];

        $details = [];

        foreach ($importantKeys as $key) {
            if (isset($context[$key])) {
                $value = is_scalar($context[$key])
                    ? (string)$context[$key]
                    : json_encode($context[$key]);
                $details[] = "$key=$value";
            }
        }

        return $details;
    }
}
