<?php

declare(strict_types=1);

namespace MonkeysLegion\Mail\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;
use MonkeysLegion\Cli\Command\MakerHelpers;
use MonkeysLegion\Mail\Security\DkimSigner;

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
        $path = $this->getArgument(2); // argv[2] = first actual argument
        if (!$path) {
            $this->error('Please provide a directory path to save the keys.');
            $this->line('Usage: make:dkim-pkey <directory> [bits]');
            return self::FAILURE;
        }

        $bits = 2048;
        $bitsArg = $this->getArgument(3); // argv[3] = optional bits
        if ($bitsArg && is_numeric($bitsArg)) {
            $bits = (int) $bitsArg;
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

        $privatePath = rtrim($path, '/\\') . DIRECTORY_SEPARATOR . 'dkim_private.key';
        $publicPath  = rtrim($path, '/\\') . DIRECTORY_SEPARATOR . 'dkim_public.key';

        if (file_exists($privatePath) || file_exists($publicPath)) {
            $this->error("Key files already exist in: $path");
            return self::FAILURE;
        }

        try {
            $keys = DkimSigner::generateKeys($bits);
        } catch (\Throwable $e) {
            $this->error('Key generation failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        if (file_put_contents($privatePath, $keys['private']) === false) {
            $this->error("Failed to write private key to: $privatePath");
            return self::FAILURE;
        }

        if (file_put_contents($publicPath, $keys['public']) === false) {
            $this->error("Failed to write public key to: $publicPath");
            return self::FAILURE;
        }

        $this->info("✓ DKIM private key saved to: $privatePath");
        $this->info("✓ DKIM public key saved to: $publicPath");
        $this->line('');
        $this->line("Generated {$bits}-bit key pair.");
        $this->line('Add the public key to your DNS as a TXT record for DKIM.');

        return self::SUCCESS;
    }

    private function getArgument(int $position): ?string
    {
        /** @var array<int, string> */
        global $argv;
        return $argv[$position] ?? null;
    }
}
