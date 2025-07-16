<?php

namespace MonkeysLegion\Mail\Transport;

use CURLFile;
use MonkeysLegion\Mail\Logger\Logger;
use MonkeysLegion\Mail\Message;
use MonkeysLegion\Mail\TransportInterface;

class MailgunTransport implements TransportInterface
{
    private string $endpoint;
    private array $supportedRegions = ['us', 'eu'];
    private int $timeout = 30;
    private int $connectTimeout = 10;

    public function __construct(
        private array $config,
        private ?Logger $logger = null
    ) {
        $this->validateConfig();
        $this->setupEndpoint();
    }

    public function send(Message $message): void
    {
        $startTime = microtime(true);

        try {
            $this->logger?->log("Preparing Mailgun API request", [
                'to' => $message->getTo(),
                'subject' => $message->getSubject(),
                'endpoint' => $this->endpoint
            ]);

            $postData = $this->preparePayload($message);
            $response = $this->makeRequest($postData);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            $this->logger?->log("Mailgun API request successful", [
                'to' => $message->getTo(),
                'subject' => $message->getSubject(),
                'duration_ms' => $duration,
                'mailgun_id' => $response['id'] ?? null,
                'message' => $response['message'] ?? null
            ]);
        } catch (\Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);

            $this->logger?->log("Mailgun API request failed", [
                'to' => $message->getTo(),
                'subject' => $message->getSubject(),
                'duration_ms' => $duration,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
    }

    private function validateConfig(): void
    {
        $required = ['api_key', 'domain'];
        $missing = array_filter($required, fn($key) => empty($this->config[$key]));

        if (!empty($missing)) {
            throw new \InvalidArgumentException(
                'Mailgun configuration is incomplete. Missing: ' . implode(', ', $missing)
            );
        }

        // Validate from configuration
        if (isset($this->config['from']) && is_array($this->config['from'])) {
            if (empty($this->config['from']['address'])) {
                throw new \InvalidArgumentException('From address is required in Mailgun configuration');
            }

            if (!filter_var($this->config['from']['address'], FILTER_VALIDATE_EMAIL)) {
                throw new \InvalidArgumentException('Invalid from email address in Mailgun configuration');
            }
        }

        // Validate region if provided
        if (isset($this->config['region']) && !in_array($this->config['region'], $this->supportedRegions)) {
            throw new \InvalidArgumentException(
                'Invalid Mailgun region. Supported regions: ' . implode(', ', $this->supportedRegions)
            );
        }

        // Validate timeout settings
        if (isset($this->config['timeout']) && (!is_int($this->config['timeout']) || $this->config['timeout'] < 1)) {
            throw new \InvalidArgumentException('Timeout must be a positive integer');
        }

        if (isset($this->config['connect_timeout']) && (!is_int($this->config['connect_timeout']) || $this->config['connect_timeout'] < 1)) {
            throw new \InvalidArgumentException('Connect timeout must be a positive integer');
        }
    }

    private function setupEndpoint(): void
    {
        $region = $this->config['region'] ?? 'us';
        $baseUrl = $region === 'eu' ? 'https://api.eu.mailgun.net' : 'https://api.mailgun.net';
        $this->endpoint = "{$baseUrl}/v3/{$this->config['domain']}/messages";

        // Set timeouts from config
        $this->timeout = $this->config['timeout'] ?? 30;
        $this->connectTimeout = $this->config['connect_timeout'] ?? 10;
    }

    private function preparePayload(Message $message): array
    {
        $postData = [
            'from' => $message->getFrom(),
            'to' => $message->getTo(),
            'subject' => $message->getSubject(),
        ];

        // Handle content based on type
        $this->addContentToPayload($postData, $message);

        // Add custom headers including DKIM signature
        $this->addCustomHeaders($postData, $message);

        // Add attachments
        $this->addAttachments($postData, $message);

        // Add optional parameters
        $this->addOptionalParameters($postData);

        return $postData;
    }

    private function addContentToPayload(array &$postData, Message $message): void
    {
        switch ($message->getContentType()) {
            case Message::CONTENT_TYPE_HTML:
                $postData['html'] = $message->getContent();
                break;

            case Message::CONTENT_TYPE_TEXT:
                $postData['text'] = $message->getContent();
                break;

            case Message::CONTENT_TYPE_ALTERNATIVE:
                // For alternative content, assume HTML is primary
                $postData['html'] = $message->getContent();
                // You might want to add a method to get plain text version
                // $postData['text'] = $message->getPlainTextContent();
                break;

            case Message::CONTENT_TYPE_MIXED:
                // Handle mixed content - typically HTML with attachments
                $postData['html'] = $message->getContent();
                break;

            default:
                // Default to text
                $postData['text'] = $message->getContent();
        }
    }

    private function addCustomHeaders(array &$postData, Message $message): void
    {
        $headers = $message->getHeaders();
        $customHeaders = [];

        // Add DKIM signature if present
        if ($message->getDkimSignature()) {
            $dkimSignature = $message->getDkimSignature();

            // Extract just the signature value (remove "DKIM-Signature:" prefix if present)
            if (stripos($dkimSignature, 'DKIM-Signature:') === 0) {
                $dkimSignature = trim(substr($dkimSignature, 15));
            }

            $customHeaders['DKIM-Signature'] = $dkimSignature;

            $this->logger?->log("Adding DKIM signature to Mailgun headers", [
                'signature_length' => strlen($dkimSignature),
                'to' => $message->getTo()
            ]);
        }

        // Add other custom headers (exclude standard ones that Mailgun handles)
        $standardHeaders = ['From', 'To', 'Subject', 'Date', 'Message-ID', 'Content-Type', 'MIME-Version'];

        foreach ($headers as $name => $value) {
            if (!in_array($name, $standardHeaders) && !empty($value)) {
                $customHeaders[$name] = $value;
            }
        }

        // Add custom headers to payload
        if (!empty($customHeaders)) {
            foreach ($customHeaders as $name => $value) {
                $postData["h:{$name}"] = $value;
            }
        }
    }

    private function addAttachments(array &$postData, Message $message): void
    {
        $attachmentIndex = 0;

        foreach ($message->getAttachments() as $attachment) {
            $path = is_array($attachment) ? ($attachment['path'] ?? null) : $attachment;

            if (!is_string($path) || !file_exists($path)) {
                $this->logger?->log("Attachment file not found", [
                    'file' => $attachment,
                    'to' => $message->getTo()
                ]);
                continue;
            }

            if (!is_readable($path)) {
                $this->logger?->log("Attachment file not readable", [
                    'file' => $attachment,
                    'to' => $message->getTo()
                ]);
                continue;
            }

            // Get MIME type with fallback
            $mimeType = is_array($attachment) ? ($attachment['mime_type'] ?? null) : null;
            if (!$mimeType) {
                $mimeType = mime_content_type($path) ?: 'application/octet-stream';
            }

            // Get filename with fallback
            $filename = is_array($attachment) ? ($attachment['name'] ?? null) : null;
            if (!$filename) {
                $filename = basename($path);
            }

            // Use indexed keys for multiple attachments to avoid array conversion issues
            $postData["attachment[{$attachmentIndex}]"] = new CURLFile($path, $mimeType, $filename);
            $attachmentIndex++;
        }
    }

    private function addOptionalParameters(array &$postData): void
    {
        // Add tracking options if configured
        if (isset($this->config['tracking']['clicks'])) {
            $postData['o:tracking-clicks'] = $this->config['tracking']['clicks'] ? 'yes' : 'no';
        }

        if (isset($this->config['tracking']['opens'])) {
            $postData['o:tracking-opens'] = $this->config['tracking']['opens'] ? 'yes' : 'no';
        }

        // Add delivery time if configured
        if (isset($this->config['delivery_time'])) {
            $postData['o:deliverytime'] = $this->config['delivery_time'];
        }

        // Add tags if configured
        if (isset($this->config['tags']) && is_array($this->config['tags'])) {
            foreach ($this->config['tags'] as $tag) {
                $postData['o:tag'][] = $tag;
            }
        }

        // Add custom variables if configured
        if (isset($this->config['variables']) && is_array($this->config['variables'])) {
            foreach ($this->config['variables'] as $key => $value) {
                $postData["v:{$key}"] = $value;
            }
        }
    }

    protected function makeRequest(array $postData): array
    {
        $ch = curl_init();

        // Check if we have file uploads (CURLFile objects)
        $hasFiles = $this->hasFileUploads($postData);

        $curlOptions = [
            CURLOPT_URL => $this->endpoint,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_USERPWD => 'api:' . $this->config['api_key'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'MonkeysLegion-Mailer/1.0',
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
            ],
        ];

        // If we don't have files, we can send as form data
        if (!$hasFiles) {
            $curlOptions[CURLOPT_POSTFIELDS] = http_build_query($postData);
            $curlOptions[CURLOPT_HTTPHEADER][] = 'Content-Type: application/x-www-form-urlencoded';
        }
        // If we have files, cURL will automatically set Content-Type to multipart/form-data

        curl_setopt_array($ch, $curlOptions);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);

        curl_close($ch);

        if ($curlErrno !== 0) {
            throw new \RuntimeException("cURL error ({$curlErrno}): {$curlError}");
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $this->handleApiError($httpCode, $response);
        }

        $decodedResponse = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("Invalid JSON response from Mailgun API: " . json_last_error_msg());
        }

        return $decodedResponse;
    }

