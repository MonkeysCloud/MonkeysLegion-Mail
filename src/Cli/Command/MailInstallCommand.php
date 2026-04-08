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
        $publishedFiles = 0;
        $skippedFiles = 0;
        $failedFiles = 0;

        $this->showInstallHeader();

        $this->cliLine()
            ->info('Step 1/2')->space()->muted('Choose config format')
            ->print();
        $configType = $this->chooseConfigType();
        $configStub = $configType === 'php' ? "{$stubDir}/config/mail.php" : "{$stubDir}/config/mail.mlc";
        $configTarget = $configType === 'php' ? "{$projectRoot}/config/mail.php" : "{$projectRoot}/config/mail.mlc";

        if (!is_dir($stubDir)) {
            $this->cliLine()
                ->error("Stub directory not found:")->space()->add($stubDir, 'red')
                ->print();
            return self::FAILURE;
        }
        $this->cliLine()
            ->success('✓ Selected config:')->space()->add($configType === 'php' ? 'Standard PHP (mail.php)' : 'MonkeysLegion-Mlc (mail.mlc)', 'cyan')
            ->newline()
            ->print();

        $this->cliLine()
            ->info('Step 2/2')->space()->muted('Publish scaffolding files')
            ->print();

        $map = [
            $configStub                              => $configTarget,
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
            if (!file_exists($from)) {
                $this->cliLine()
                    ->warning("Source file not found:")->space()->add($from, 'yellow')
                    ->print();
                $failedFiles++;
                continue;
            }
            if (is_dir($from)) {
                $this->mirror($from, $to);
                $this->cliLine()
                    ->success('✓ Published directory')->space()->add(str_replace($projectRoot . '/', '', $to), 'cyan')
                    ->print();
                continue;
            }

            if (file_exists($to) && !$this->shouldOverwrite($to, $projectRoot)) {
                $skippedFiles++;
                continue;
            }

            if ($this->copyFile($from, $to)) {
                $publishedFiles++;
                $this->cliLine()
                    ->success('✓ Published file')->space()->add(str_replace($projectRoot . '/', '', $to), 'cyan')
                    ->print();
            } else {
                $failedFiles++;
            }
        }

        $this->cliLine()->newline()->print();

        $this->showInstallSummary($publishedFiles, $skippedFiles, $failedFiles, $addedEnvKeys);
        return self::SUCCESS;
    }

    private function chooseConfigType(): string
    {
        $this->cliLine()
            ->muted('Options:')
            ->print();
        $this->cliLine()
            ->add('  • ', 'white')->add('mlc', 'cyan', 'bold')->muted(' (MonkeysLegion config)')
            ->print();
        $this->cliLine()
            ->add('  • ', 'white')->add('php', 'yellow', 'bold')->muted(' (Standard PHP array config)')
            ->print();

        $answer = strtolower(trim($this->ask(
            'Will you use MonkeysLegion-Mlc or standard PHP config? [mlc/php] (default: mlc)'
        )));

        if ($answer === '' || in_array($answer, ['mlc', 'monkeyslegion-mlc'], true)) {
            return 'mlc';
        }

        if (in_array($answer, ['php', 'standard', 'standard-php'], true)) {
            return 'php';
        }

        $this->cliLine()
            ->warning('Invalid choice. Using default: MonkeysLegion-Mlc config.')
            ->print();

        return 'mlc';
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

    private function showInstallHeader(): void
    {
        $this->cliLine()->newline()->print();
        $this->cliLine()
            ->add('MonkeysLegion Mail Installer', 'cyan', 'bold')
            ->print();
        $this->cliLine()
            ->muted('Publish config, templates, mail classes, and .env mail keys')
            ->newline()
            ->print();
    }

    private function showInstallSummary(int $publishedFiles, int $skippedFiles, int $failedFiles, ?int $addedEnvKeys): void
    {
        $this->cliLine()
            ->success('✓ Mail installation complete!')
            ->print();

        $this->cliLine()
            ->info('Summary:')
            ->print();
        $this->cliLine()
            ->add('  • Published: ', 'white')->add((string)$publishedFiles, 'green', 'bold')
            ->print();
        $this->cliLine()
            ->add('  • Skipped: ', 'white')->add((string)$skippedFiles, 'yellow', 'bold')
            ->print();
        $this->cliLine()
            ->add('  • Failed: ', 'white')->add((string)$failedFiles, $failedFiles > 0 ? 'red' : 'green', 'bold')
            ->print();

        if ($addedEnvKeys === null) {
            $this->cliLine()
                ->add('  • .env: ', 'white')->add('not found', 'yellow', 'bold')
                ->print();
            return;
        }

        $this->cliLine()
            ->add('  • .env keys added: ', 'white')->add((string)$addedEnvKeys, $addedEnvKeys > 0 ? 'yellow' : 'green', 'bold')
            ->print();
    }
}
