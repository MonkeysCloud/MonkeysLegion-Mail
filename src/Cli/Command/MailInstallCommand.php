<?php

declare(strict_types=1);

namespace MonkeysLegion\Mail\Cli\Command;

use FilesystemIterator;
use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;
use MonkeysLegion\Cli\Command\MakerHelpers;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Publish Mail scaffolding (views, config, docs) into the host app
 * without relying on the symfony/filesystem component, and ensures .env has Mail keys.
 */
#[CommandAttr('mail:install', 'Publish Mail scaffolding into your project')]
final class MailInstallCommand extends Command
{
    use MakerHelpers;

    public function handle(): int
    {
        $projectRoot = base_path();
        $stubDir = __DIR__ . '/../../../stubs';

        // 1) Copy scaffolding files
        $map = [
            "{$stubDir}/config/mail.php"                              => "{$projectRoot}/config/mail.php",
            "{$stubDir}/config/mail.mlc"                              => "{$projectRoot}/config/mail.mlc",
            "{$stubDir}/config/mail.dev.php"                   => "{$projectRoot}/config/mail/mail.dev.php",
            "{$stubDir}/config/mail.prod.php"                  => "{$projectRoot}/config/mail/mail.prod.php",
            "{$stubDir}/config/mail.test.php"                  => "{$projectRoot}/config/mail/mail.test.php",
            "{$stubDir}/resources/views/components/email-button.ml.php"      => "{$projectRoot}/resources/views/components/email-button.ml.php",
            "{$stubDir}/resources/views/components/email-card.ml.php"      => "{$projectRoot}/resources/views/components/email-card.ml.php",
            "{$stubDir}/resources/views/components/email-content.ml.php"      => "{$projectRoot}/resources/views/components/email-content.ml.php",
            "{$stubDir}/resources/views/components/email-footer.ml.php"      => "{$projectRoot}/resources/views/components/email-footer.ml.php",
            "{$stubDir}/resources/views/components/email-header.ml.php"      => "{$projectRoot}/resources/views/components/email-header.ml.php",
            "{$stubDir}/resources/views/components/email-layout.ml.php"      => "{$projectRoot}/resources/views/components/email-layout.ml.php",
            "{$stubDir}/resources/views/emails/password-reset.ml.php"   => "{$projectRoot}/resources/views/emails/password-reset.ml.php",
            "{$stubDir}/resources/views/emails/receipt.ml.php"   => "{$projectRoot}/resources/views/emails/receipt.ml.php",
            "{$stubDir}/resources/views/emails/welcome.ml.php"   => "{$projectRoot}/resources/views/emails/welcome.ml.php",
            "{$stubDir}/Mail/welcome-mail.php"   => "{$projectRoot}/app/Mail/WelcomeMail.php",
            "{$stubDir}/Mail/receipt-mail.php"   => "{$projectRoot}/app/Mail/ReceiptMail.php",
            "{$stubDir}/Mail/password-reset-mail.php"   => "{$projectRoot}/app/Mail/PasswordResetMail.php",
        ];

        foreach ($map as $from => $to) {
            if (is_dir($from)) {
                $this->mirror($from, $to);
                $this->info('✓ Published directory ' . str_replace($projectRoot . '/', '', $to));
                continue;
            }

            if (file_exists($to) && !$this->shouldOverwrite($to, $projectRoot)) {
                continue;
            }

            if ($this->copyFile($from, $to)) {
                $this->info('✓ Published file ' . str_replace($projectRoot . '/', '', $to));
            }
            $this->line('');
        }

        // 2) Ensure .env contains Mail keys
        $this->ensureEnvKeys($projectRoot);

        // 3) Patch config/app.mlc: add mail { … } section
        $this->addMailConfig($projectRoot);

        $this->line('<info>Mail scaffolding and .env setup complete!</info>');
        return self::SUCCESS;
    }