    /**
     * Check if the post data contains file uploads (CURLFile objects)
     */
    private function hasFileUploads(array $data): bool
    {
        foreach ($data as $value) {
            if ($value instanceof CURLFile) {
                return true;
            }
            if (is_array($value)) {
                foreach ($value as $item) {
                    if ($item instanceof CURLFile) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    private function handleApiError(int $httpCode, string $response): void
    {
        $decodedResponse = json_decode($response, true);
        $message = $decodedResponse['message'] ?? 'Unknown error';

        switch ($httpCode) {
            case 400:
                throw new \InvalidArgumentException("Bad Request: {$message}");
            case 401:
                throw new \RuntimeException("Unauthorized: Invalid API key or domain");
            case 402:
                throw new \RuntimeException("Payment Required: {$message}");
            case 404:
                throw new \RuntimeException("Not Found: Domain not found or not configured");
            case 413:
                throw new \RuntimeException("Request Entity Too Large: {$message}");
            case 429:
                throw new \RuntimeException("Rate Limited: {$message}");
            case 500:
                throw new \RuntimeException("Internal Server Error: {$message}");
            case 502:
            case 503:
            case 504:
                throw new \RuntimeException("Service Unavailable: {$message}");
            default:
                throw new \RuntimeException("Mailgun API Error (HTTP {$httpCode}): {$message}");
        }
    }

    /**
     * Get the Mailgun API endpoint URL
     */
    public function getEndpoint(): string
    {
        return $this->endpoint;
    }

    /**
     * Get the configured domain
     */
    public function getDomain(): string
    {
        return $this->config['domain'];
    }

    /**
     * Get the configured region
     */
    public function getRegion(): string
    {
        return $this->config['region'] ?? 'us';
    }
}
