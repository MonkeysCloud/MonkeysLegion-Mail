<?php

declare(strict_types=1);

namespace MonkeysLegion\Mail\Transport;

use MonkeysLegion\Mail\Logger\Logger;
use MonkeysLegion\Mail\Message;
use MonkeysLegion\Mail\TransportInterface;

final class SmtpTransport implements TransportInterface
{
    private ?string $address = null;
    private $socket;

    /**
     * SmtpTransport constructor.
     *
     * @param array $config Configuration for the SMTP transport.
     *  Expected keys: 'host', 'port', 'encryption', 'username', 'password', 'from', 'timeout'.
     *  'encryption' can be 'ssl', 'tls', or 'none'.    
     */
    public function __construct(
        private array $config,
        private  ?Logger $logger = null
    ) {
        $this->logger->log("SMTP Transport constructor called", [
            'config_keys' => array_keys($config),
            'has_host' => isset($config['host']),
            'has_port' => isset($config['port']),
            'has_encryption' => isset($config['encryption'])
        ]);

        $this->logger->log("SMTP Transport initialized", [
            'host' => $this->config['host'] ?? 'not_set',
            'port' => $this->config['port'] ?? 25,
            'encryption' => $this->config['encryption'] ?? 'not_set',
            'has_auth' => !empty($this->config['username']),
            'from_address' => $this->config['from']['address'] ?? 'not_set'
        ]);

        $this->address = $this->makeAddress();
    }

