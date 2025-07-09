<?php

declare(strict_types=1);

namespace MonkeysLegion\Mail\Transport;

use MonkeysLegion\Mail\Message;
use MonkeysLegion\Mail\TransportInterface;

/**
 * Sendmail transport for sending emails using system sendmail
 */
final class SendmailTransport implements TransportInterface
{

    public function __construct(private string $sendmailPath = '/usr/sbin/sendmail') {}

    public function send(Message $message): void
    {
        if (!is_executable($this->sendmailPath)) {
            throw new \RuntimeException("Sendmail binary not found or not executable: {$this->sendmailPath}");
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

        $process = proc_open("{$this->sendmailPath} -t", $descriptorspec, $pipes);

        if (!is_resource($process)) {
            throw new \RuntimeException("Failed to open sendmail process");
        }

        fwrite($pipes[0], $emailData);
        fclose($pipes[0]);

        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $returnValue = proc_close($process);

        if ($returnValue !== 0) {
            throw new \RuntimeException("Sendmail failed with exit code $returnValue: $errors");
        }
    }

    public function getName(): string
    {
        return 'sendmail';
    }
}
