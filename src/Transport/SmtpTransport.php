<?php

declare(strict_types=1);

namespace MonkeysLegion\Mail\Transport;

use InvalidArgumentException;
use MonkeysLegion\Core\Contracts\FrameworkLoggerInterface;
use MonkeysLegion\Mail\Enums\Encryption;
use MonkeysLegion\Mail\Enums\MailDefaults;
use MonkeysLegion\Mail\Enums\MailDriverName;
use MonkeysLegion\Mail\Message;
use MonkeysLegion\Mail\TransportInterface;

final class SmtpTransport implements TransportInterface
{
    private string $host;
    private int $port;
    private string $encryption;
    private string $username;
    private string $password;
    private int $timeout;
    private string $fromAddress;
    private string $fromName;
    private ?string $address = null;
    /** @var resource|null $socket */
    private $socket = null;

    /**
     * SmtpTransport constructor.
     *
     * @param array<string, mixed> $config Configuration for the SMTP transport.
     * @param FrameworkLoggerInterface $logger
     */
    public function __construct(
        private array $config,
        private ?FrameworkLoggerInterface $logger
    ) {
        $this->logger?->smartLog("SMTP Transport constructor called", [
            'config_keys' => array_keys($config),
            'has_host' => isset($config['host']),
            'has_port' => isset($config['port']),
            'has_encryption' => isset($config['encryption'])
        ]);

        $this->validateSmtpConfig($config);

        $this->logger?->smartLog("SMTP Transport initialized", [
            'host' => safeString($this->host, 'not_set'),
            'port' => $this->port ?? 25,
            'encryption' => safeString($this->encryption, 'not_set'),
            'has_auth' => !empty($this->username),
            'from_address' => $this->fromAddress
        ]);

        $this->address = $this->makeAddress();
    }

