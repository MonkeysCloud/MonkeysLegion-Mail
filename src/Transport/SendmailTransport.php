<?php

declare(strict_types=1);

namespace MonkeysLegion\Mail\Transport;

use MonkeysLegion\Mail\Message;
use MonkeysLegion\Mail\TransportInterface;

class SendmailTransport implements TransportInterface
{
    private string $sendmailPath;
    private string $sendmailOptions;

    public function __construct(string $sendmailPath = '/usr/sbin/sendmail', string $sendmailOptions = '-t -i')
    {
        $this->sendmailPath = $sendmailPath;
        $this->sendmailOptions = $sendmailOptions;
    }

    public function send(Message $m): void
    {
        $command = escapeshellcmd($this->sendmailPath) . ' ' . $this->sendmailOptions;

        // Open a process to sendmail with stdin, stdout, stderr pipes
        $process = proc_open($command, [
            ['pipe', 'r'],  // stdin - to write the email
            ['pipe', 'w'],  // stdout
            ['pipe', 'w'],  // stderr
        ], $pipes);

        if (!is_resource($process)) {
            throw new \RuntimeException("Failed to start sendmail process.");
        }

        // Write the email content to sendmail stdin
        fwrite($pipes[0], $m->toString());
        fclose($pipes[0]);

        // Read output and errors (optional, useful for debugging)
        // $output = stream_get_contents($pipes[1]);

        fclose($pipes[1]);

        $errorOutput = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        // Close the process and get the exit code
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            throw new \RuntimeException("Sendmail failed with error: " . $errorOutput);
        }
    }
}