    public function send(Message $m): void
    {
        $this->logger->log("Attempting SMTP send", [
            'to' => $m->getTo(),
            'subject' => $m->getSubject(),
            'host' => $this->config['host'],
            'port' => $this->config['port']
        ]);

        try {
            $this->connect();

            $this->sendCommand("MAIL FROM:<{$this->config['from']['address']}>");
            $this->expectResponse(250);

            $this->sendCommand("RCPT TO:<{$m->getTo()}>");
            $this->expectResponse(250);

            $this->sendCommand("DATA");
            $this->expectResponse(354);

            // Check if DKIM signature is available and add it to headers
            $messageContent = $this->formatMessageWithDkim($m);

            $this->sendCommand($messageContent . "\r\n.");
            $this->expectResponse(250);

            $this->disconnect();

            $this->logger->log("SMTP send completed successfully", [
                'to' => $m->getTo(),
                'subject' => $m->getSubject()
            ]);
        } catch (\Exception $e) {
            $this->logger->log("SMTP send failed", [
                'to' => $m->getTo(),
                'subject' => $m->getSubject(),
                'exception' => $e,
                'error_message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $this->disconnect();
            throw new \RuntimeException("SMTP send failed: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Format message content with DKIM signature if available
     */
    private function formatMessageWithDkim(Message $message): string
    {
        $headers = [];
        $messageHeaders = $message->getHeaders();

        // Check if there's a DKIM signature in the message
        $dkimSignature = $message->getDkimSignature() ?? null;

        // CRITICAL: Add DKIM signature FIRST before any other headers
        if ($dkimSignature) {
            $headers[] = $dkimSignature;

            $this->logger->log("Adding DKIM signature to SMTP message", [
                'has_dkim' => true,
                'signature_length' => strlen($dkimSignature)
            ]);
        }

        // Add headers in EXACT same order as they were when we signed
        $headerOrder = ['From', 'To', 'Subject', 'Date', 'Message-ID', 'Content-Type', 'MIME-Version'];

        foreach ($headerOrder as $headerName) {
            if (isset($messageHeaders[$headerName]) && !empty($messageHeaders[$headerName])) {
                $headers[] = "$headerName: " . $messageHeaders[$headerName];
            }
        }

        // Add any remaining headers not in our standard order
        foreach ($messageHeaders as $key => $value) {
            if (!in_array($key, $headerOrder) && !empty($value)) {
                $headers[] = "$key: $value";
            }
        }

        $body = $message->getContent();

        // CRITICAL: Body must have same line endings as when we calculated the hash
        // Remove any existing line endings and add exactly one CRLF
        $body = rtrim($body, "\r\n") . "\r\n";

        // Handle attachments and inline images
        if (!empty($message->getAttachments())) {
            foreach ($message->getAttachments() as $attachment) {
                $body .= "Attachment: {$attachment}\r\n";
            }
        }

        if (!empty($message->getInlineImages())) {
            foreach ($message->getInlineImages() as $image) {
                $body .= "Inline Image: {$image}\r\n";
            }
        }

        return implode("\r\n", $headers) . "\r\n\r\n" . $body;
    }

    /**
     * Connects to the SMTP server.
     *
     * @throws \RuntimeException if the connection fails or if STARTTLS is not supported.
     */
    private function connect(): void
    {
        $this->logger->log("Connecting to SMTP server", [
            'address' => $this->address,
            'timeout' => $this->config['timeout']
        ]);

        try {
            $timeout = $this->config['timeout'];

            $this->socket = @stream_socket_client(
                $this->address,
                $errno,
                $errstr,
                $timeout
            );
            if (!$this->socket) {
                $this->logger->log("SMTP connection failed", [
                    'address' => $this->address,
                    'errno' => $errno,
                    'errstr' => $errstr
                ]);
                throw new \RuntimeException("SMTP connection failed to {$this->address}: $errstr (Code: $errno)");
            }

            $banner = $this->readResponse();
            if (substr($banner, 0, 3) !== '220') {
                $this->logger->log("SMTP server greeting failed", [
                    'banner' => trim($banner)
                ]);
                throw new \RuntimeException("SMTP server did not greet properly: " . trim($banner));
            }

            $this->logger->log("SMTP connection established", [
                'banner' => trim($banner)
            ]);

            $ehloResponse = '';

            $encryption = $this->config['encryption'] ?? 'none';


            // Explicitly handle null values AND string 'null'
            if ($encryption === null || $encryption === '' || $encryption === 'null') {
                $encryption = 'none';
            }

            $encryption = strtolower($encryption);

            if (!in_array($encryption, ['ssl', 'tls', 'none'])) {
                $this->logger->log("Invalid encryption value detected", [
                    'raw_encryption' => $this->config['encryption'] ?? 'NOT_SET',
                    'processed_encryption' => $encryption,
                    'config_keys' => array_keys($this->config)
                ]);
                throw new \RuntimeException("Unsupported encryption method: {$encryption}");
            }

            // For STARTTLS or no encryption: send EHLO immediately
            if ($encryption !== 'ssl') {
                $this->sendCommand("EHLO localhost");
                $ehloResponse = $this->readResponse();

                if (!$ehloResponse || substr($ehloResponse, 0, 3) !== '250') {
                    $this->logger->log("EHLO failed", [
                        'response' => trim($ehloResponse)
                    ]);
                    throw new \RuntimeException("EHLO failed. Server response: " . trim($ehloResponse));
                }

                $this->handleEncryption($ehloResponse);
            }

            // After STARTTLS handshake, or in case of direct SSL connection
            if ($encryption === 'ssl' || $encryption === 'tls') {
                $this->sendCommand("EHLO localhost");
                $ehloResponse = $this->readResponse();

                if (!$ehloResponse || substr($ehloResponse, 0, 3) !== '250') {
                    $this->logger->log("Second EHLO failed", [
                        'response' => trim($ehloResponse)
                    ]);
                    throw new \RuntimeException("Second EHLO failed. Server response: " . trim($ehloResponse));
                }
            }

            if (!empty($this->config['username']) && !empty($this->config['password'])) {
                $this->logger->log("Attempting SMTP authentication", [
                    'username' => $this->config['username'],
                    'auth_method' => strpos($ehloResponse, 'CRAM-MD5') !== false ? 'CRAM-MD5' : 'LOGIN'
                ]);

                if (strpos($ehloResponse, 'CRAM-MD5') !== false) {
                    $this->authenticateCramMd5();
                } else {
                    $this->authenticateLogin();
                }

                $this->logger->log("SMTP authentication successful");
            }
        } catch (\Exception $e) {
            $this->logger->log("SMTP connection setup failed", [
                'exception' => $e,
                'error_message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            if ($this->socket) {
                fclose($this->socket);
                $this->socket = null;
            }
            throw new \RuntimeException("SMTP connection setup failed: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Disconnects from the SMTP server.
     * Sends a QUIT command before closing the socket.
     */
    private function disconnect(): void
    {
        if ($this->socket) {
            $this->logger->log("Disconnecting from SMTP server");
            $this->sendCommand("QUIT");
            fclose($this->socket);
            $this->socket = null;
        }
    }

    /**
     * Constructs the address based on the configuration.
     * 
     * @return string The address in the format 'ssl://host:port' for SSL, or 'host:port' for TLS/none.
     */
    private function makeAddress(): string
    {
        $host = $this->config['host'] ?? 'localhost';
        $port = $this->config['port'] ?? 25;
        $encryption = $this->config['encryption'] ?? 'none';

        // Explicitly handle null values AND string 'null'
        if ($encryption === null || $encryption === '' || $encryption === 'null') {
            $encryption = 'none';
        }

        $encryption = strtolower($encryption);

        if ($encryption === 'ssl') {
            return 'ssl://' . $host . ':' . $port;
        }

        // For 'tls' or 'none', use plain address (unencrypted TCP initially)
        return $host . ':' . $port;
    }

    private function handleEncryption(string $ehloResponse): void
    {
        $encryption = $this->config['encryption'] ?? 'none';

        // Explicitly handle null values AND string 'null'
        if ($encryption === null || $encryption === '' || $encryption === 'null') {
            $encryption = 'none';
        }

        $encryption = strtolower($encryption);

        $this->logger->log("Handling SMTP encryption", [
            'encryption_type' => $encryption,
            'starttls_available' => strpos($ehloResponse, 'STARTTLS') !== false
        ]);

        if ($encryption === 'tls') {
            if (strpos($ehloResponse, 'STARTTLS') === false) {
                $this->logger->log("STARTTLS not supported by server");
                throw new \RuntimeException('Server does not support STARTTLS.');
            }

            $this->sendCommand("STARTTLS");
            $this->expectResponse(220);

            if (!stream_socket_enable_crypto(
                $this->socket,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT
            )) {
                $this->logger->log("Failed to enable TLS encryption");
                throw new \RuntimeException('Failed to enable TLS.');
            }

            $this->logger->log("TLS encryption enabled successfully");

            // Re-EHLO is mandatory after STARTTLS
            $this->sendCommand("EHLO localhost");
            $this->expectResponse(250);
        } elseif ($encryption === 'none') {
            $this->logger->log("Using no encryption");
        } elseif ($encryption !== 'ssl') {
            $this->logger->log("Unsupported encryption method", [
                'encryption' => $encryption
            ]);
            throw new \InvalidArgumentException('Unsupported encryption method: ' . $encryption);
        }
    }


    private function authenticateLogin(): void
    {
        $this->sendCommand("AUTH LOGIN");
        $this->expectResponse(334);

        $this->sendCommand(base64_encode($this->config['username']));
        $this->expectResponse(334);

        $this->sendCommand(base64_encode($this->config['password']));
        $this->expectResponse(235);
    }

    private function authenticateCramMd5(): void
    {
        $this->sendCommand('AUTH CRAM-MD5');
        $challengeResponse = $this->readResponse(); // should be 334 base64(challenge)

        if (substr($challengeResponse, 0, 3) !== '334') {
            throw new \RuntimeException("Server did not accept CRAM-MD5 start");
        }

        $challenge = base64_decode(substr($challengeResponse, 4));
        $username = $this->config['username'];
        $password = $this->config['password'];

        // HMAC-MD5 challenge using password as key
        $digest = hash_hmac('md5', $challenge, $password);
        $response = base64_encode($username . ' ' . $digest);

        $this->sendCommand($response);
        $this->expectResponse(235); // Authentication successful
    }


    private function sendCommand(string $command): void
    {
        if (!$this->socket) {
            throw new \RuntimeException("Cannot send command: SMTP connection not established");
        }

        // Don't log sensitive authentication data
        $logCommand = (strpos($command, 'AUTH') === 0 && $command !== 'AUTH LOGIN' && $command !== 'AUTH CRAM-MD5')
            ? 'AUTH [HIDDEN]'
            : $command;

        $this->logger->log("Sending SMTP command", ['command' => $logCommand]);

        $result = fwrite($this->socket, $command . "\r\n");
        if ($result === false) {
            $this->logger->log("Failed to send SMTP command", ['command' => $logCommand]);
            throw new \RuntimeException("Failed to send SMTP command: $command");
        }
    }

    private function readResponse(): string
    {
        if (!$this->socket) {
            throw new \RuntimeException("Cannot read response: SMTP connection not established");
        }

        $response = '';
        $readTimeout = $this->config['timeout'];
        $timeout = time() + $readTimeout;

        while ($line = fgets($this->socket)) {
            if (time() > $timeout) {
                $this->logger->log("SMTP response timeout", [
                    'timeout_seconds' => $readTimeout
                ]);
                throw new \RuntimeException("SMTP response timeout after {$readTimeout} seconds");
            }

            $response .= $line;
            if (preg_match('/^\d{3} /', $line)) {
                break;
            }
        }

        if (empty($response)) {
            $this->logger->log("No response received from SMTP server");
            throw new \RuntimeException("No response received from SMTP server");
        }

        $this->logger->log("Received SMTP response", [
            'response_code' => substr(trim($response), 0, 3),
            'response' => trim($response)
        ]);

        return $response;
    }

    private function expectResponse(int $code): void
    {
        $response = $this->readResponse();
        $responseCode = (int) substr(trim($response), 0, 3);

        if ($responseCode !== $code) {
            throw new \RuntimeException("SMTP Error: Expected code $code, got $responseCode. Server response: " . trim($response));
        }
    }

    /**
     * Sets the authentication credentials for the SMTP server.
     *
     * @param string $username The username for SMTP authentication.
     * @param string $password The password for SMTP authentication.
     */
    public function setAuth(string $username, string $password): void
    {
        $this->config['username'] = $username;
        $this->config['password'] = $password;
    }

    /**
     * Gets the authentication username.
     *
     * @return string|null The username for SMTP authentication.
     */
    public function getUsername(): ?string
    {
        return $this->config['username'] ?? null;
    }

    /**
     * Sets the sender's email address.
     *
     * @param string $address The sender's email address.
     * @param string|null $name The sender's name (optional).
     */
    public function setFrom(string $address, ?string $name = null): void
    {
        if (!filter_var($address, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException("Invalid email address: $address");
        }

        $this->config['from']['address'] = $address;
        if ($name !== null) {
            $this->config['from']['name'] = $name;
        }
    }

    /**
     * Gets the sender's email address.
     *
     * @return string|null The sender's email address.
     */
    public function getFromAddress(): ?string
    {
        return $this->config['from']['address'] ?? null;
    }

    /**
     * Gets the sender's name.
     *
     * @return string|null The sender's name.
     */
    public function getFromName(): ?string
    {
        return $this->config['from']['name'] ?? null;
    }

    /**
     * Sets the SMTP server host.
     *
     * @param string $host The SMTP server host.
     */
    public function setHost(string $host): void
    {
        if (empty($host)) {
            throw new \InvalidArgumentException("SMTP host cannot be empty");
        }

        $this->config['host'] = $host;
        $this->address = $this->makeAddress();
    }

    /**
     * Gets the SMTP server host.
     *
     * @return string|null The SMTP server host.
     */
    public function getHost(): ?string
    {
        return $this->config['host'] ?? null;
    }

    /**
     * Sets the SMTP server port.
     *
     * @param int $port The SMTP server port.
     */
    public function setPort(int $port): void
    {
        if ($port <= 0 || $port > 65535) {
            throw new \InvalidArgumentException("Invalid SMTP port: $port");
        }

        $this->config['port'] = $port;
        $this->address = $this->makeAddress();
    }

    /**
     * Gets the SMTP server port.
     *
     * @return int|null The SMTP server port.
     */
    public function getPort(): ?int
    {
        return $this->config['port'] ?? null;
    }

    /**
     * Sets the encryption method used by the SMTP transport.
     *
     * @param string $encryption The encryption method ('ssl', 'tls', or 'none').
     */
    public function setEncryption(string $encryption): void
    {
        $encryption = strtolower($encryption);
        if (!in_array($encryption, ['ssl', 'tls', 'none'])) {
            throw new \InvalidArgumentException("Invalid encryption method: $encryption");
        }

        $this->config['encryption'] = $encryption;
        $this->address = $this->makeAddress();
    }

    /**
     * Gets the encryption method.
     *
     * @return string|null The encryption method.
     */
    public function getEncryption(): ?string
    {
        return $this->config['encryption'] ?? null;
    }

    /**
     * Set the timeout for the SMTP connection.
     *
     * @param int $timeout The timeout in seconds.
     */
    public function setTimeout(int $timeout): void
    {
        if ($timeout <= 0) {
            throw new \InvalidArgumentException("Invalid timeout: $timeout");
        }

        $this->config['timeout'] = $timeout;
    }

    /**
     * Gets the connection timeout.
     *
     * @return int|null The timeout in seconds.
     */
    public function getTimeout(): ?int
    {
        return $this->config['timeout'] ?? null;
    }

    /**
     * Get the current SMTP configuration.
     *
     * @return array The SMTP configuration.
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Set the entire SMTP configuration.
     *
     * @param array $config The SMTP configuration array.
     */
    public function setConfig(array $config): void
    {
        $this->config = array_merge($this->config, $config);
        $this->address = $this->makeAddress();
    }

    public function getName(): string
    {
        return 'smtp';
    }
}