    public function send(Message $m): void
    {
        $this->logger?->smartLog("Attempting SMTP send", [
            'to' => $m->getTo(),
            'subject' => $m->getSubject(),
            'host' => $this->host,
            'port' => $this->port
        ]);

        try {
            $this->connect();

            $this->sendCommand("MAIL FROM:<{" . ($this->fromAddress) . "}>");
            $this->expectResponse(250);

            $this->sendCommand("RCPT TO:<{" . ($m->getTo()) . "}>");
            $this->expectResponse(250);

            $this->sendCommand("DATA");
            $this->expectResponse(354);

            // Check if DKIM signature is available and add it to headers
            $messageContent = $this->formatMessageWithDkim($m);

            $this->sendCommand($messageContent . "\r\n.");
            $this->expectResponse(250);

            $this->disconnect();

            $this->logger?->smartLog("SMTP send completed successfully", [
                'to' => $m->getTo(),
                'subject' => $m->getSubject()
            ]);
        } catch (InvalidArgumentException $e) {
            $this->logger?->error("SMTP send failed due to invalid argument", [
                'to' => $m->getTo(),
                'subject' => $m->getSubject(),
                'exception' => $e,
                'error_message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        } catch (\Exception $e) {
            $this->logger?->error("SMTP send failed", [
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
        $boundary = uniqid('boundary_');

        // Add DKIM signature first
        if ($message->getDkimSignature()) {
            $headers[] = $message->getDkimSignature();
        }

        $headers[] = "MIME-Version: 1.0";

        $hasAttachments = !empty($message->getAttachments());
        if ($hasAttachments) {
            $headers[] = "Content-Type: multipart/mixed; boundary=\"$boundary\"";
        } else {
            $headers[] = "Content-Type: text/html; charset=\"UTF-8\"";
        }

        $bodyParts = [];

        // Text/HTML body
        $body = rtrim($message->getContent(), "\r\n") . "\r\n";
        if ($hasAttachments) {
            $bodyParts[] = "--$boundary\r\n" .
                "Content-Type: text/html; charset=\"UTF-8\"\r\n" .
                "Content-Transfer-Encoding: 7bit\r\n\r\n" .
                $body;
        } else {
            $bodyParts[] = $body;
        }

        // Add attachments
        foreach ($message->getAttachments() as $attachment) {
            try {
                $normalized = normalizeAttachment($attachment);
                if (!isset($normalized['boundary_encoded'])) {
                    throw new \LogicException('Expected boundary_encoded key missing from attachment');
                }
                $bodyParts[] = "--$boundary\r\n" . $normalized['boundary_encoded'];
            } catch (\RuntimeException $e) {
                $this->logger?->warning("Attachment error: " . $e->getMessage(), [
                    'file' => $attachment,
                    'to' => $message->getTo()
                ]);
                continue;
            }
        }

        if ($hasAttachments) {
            $bodyParts[] = "--$boundary--";
        }

        // Add headers like From, To, Subject
        /** @var array<string, string> $headers */
        $headers = $message->getHeaders();
        foreach ($headers as $key => $value) {
            $headers[] = "$key: $value";
        }

        return implode("\r\n", $headers) . "\r\n\r\n" . implode("\r\n", $bodyParts);
    }

    /**
     * Connects to the SMTP server.
     *
     * @throws \RuntimeException if the connection fails or if STARTTLS is not supported.
     */
    private function connect(): void
    {
        $this->logger?->smartLog("Connecting to SMTP server", [
            'address' => $this->address,
            'timeout' => $this->timeout
        ]);

        try {
            $timeout = $this->timeout;
            if (!is_string($this->address)) {
                $this->logger?->error("Invalid SMTP address type", [
                    'address' => $this->address,
                    'expected_type' => 'string'
                ]);
                throw new \InvalidArgumentException('SMTP address must be a non-null string');
            }

            $errno = 0;
            $errstr = '';
            $socket = @stream_socket_client(
                $this->address,
                $errstr,
                $errno,
                $timeout
            );

            if ($socket === false) {
                assert(is_int($errno));
                assert(is_string($errstr));
                $this->logger?->smartLog("SMTP connection failed", [
                    'address' => $this->address,
                    'errno' => $errno,
                    'errstr' => $errstr
                ]);
                throw new \RuntimeException("SMTP connection failed to {$this->address}: $errstr (Code: $errno)");
            }

            $this->socket = $socket;

            $banner = $this->readResponse();
            if (substr($banner, 0, 3) !== '220') {
                $this->logger?->error("SMTP server greeting failed", [
                    'banner' => trim($banner)
                ]);
                throw new \RuntimeException("SMTP server did not greet properly: " . trim($banner));
            }

            $this->logger?->smartLog("SMTP connection established", [
                'banner' => trim($banner)
            ]);

            $ehloResponse = '';

            if (!in_array($this->encryption, ['ssl', 'tls', 'none'])) {
                $this->logger?->error("Invalid encryption value detected", [
                    'raw_encryption' => $this->config['encryption'] ?? 'NOT_SET',
                    'processed_encryption' => $this->encryption,
                    'config_keys' => array_keys($this->config)
                ]);
                throw new \RuntimeException("Unsupported encryption method: {$this->encryption}");
            }

            // For STARTTLS or no encryption: send EHLO immediately
            if ($this->encryption !== Encryption::SSL->value) {
                $this->sendCommand("EHLO localhost");
                $ehloResponse = $this->readResponse();

                if (!$ehloResponse || substr($ehloResponse, 0, 3) !== '250') {
                    $this->logger?->error("EHLO failed", [
                        'response' => trim($ehloResponse)
                    ]);
                    throw new \RuntimeException("EHLO failed. Server response: " . trim($ehloResponse));
                }

                $this->handleEncryption($ehloResponse);
            }

            // After STARTTLS handshake, or in case of direct SSL connection
            if ($this->encryption === Encryption::SSL->value || $this->encryption === Encryption::TLS->value) {
                $this->sendCommand("EHLO localhost");
                $ehloResponse = $this->readResponse();

                if (!$ehloResponse || substr($ehloResponse, 0, 3) !== '250') {
                    $this->logger?->error("Second EHLO failed", [
                        'response' => trim($ehloResponse)
                    ]);
                    throw new \RuntimeException("Second EHLO failed. Server response: " . trim($ehloResponse));
                }
            }

            if (!empty($this->username) && !empty($this->password)) {
                $this->logger?->smartLog("Attempting SMTP authentication", [
                    'username' => $this->username,
                    'auth_method' => strpos($ehloResponse, 'CRAM-MD5') !== false ? 'CRAM-MD5' : 'LOGIN'
                ]);

                if (strpos($ehloResponse, 'CRAM-MD5') !== false) {
                    $this->authenticateCramMd5();
                } else {
                    $this->authenticateLogin();
                }

                $this->logger?->smartLog("SMTP authentication successful");
            }
        } catch (\Exception $e) {
            $this->logger?->error("SMTP connection setup failed", [
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
        $socket = $this->socket;
        if (is_resource($socket)) {
            $this->logger?->smartLog("Disconnecting from SMTP server");
            $this->sendCommand("QUIT");
            fclose($socket);
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
        if ($this->encryption === Encryption::SSL->value) {
            return 'ssl://' . $this->host . ':' . $this->port;
        }

        // For 'tls' or 'none', use plain address (unencrypted TCP initially)
        return $this->host . ':' . $this->port;
    }

    private function handleEncryption(string $ehloResponse): void
    {
        $this->logger?->smartLog("Handling SMTP encryption", [
            'encryption_type' => $this->encryption,
            'starttls_available' => strpos($ehloResponse, 'STARTTLS') !== false
        ]);

        if ($this->encryption === Encryption::TLS->value) {
            if (strpos($ehloResponse, 'STARTTLS') === false) {
                $this->logger?->error("STARTTLS not supported by server");
                throw new \RuntimeException('Server does not support STARTTLS.');
            }

            $this->sendCommand("STARTTLS");
            $this->expectResponse(220);

            $socket = $this->socket;
            if (!is_resource($socket)) {
                throw new \RuntimeException("SMTP connection not established");
            }

            if (!stream_socket_enable_crypto(
                $socket,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT
            )) {
                $this->logger?->error("Failed to enable TLS encryption");
                throw new \RuntimeException('Failed to enable TLS.');
            }

            $this->logger?->smartLog("TLS encryption enabled successfully");

            // Re-EHLO is mandatory after STARTTLS
            $this->sendCommand("EHLO localhost");
            $this->expectResponse(250);
        } elseif ($this->encryption === Encryption::NONE->value) {
            $this->logger?->smartLog("Using no encryption");
        } elseif ($this->encryption !== Encryption::SSL->value) {
            $this->logger?->error("Unsupported encryption method", [
                'encryption' => $this->encryption
            ]);
            throw new InvalidArgumentException('Unsupported encryption method: ' . $this->encryption);
        }
    }


    private function authenticateLogin(): void
    {
        $this->sendCommand("AUTH LOGIN");
        $this->expectResponse(334);

        $this->sendCommand(base64_encode($this->username));
        $this->expectResponse(334);

        $this->sendCommand(base64_encode($this->password));
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
        $username = $this->username;
        $password = $this->password;

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

        $this->logger?->smartLog("Sending SMTP command", ['command' => $logCommand]);

        $result = fwrite($this->socket, $command . "\r\n");
        if ($result === false) {
            $this->logger?->error("Failed to send SMTP command", ['command' => $logCommand]);
            throw new \RuntimeException("Failed to send SMTP command: $command");
        }
    }

    private function readResponse(): string
    {
        if (!$this->socket) {
            throw new \RuntimeException("Cannot read response: SMTP connection not established");
        }

        $response = '';
        $timeout = time() + $this->timeout;

        while ($line = fgets($this->socket)) {
            if (time() > $timeout) {
                $this->logger?->error("SMTP response timeout", [
                    'timeout_seconds' => $this->timeout
                ]);
                throw new \RuntimeException("SMTP response timeout after {$this->timeout} seconds");
            }

            $response .= $line;
            if (preg_match('/^\d{3} /', $line)) {
                break;
            }
        }

        if (empty($response)) {
            $this->logger?->error("No response received from SMTP server");
            throw new \RuntimeException("No response received from SMTP server");
        }

        $this->logger?->smartLog("Received SMTP response", [
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
        $this->username = $username;
        $this->password = $password;
    }

    /**
     * Gets the authentication username.
     *
     * @return string The username for SMTP authentication.
     */
    public function getUsername(): string
    {
        return $this->username;
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
            throw new InvalidArgumentException("Invalid email address: $address");
        }

        $this->fromAddress = $address;
        if ($name !== null) {
            $this->fromName = $name;
        }
    }

    /**
     * Gets the sender's email address.
     *
     * @return string The sender's email address.
     */
    public function getFromAddress(): string
    {
        return $this->fromAddress;
    }

    /**
     * Gets the sender's name.
     *
     * @return string The sender's name.
     */
    public function getFromName(): string
    {
        return $this->fromName;
    }

    /**
     * Sets the SMTP server host.
     *
     * @param string $host The SMTP server host.
     */
    public function setHost(string $host): void
    {
        if (empty($host)) {
            throw new InvalidArgumentException("SMTP host cannot be empty");
        }

        $this->host = $host;
        $this->address = $this->makeAddress();
    }

    /**
     * Gets the SMTP server host.
     *
     * @return string The SMTP server host.
     */
    public function getHost(): string
    {
        return $this->host;
    }

    /**
     * Sets the SMTP server port.
     *
     * @param int $port The SMTP server port.
     */
    public function setPort(int $port): void
    {
        if ($port <= 0 || $port > 65535) {
            throw new InvalidArgumentException("Invalid SMTP port: $port");
        }

        $this->port = $port;
        $this->address = $this->makeAddress();
    }

    /**
     * Gets the SMTP server port.
     *
     * @return int The SMTP server port.
     */
    public function getPort(): int
    {
        return $this->port;
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
            throw new InvalidArgumentException("Invalid encryption method: $encryption");
        }

        $this->encryption = $encryption;
        $this->address = $this->makeAddress();
    }

    /**
     * Gets the encryption method.
     *
     * @return string The encryption method.
     */
    public function getEncryption(): string
    {
        return $this->encryption;
    }

    /**
     * Set the timeout for the SMTP connection.
     *
     * @param int $timeout The timeout in seconds.
     */
    public function setTimeout(int $timeout): void
    {
        if ($timeout <= 0) {
            throw new InvalidArgumentException("Invalid timeout: $timeout");
        }

        $this->timeout = $timeout;
    }

    /**
     * Gets the connection timeout.
     *
     * @return int The timeout in seconds.
     */
    public function getTimeout(): int
    {
        return $this->timeout;
    }

    /**
     * Get the current SMTP configuration.
     *
     * @return array<string, mixed> $config Configuration for the SMTP transport. The SMTP configuration.
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Set the entire SMTP configuration.
     *
     * @param array<string, mixed> $config Configuration for the SMTP transport. The SMTP configuration.
     */
    public function setConfig(array $config): void
    {
        $this->config = array_merge($this->config, $config);
        $this->address = $this->makeAddress();
    }

    public function getName(): string
    {
        return MailDriverName::SMTP->value;
    }

    /**
     * Validates the SMTP configuration.
     *
     * @param array<string, mixed> $config The SMTP configuration.
     */
    private function validateSmtpConfig(array $config): void
    {
        $required = ['host', 'port', 'username', 'password', 'from', 'timeout'];

        foreach ($required as $key) {
            if (!isset($config[$key])) {
                throw new InvalidArgumentException("Missing required config key: '$key'");
            }
        }

        if (!is_string($config['host'])) {
            throw new InvalidArgumentException("Config 'host' must be a string.");
        }

        if (!is_int($config['port'])) {
            throw new InvalidArgumentException("Config 'port' must be an integer.");
        }

        $encryption = $config['encryption'] ?? 'none';
        if (empty($encryption) || !is_string($encryption)) {
            $encryption = 'none';
        }
        $encryption = strtolower($encryption);
        if (!Encryption::tryFrom($encryption)) {
            $validValues = array_map(fn($e) => $e->value, Encryption::cases());
            throw new \InvalidArgumentException(
                "Invalid encryption value '{$encryption}'. Supported: " . implode(', ', $validValues)
            );
        }


        if (!isset($config['username']) || !is_string($config['username'])) {
            throw new InvalidArgumentException("Config 'username' is required and must be a string.");
        }
        if (!isset($config['password']) || !is_string($config['password'])) {
            throw new InvalidArgumentException("Config 'password' is required and must be a string.");
        }

        if (!is_int($config['timeout'])) {
            $this->logger?->warning("Config 'timeout' is not an integer, defaulting to " . MailDefaults::TIMEOUT . " seconds");
            $config['timeout'] = MailDefaults::TIMEOUT;
        }

        if (
            !is_array($config['from'] ?? null) ||
            !isset($config['from']['address'], $config['from']['name']) ||
            !is_string($config['from']['address']) ||
            !is_string($config['from']['name'])
        ) {
            throw new InvalidArgumentException("Config 'from' must be an array with string keys 'address' and 'name'.");
        }

        $this->host = $config['host'];
        $this->port = $config['port'];
        $this->encryption = $encryption;
        $this->username = $config['username'];
        $this->password = $config['password'];
        $this->timeout = $config['timeout'];
        $this->fromAddress = $config['from']['address'];
        $this->fromName = $config['from']['name'];
    }
}
