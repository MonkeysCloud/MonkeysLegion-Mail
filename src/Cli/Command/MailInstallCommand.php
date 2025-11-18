<?php

declare(strict_types=1);

namespace MonkeysLegion\Mail\Cli\Command;

use FilesystemIterator;
use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;
use MonkeysLegion\Cli\Command\MakerHelpers;
use MonkeysLegion\Cli\Console\Traits\Cli;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Publish Mail scaffolding (views, config, docs) into the host app
 * without relying on the symfony/filesystem component, and ensures .env has Mail keys.
 */
#[CommandAttr('mail:install', 'Publish Mail scaffolding into your project')]
final class MailInstallCommand extends Command
{
    use MakerHelpers, Cli;

    public function handle(): int
    {
        $projectRoot = base_path();
        $stubDir = __DIR__ . '/../../../stubs';

        $this->cliLine()
            ->info('Installing Mail package...')
            ->print();
        echo "\n";

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
                $this->cliLine()
                    ->success('✓ Published directory')->space()->add(str_replace($projectRoot . '/', '', $to), 'cyan')
                    ->print();
                continue;
            }

            if (file_exists($to) && !$this->shouldOverwrite($to, $projectRoot)) {
                continue;
            }

            if ($this->copyFile($from, $to)) {
                $this->cliLine()
                    ->success('✓ Published file')->space()->add(str_replace($projectRoot . '/', '', $to), 'cyan')
                    ->print();
            }
        }

        echo "\n";

        // 2) Ensure .env contains Mail keys
        $this->ensureEnvKeys($projectRoot);

        echo "\n";

        // 3) Patch config/app.mlc: add mail { … } section
        $this->addMailConfig($projectRoot);

        echo "\n";
        $this->cliLine()
            ->success('✓ Mail installation complete!')
            ->print();
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
            $this->cliLine()
                ->warning('.env file not found; skipping Mail key injection.')
                ->print();
            return;
        }

        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) throw new \RuntimeException("Failed to read .env file at: $envFile");
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
            $this->cliLine()
                ->info('All Mail keys already present in .env.')
                ->print();
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
            };
            $append .= "$key=" . strtoupper($key) . "_VALUE $comment\n";
        }

        file_put_contents($envFile, "\n" . $append, FILE_APPEND);
        $this->cliLine()
            ->success('✓ Added missing Mail keys to .env:')->space()->add(implode(', ', $missing), 'yellow')
            ->print();
    }

    /**
     * Make sure config/app.mlc contains a mail { … } section
     *
     * @param string $projectRoot
     */
    private function addMailConfig(string $projectRoot): void
    {
        $path = 'config/app.mlc';
        $mlcFile = "{$projectRoot}/$path";
        if (!is_file($mlcFile)) {
            $this->cliLine()
                ->warning("$path not found; skipping mail section injection.")
                ->print();
            return;
        }

        $lines = file($mlcFile, FILE_IGNORE_NEW_LINES);
        if ($lines === false) throw new \RuntimeException("Failed to read $path at: $mlcFile");

        $mailStart = null;
        $mailEnd   = null;
        foreach ($lines as $i => $line) {
            if (preg_match('/^\s*mail\s*\{\s*$/', $line)) {
                $mailStart = $i;
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

        $indent = '    ';
        if ($mailStart !== null && $mailStart + 1 < count($lines)) {
            if (preg_match('/^(\s+)\S/', $lines[$mailStart + 1], $m)) {
                $indent = $m[1];
            }
        }

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
            for ($k = $mailStart + 1; $k < $mailEnd; $k++) {
                if (preg_match('/^\s*([A-Za-z0-9_]+)\s*=\s*(.+)$/', $lines[$k], $m)) {
                    $existing[$m[1]] = trim($m[2]);
                }
            }
        }

        $kv = $defaults;
        foreach ($existing as $k => $v) {
            $kv[$k] = $v;
        }

        $block = [];
        $block[] = 'mail {';
        foreach ($kv as $k => $v) {
            $block[] = $indent . $k . ' = ' . $v;
        }
        $block[] = '}';

        if ($mailStart !== null && $mailEnd !== null) {
            array_splice($lines, $mailStart, $mailEnd - $mailStart + 1, $block);
        } else {
            $insertAt = count($lines);
            foreach ($lines as $i => $line) {
                if (preg_match('/^\s*auth\s*\{\s*$/', $line)) {
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
            array_splice($lines, $insertAt, 0, array_merge([''], $block));
        }

        file_put_contents($mlcFile, implode("\n", $lines) . "\n");
        $this->cliLine()
            ->success("✓ Added/merged mail { … } section in")->space()->add($path, 'cyan')
            ->print();
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
            /** @var \SplFileInfo $item */
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
        $relativePath = str_replace($projectRoot . '/', '', $to);
        $overwrite = $this->confirm("$relativePath exists, overwrite?", false);
        if (!$overwrite) {
            $this->cliLine()
                ->muted('↷ Skipped')->space()->add($relativePath, 'gray')
                ->print();
        }
        return $overwrite;
    }

    /**
     * Copy a single file ensuring the destination directory exists.
     */
    private function copyFile(string $from, string $to): bool
    {
        if (!file_exists($from)) {
            $this->cliLine()
                ->warning("Source file does not exist:")->space()->add($from, 'yellow')
                ->print();
            return false;
        }

        $dir = dirname($to);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
                $this->cliLine()
                    ->error("Failed to create directory:")->space()->add($dir, 'red')
                    ->print();
                return false;
            }
        }

        if (!copy($from, $to)) {
            $this->cliLine()
                ->error("Failed to copy file from")->space()->add($from, 'red')->space()->add('to', 'white')->space()->add($to, 'red')
                ->print();
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
}
