<?php

declare(strict_types=1);

namespace MonkeysLegion\Mail\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;
use MonkeysLegion\Cli\Command\MakerHelpers;
use MonkeysLegion\Cli\Console\Traits\Cli;
use MonkeysLegion\Logger\Contracts\MonkeysLoggerInterface;
use MonkeysLegion\Mail\Service\ServiceContainer;

#[CommandAttr('mail:test', 'Send a test email')]
final class MailTestCommand extends Command
{
    use MakerHelpers, Cli;

    public function handle(): int
    {
        $email = $this->argument(0);

        if (!$email) {
            $this->cliLine()
                ->error('Please provide an email address')
                ->print();
            $this->cliLine()
                ->muted('Usage: mail:test <email_address>')
                ->print();
            $this->cliLine()
                ->muted('Example: mail:test user@example.com')
                ->print();
            return self::FAILURE;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->cliLine()
                ->error('Invalid email address:')->space()->add($email, 'red', 'bold')
                ->print();
            return self::FAILURE;
        }

        $this->cliLine()
            ->info('Sending test email to:')->space()->add($email, 'cyan', 'bold')
            ->print();

        $container = ServiceContainer::getInstance();
        /** @var MonkeysLoggerInterface $logger */
        $logger = $container->get(MonkeysLoggerInterface::class);

        try {
            /** @var \MonkeysLegion\Mail\Mailer $mailer */
            $mailer = $container->get(\MonkeysLegion\Mail\Mailer::class);

            $mailer->send(
                $email,
                '[TEST] Mailer setup is working',
                'This is a test message from your mail system.',
                'text/plain'
            );

            $logger->info("Test email sent", ['email' => $email]);

            echo "\n";
            $this->cliLine()
                ->success('✓ Test email sent successfully!')
                ->print();
        } catch (\Exception $e) {
            $logger->error("Test email failed", [
                'exception' => $e,
                'email' => $email,
                'trace' => $e->getTraceAsString()
            ]);

            echo "\n";
            $this->cliLine()
                ->error('✗ Failed to send test email')
                ->print();
            $this->cliLine()
                ->error('Error:')->space()->add($e->getMessage(), 'red')
                ->print();
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