    /**
     * Ensure the MailServiceProvider is registered in config/app.php.
     *
     * @param string $projectRoot
     */
    private function ensureEnvKeys(string $projectRoot): void
    {
        $envFile = $projectRoot . '/.env';
        if (!file_exists($envFile)) {
            $this->warn('.env file not found; skipping Mail key injection.');
            return;
        }

        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $required = [
            'MAIL_DRIVER',
            'MAIL_HOST',
            'MAIL_PORT',
            'MAIL_USERNAME',
            'MAIL_PASSWORD',
            'MAIL_ENCRYPTION',
            'MAIL_FROM_ADDRESS',
            'MAIL_FROM_NAME',
            'MAIL_TIMEOUT',
            'MAIL_DKIM_PRIVATE_KEY',
            'MAIL_DKIM_SELECTOR',
            'MAIL_DKIM_DOMAIN'
        ];

        $missing = [];
        foreach ($required as $key) {
            $found = false;
            foreach ($lines as $line) {
                if (str_starts_with(trim($line), "$key=")) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $missing[] = $key;
            }
        }

        if (empty($missing)) {
            $this->info('All Mail keys already present in .env.');
            return;
        }

        // Append missing keys with placeholder comments
        $append = "# Mail configuration added by mail:install command\n";
        foreach ($missing as $key) {
            $comment = match ($key) {
                'MAIL_DRIVER' => '# Mail driver (e.g., smtp, sendmail, etc.)',
                'MAIL_HOST' => '# Mail server host',
                'MAIL_PORT' => '# Mail server port',
                'MAIL_USERNAME' => '# Mail server username',
                'MAIL_PASSWORD' => '# Mail server password',
                'MAIL_ENCRYPTION' => '# Mail encryption protocol (e.g., tls, ssl)',
                'MAIL_FROM_ADDRESS' => '# Default sender email address',
                'MAIL_FROM_NAME' => '# Default sender name',
                'MAIL_TIMEOUT' => '# Mail server timeout (in seconds)',
                'MAIL_DKIM_PRIVATE_KEY' => '# DKIM private key for signing emails',
                'MAIL_DKIM_SELECTOR' => '# DKIM selector for identifying the key',
                'MAIL_DKIM_DOMAIN' => '# DKIM domain for signing emails',
                default => ''
            };
            $append .= "$key=" . strtoupper($key) . "_VALUE $comment\n";
        }

        file_put_contents($envFile, "\n" . $append, FILE_APPEND);
        $this->info('✓ Added missing Mail keys to .env: ' . implode(', ', $missing));
    }

