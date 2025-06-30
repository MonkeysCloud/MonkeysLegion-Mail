<?php

declare(strict_types=1);

namespace MonkeysLegion\Mail\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;
use MonkeysLegion\Cli\Command\MakerHelpers;

/**
 * Class MailMakeCommand
 *
 * This command generates the scaffolding for a new Mailable class.
 */
#[CommandAttr('make:mail', 'Generate a new Mailable class')]
final class MailMakeCommand extends Command
{
    use MakerHelpers;

    public function handle(): int
    {
        $name = $this->argument('name');

        if (!$name) {
            $this->error('Please provide a name for the mail class.');
            $this->line('Usage: make:mail <ClassName>');
            $this->line('Example: make:mail WelcomeEmail');
            return self::FAILURE;
        }

        $projectRoot = WORKING_DIRECTORY;
        $stubPath = __DIR__ . '/../../Resources/Stubs/mail.stub';

        if (!file_exists($stubPath)) {
            $this->error("Mail stub template not found at: $stubPath");
            return self::FAILURE;
        }

        try {
            // Parse and validate the class name
            $className = $this->parseClassName($name);
            $namespace = $this->generateNamespace($projectRoot);
            $viewName = $this->generateViewName($className);
            $subject = $this->generateSubject($className);

            // Generate the file path
            $filePath = $this->generateFilePath($projectRoot, $className);

            // Check if file already exists
            if (file_exists($filePath) && !$this->shouldOverwrite($filePath, $projectRoot)) {
                return self::SUCCESS;
            }

            // Read and process the stub
            $stubContent = file_get_contents($stubPath);
            $processedContent = $this->processStub($stubContent, [
                'namespace' => $namespace,
                'class' => $className,
                'class_lower' => strtolower($className),
                'view_name' => $viewName,
                'to_placeholder' => 'user@example.com',
                'subject_placeholder' => $subject
            ]);

            // Ensure directory exists and write file
            $this->ensureDirectoryExists(dirname($filePath));
            file_put_contents($filePath, $processedContent);

            // Success message
            $relativePath = str_replace($projectRoot . DIRECTORY_SEPARATOR, '', $filePath);
            $this->info("✓ Mail class created: {$relativePath}");
            $this->line('');
            $this->line('<comment>Next steps:</comment>');
            $this->line("1. Create the email template: resources/views/emails/{$viewName}.ml.php");
            $this->line("2. Customize the build() method in your mail class");
            $this->line("3. Use your mail class:");
            $this->line("   <info>\$mail = new {$className}();</info>");
            $this->line("   <info>\$mail->setTo('user@example.com')->send();</info>");

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Failed to generate mail class: " . $e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * Get command line argument by name or position
     */
    private function argument(string $name, int $position = 1): ?string
    {
        global $argv;

        // For 'name' argument, it's typically the first argument after the command
        // Command structure: php artisan make:mail <name>
        // $argv[0] = script name
        // $argv[1] = command (make:mail)  
        // $argv[2] = name argument

        if (isset($argv[$position + 1])) {
            return $argv[$position + 1];
        }

        return null;
    }

    /**
     * Parse and validate the class name
     */
    private function parseClassName(string $name): string
    {
        // Remove .php extension if provided
        $name = preg_replace('/\.php$/', '', $name);

        // Convert to PascalCase
        $className = str_replace(['-', '_'], ' ', $name);
        $className = ucwords($className);
        $className = str_replace(' ', '', $className);

        // Ensure it ends with 'Mail' if not already
        if (!str_ends_with($className, 'Mail')) {
            $className .= 'Mail';
        }

        // Validate class name format
        if (!preg_match('/^[A-Z][a-zA-Z0-9]*$/', $className)) {
            throw new \InvalidArgumentException("Invalid class name: {$className}. Class names must start with a capital letter and contain only letters and numbers.");
        }

        return $className;
    }

    /**
     * Generate the namespace for the mail class
     */
    private function generateNamespace(string $projectRoot): string
    {
        // Try to detect namespace from composer.json
        $composerPath = $projectRoot . '/composer.json';
        if (file_exists($composerPath)) {
            $composer = json_decode(file_get_contents($composerPath), true);
            $autoload = $composer['autoload']['psr-4'] ?? [];

            foreach ($autoload as $namespace => $path) {
                if ($path === 'app/' || $path === 'src/') {
                    return rtrim($namespace, '\\') . '\\Mail';
                }
            }
        }

        // Default namespace
        return 'App\\Mail';
    }

    /**
     * Generate view name from class name
     */
    private function generateViewName(string $className): string
    {
        // Remove 'Mail' suffix
        $name = preg_replace('/Mail$/', '', $className);

        // Convert PascalCase to kebab-case
        $viewName = strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $name));

        return $viewName;
    }

    /**
     * Generate a default subject from class name
     */
    private function generateSubject(string $className): string
    {
        // Remove 'Mail' suffix
        $name = preg_replace('/Mail$/', '', $className);

        // Convert PascalCase to words
        $subject = ucfirst(strtolower(preg_replace('/(?<!^)[A-Z]/', ' $0', $name)));

        return $subject;
    }

    /**
     * Generate the file path for the mail class
     */
    private function generateFilePath(string $projectRoot, string $className): string
    {
        $mailDir = $projectRoot . '/app/Mail';
        return $mailDir . '/' . $className . '.php';
    }

    /**
     * Process the stub template with replacements
     */
    private function processStub(string $content, array $replacements): string
    {
        foreach ($replacements as $key => $value) {
            $content = str_replace("{{ $key }}", $value, $content);
        }

        return $content;
    }

    /**
     * Ensure directory exists
     */
    private function ensureDirectoryExists(string $directory): void
    {
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
    }

    /**
     * Check if we should overwrite an existing file
     */
    private function shouldOverwrite(string $filePath, string $projectRoot): bool
    {
        $relativePath = str_replace($projectRoot . DIRECTORY_SEPARATOR, '', $filePath);
        $overwrite = $this->confirm("{$relativePath} already exists. Overwrite?", false);

        if (!$overwrite) {
            $this->line("↷ Skipped {$relativePath}");
        }

        return $overwrite;
    }

    /**
     * Ask a yes/no question and return true for 'yes', false for 'no'
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
