<?php

declare(strict_types=1);

namespace MonkeysLegion\Mail\Transport;

use MonkeysLegion\Core\Contracts\FrameworkLoggerInterface;
use MonkeysLegion\Mail\Enums\MailDriverName;
use MonkeysLegion\Mail\Message;
use MonkeysLegion\Mail\TransportInterface;

final class SendmailTransport implements TransportInterface
{
    private string $path;
    /** @var array<string, string> */
    private array $from;

    /**
     * @param array<string, mixed> $config Configuration for the sendmail transport
     * @param FrameworkLoggerInterface|null $logger Optional logger instance
     */
    public function __construct(array $config = [], private ?FrameworkLoggerInterface $logger = null)
    {
        $this->validateAndSetConfig($config);

        $this->logger?->debug('Sendmail transport initialized', [
            'path' => $this->path,
            'from' => $this->from,
        ]);
    }

    /**
     * Validate and set configuration values
     *
     * @param array<string, mixed> $config
     * @throws \InvalidArgumentException
     * @return void
     */
    private function validateAndSetConfig(array $config): void
    {
        $this->path = safeString($config['path'], '/usr/sbin/sendmail');

        // Validate path exists, but don't check executable yet (might be running as different user)
        if (!file_exists($this->path)) {
            $warning = "Warning: Sendmail binary path not found: {$this->path}";
            $this->logger?->warning($warning);
        }

        if (isset($config['from']) && is_array($config['from'])) {
            $fromAddress = safeString($config['from']['address']);
            $fromName = safeString($config['from']['name']);

            if (!filter_var($fromAddress, FILTER_VALIDATE_EMAIL)) {
                $warning = "Invalid 'from' email address format: {$fromAddress}";
                $this->logger?->warning($warning);
                throw new \InvalidArgumentException($warning);
            }

            $this->from = [
                'address' => $fromAddress,
                'name' => $fromName,
            ];
        } else {
            throw new \InvalidArgumentException("Sendmail configuration must include 'from' address");
        }
    }

    public function send(Message $message): void
    {
        if (!is_executable($this->path)) {
            $error = "Sendmail binary not found or not executable: {$this->path}";
            $this->logger?->error($error);
            throw new \RuntimeException($error);
        }

        // Set from address if not already set in the message
        if (empty($message->getFrom())) {
            $fromText = "{$this->from['name']} <{$this->from['address']}>";
            $message->setFrom($fromText);
        }

        $headers = [];
        foreach ($message->getHeaders() as $key => $value) {
            if (!empty($value)) {
                $headers[] = "$key: $value";
            }
        }

        $emailData = implode("\r\n", $headers) . "\r\n\r\n" . $message->getContent();

        $descriptorspec = [
            0 => ["pipe", "r"],  // stdin
            1 => ["pipe", "w"],  // stdout
            2 => ["pipe", "w"]   // stderr
        ];

        $this->logger?->debug('Sending email via sendmail', [
            'command' => "{$this->path} -t",
            'headers_count' => count($headers),
            'content_length' => strlen($message->getContent())
        ]);

        $pipes = [];
        $process = proc_open("{$this->path} -t", $descriptorspec, $pipes);

        if (!is_resource($process)) {
            $error = "Failed to open sendmail process";
            $this->logger?->error($error);
            throw new \RuntimeException($error);
        }

        /** @var array{0: resource, 1: resource, 2: resource} $pipes */
        fwrite($pipes[0], $emailData);
        fclose($pipes[0]);

        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $returnValue = proc_close($process);

        if ($returnValue !== 0) {
            $errorMsg = "Sendmail failed with exit code $returnValue: $errors";
            $this->logger?->error($errorMsg, [
                'output' => $output,
                'errors' => $errors,
                'return_value' => $returnValue
            ]);
            throw new \RuntimeException($errorMsg);
        }

        $this->logger?->info('Email sent successfully via sendmail');
    }

    public function getName(): string
    {
        return MailDriverName::SENDMAIL->value;
    }
}
