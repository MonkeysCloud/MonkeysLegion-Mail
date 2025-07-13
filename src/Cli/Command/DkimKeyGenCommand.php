<?php

declare(strict_types=1);

namespace MonkeysLegion\Mail\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;
use MonkeysLegion\Cli\Command\MakerHelpers;
use MonkeysLegion\Mail\Security\DkimSigner;
use RuntimeException;

/**
 * Class DkimKeyGenCommand
 *
 * Generates a DKIM private and public key pair and saves them to the given path.
 */
#[CommandAttr('make:dkim-pkey', 'Generate DKIM private and public key files')]
final class DkimKeyGenCommand extends Command
{
    use MakerHelpers;

    public function handle(): int
    {
        $path = $this->argument('path');
        if (!$path) {
            $this->error('Please provide a directory path to save the keys.');
            $this->line('Usage: make:dkim-pkey <directory>');
            return self::FAILURE;
        }

        $bits = 2048;
        $bitsArg = $this->argument('bits', 2);
        if ($bitsArg && is_numeric($bitsArg)) {
            $bits = (int)$bitsArg;
        }

        if (!is_dir($path)) {
            if (!mkdir($path, 0755, true)) {
                $this->error("Failed to create directory: $path");
                return self::FAILURE;
            }
        }

        if (!is_writable($path)) {
            $this->error("Directory is not writable: $path");
            return self::FAILURE;
        }

        try {
            $keys = DkimSigner::generateKeys($bits);
        } catch (\Throwable $e) {
            $this->error('Key generation failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        $privatePath = rtrim($path, '/\\') . DIRECTORY_SEPARATOR . 'dkim_private.key';
        $publicPath  = rtrim($path, '/\\') . DIRECTORY_SEPARATOR . 'dkim_public.key';

        file_put_contents($privatePath, $keys['private']);
        file_put_contents($publicPath, $keys['public']);

        $this->info("✓ DKIM private key saved to: $privatePath");
        $this->info("✓ DKIM public key saved to: $publicPath");
        $this->line('');
        $this->line('Add the public key to your DNS as a TXT record for DKIM.');

        return self::SUCCESS;
    }

    private function argument(string $name, int $position = 1): ?string
    {
        global $argv;
        if (isset($argv[$position + 1])) {
            return $argv[$position + 1];
        }
        return null;
    }
}