    /**
     * Make sure config/app.mlc contains a mail { … } section
     *
     * @param string $projectRoot
     */
    private function addMailConfig(string $projectRoot): void
    {
        $mlcFile = "{$projectRoot}/config/app.mlc";
        if (!is_file($mlcFile)) {
            $this->warn('config/app.mlc not found; skipping mail section injection.');
            return;
        }

        $lines = file($mlcFile, FILE_IGNORE_NEW_LINES);

        // -----------------------------------------------------------------
        // 1) Find an existing `mail {` block (track braces)
        // -----------------------------------------------------------------
        $mailStart = null;
        $mailEnd   = null;
        foreach ($lines as $i => $line) {
            if (preg_match('/^\s*mail\s*\{\s*$/', $line)) {
                $mailStart = $i;
                // walk to matching }
                $depth = 1;
                for ($j = $i + 1, $n = count($lines); $j < $n; $j++) {
                    if (strpos($lines[$j], '{') !== false) $depth++;
                    if (strpos($lines[$j], '}') !== false) $depth--;
                    if ($depth === 0) {
                        $mailEnd = $j;
                        break;
                    }
                }
                break;
            }
        }

        // -----------------------------------------------------------------
        // 2) Existing child-indent or default four spaces
        // -----------------------------------------------------------------
        $indent = '    ';
        if ($mailStart !== null && $mailStart + 1 < count($lines)) {
            if (preg_match('/^(\s+)\S/', $lines[$mailStart + 1], $m)) {
                $indent = $m[1];
            }
        }

        // -----------------------------------------------------------------
        // 3) Build defaults and merge with any existing keys
        // -----------------------------------------------------------------
        $defaults = [
            'driver'               => '"smtp"',
            'queue_enabled'        => 'true',
            'default_queue'        => '"emails"',
            'template_engine'      => '"mlview"',
            'views_path'           => '"resources/views"',
            'cache_path'           => '"storage/cache/views"',
            'retry_attempts'       => '3',
            'retry_delay'          => '5',
            'timeout'              => '30',
        ];

        $existing = [];

        if ($mailStart !== null) {
            // scan the existing block for key = value pairs
            for ($k = $mailStart + 1; $k < $mailEnd; $k++) {
                if (preg_match('/^\s*([A-Za-z0-9_]+)\s*=\s*(.+)$/', $lines[$k], $m)) {
                    $existing[$m[1]] = trim($m[2]);
                }
            }
        }

        // Final key/value list (existing overrides defaults)
        $kv = $defaults;
        foreach ($existing as $k => $v) {
            $kv[$k] = $v;
        }

        // -----------------------------------------------------------------
        // 4) Compose the block
        // -----------------------------------------------------------------
        $block = [];
        $block[] = 'mail {';
        foreach ($kv as $k => $v) {
            $block[] = $indent . $k . ' = ' . $v;
        }
        $block[] = '}';

        // -----------------------------------------------------------------
        // 5) Splice into file
        // -----------------------------------------------------------------
        if ($mailStart !== null && $mailEnd !== null) {
            // replace old block
            array_splice($lines, $mailStart, $mailEnd - $mailStart + 1, $block);
        } else {
            // append after auth { … } or at end of file
            $insertAt = count($lines);
            foreach ($lines as $i => $line) {
                if (preg_match('/^\s*auth\s*\{\s*$/', $line)) {
                    // jump to its closing brace
                    $d = 1;
                    for ($j = $i + 1; $j < count($lines); $j++) {
                        if (strpos($lines[$j], '{') !== false) $d++;
                        if (strpos($lines[$j], '}') !== false) $d--;
                        if ($d === 0) {
                            $insertAt = $j + 1;
                            break;
                        }
                    }
                    break;
                }
            }
            array_splice($lines, $insertAt, 0, array_merge([''], $block)); // blank line before
        }

        file_put_contents($mlcFile, implode("\n", $lines) . "\n");
        $this->info('✓ Added/merged mail { … } section in config/app.mlc.');
    }

    /**
     * Recursively copy a directory using native PHP iterators.
     */
    private function mirror(string $source, string $dest): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $relative = substr($item->getPathname(), strlen($source) + 1);
            $target   = $dest . DIRECTORY_SEPARATOR . $relative;

            if ($item->isDir()) {
                @mkdir($target, 0755, true);
            } else {
                $this->copyFile($item->getPathname(), $target);
            }
        }
    }

    /**
     * Prompt the user to confirm overwriting an existing file.
     * Returns true if the user confirms, false otherwise.
     */
    private function shouldOverwrite(string $to, string $projectRoot): bool
    {
        $overwrite = $this->confirm(str_replace($projectRoot . '/', '', $to) . ' exists, overwrite?', false);
        if (!$overwrite) {
            $this->line('↷ Skipped ' . str_replace($projectRoot . '/', '', $to));
        }
        return $overwrite;
    }

    /**
     * Copy a single file ensuring the destination directory exists.
     */
    private function copyFile(string $from, string $to): bool
    {
        if (!file_exists($from)) {
            $this->warn("Source file does not exist: $from");
            return false;
        }

        $dir = dirname($to);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
                $this->warn("Failed to create directory: $dir");
                return false;
            }
        }

        if (!copy($from, $to)) {
            $this->warn("Failed to copy file from $from to $to");
            return false;
        }

        return true;
    }


    /**
     * Ask a yes/no question and return true for 'yes', false for 'no'.
     * Defaults to the provided value if no input is given.
     */
    private function confirm(string $question, bool $default = false): bool
    {
        $answer = $this->ask($question . ($default ? ' [Y/n]' : ' [y/N]'));
        if ($answer === '') {
            return $default;
        }
        return in_array(strtolower($answer), ['y', 'yes'], true);
    }

    /**
     * Output a warning message to the console.
     */
    private function warn(string $message): void
    {
        $this->line('<comment>' . $message . '</comment>');
    }
}
