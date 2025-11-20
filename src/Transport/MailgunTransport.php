<?php

namespace MonkeysLegion\Mail\Transport;

use CURLFile;
use MonkeysLegion\Logger\Contracts\MonkeysLoggerInterface;
use MonkeysLegion\Mail\Enums\MailDefaults;
use MonkeysLegion\Mail\Enums\MailDriverName;
use MonkeysLegion\Mail\Enums\MailgunRegion;
use MonkeysLegion\Mail\Message;
use MonkeysLegion\Mail\TransportInterface;

class MailgunTransport implements TransportInterface
{
    private string $endpoint;
    private string $apiKey;
    private string $domain;
    /** @var array<string, string> */
    private array $from;
    private string $region;
    private int $timeout;
    private int $connectTimeout;
    /** @var array<string, bool> */
    private array $tracking;
    private ?string $deliveryTime = null;
    /** @var array<int, string> */
    private array $tags = [];
    /** @var array<string, mixed> */
    private array $variables = [];

    /**
     * @param array<string, mixed> $config
     * @param MonkeysLoggerInterface|null $logger
     */
    public function __construct(
        array $config,
        private ?MonkeysLoggerInterface $logger = null
    ) {
        $this->validateAndSetConfig($config);
        $this->setupEndpoint();

        $this->logger?->debug('Mailgun transport initialized', [
            'domain' => $this->domain,
            'region' => $this->region,
            'from' => $this->from,
            'endpoint' => $this->endpoint
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
        // Validate required fields
        $required = ['api_key', 'domain'];
        $missing = array_filter($required, function ($key) use ($config) {
            return !isset($config[$key]) || !is_string($config[$key]) || trim($config[$key]) === '';
        });

        if (!empty($missing)) {
            throw new \InvalidArgumentException(
                'Mailgun configuration is incomplete. Missing or Not Valid: ' . implode(', ', $missing)
            );
        }

        // Set validated required values
        $this->apiKey = safeString($config['api_key']);
        $this->domain = safeString($config['domain']);

        // Validate and assign 'from'
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
            throw new \InvalidArgumentException("Mailgun configuration must include 'from' address");
        }

        // Validate and assign region if provided
        $regionString = safeString($config['region'] ?? MailgunRegion::US->value);
        try {
            $region = MailgunRegion::from($regionString);
            $this->region = $region->value;
        } catch (\ValueError $e) {
            $warning = "Invalid Mailgun region '{$regionString}'. Supported regions: " .
                implode(', ', array_map(fn($r) => $r->value, MailgunRegion::cases()));
            $this->logger?->warning($warning);
            throw new \InvalidArgumentException($warning);
        }

        // Timeout settings
        if (!isset($config['timeout']) || !is_int($config['timeout']) || $config['timeout'] <= 0) {
            throw new \InvalidArgumentException("Invalid timeout value. Must be a positive integer.");
        }
        $this->timeout = $config['timeout'];

        if (!isset($config['connect_timeout']) || !is_int($config['connect_timeout']) || $config['connect_timeout'] <= 0) {
            throw new \InvalidArgumentException("Invalid connect_timeout value. Must be a positive integer.");
        }
        $this->connectTimeout = $config['connect_timeout'];

        // Tracking options
        $this->tracking = [];
        if (isset($config['tracking']) && is_array($config['tracking'])) {
            $this->tracking = [
                'clicks' => isset($config['tracking']['clicks']) ? (bool)$config['tracking']['clicks'] : true,
                'opens' => isset($config['tracking']['opens']) ? (bool)$config['tracking']['opens'] : true
            ];
        }

        // Delivery time
        if (isset($config['delivery_time']) && !empty($config['delivery_time'])) {
            $this->deliveryTime = safeString($config['delivery_time']);
        }

        // Tags
        if (isset($config['tags']) && is_array($config['tags']) && !empty($config['tags'])) {
            $tags = array_values(array_filter($config['tags'], fn($tag) => is_string($tag)));
            $this->tags = array_slice($tags, 0, 3); // keep only first 3
        }

        // Variables
        if (isset($config['variables']) && is_array($config['variables'])) {
            $validVars = [];
            foreach ($config['variables'] as $key => $value) {
                if (is_string($key)) {
                    $validVars[$key] = $value;
                }
            }
            $this->variables = $validVars;
        }
    }

    public function send(Message $message): void
    {
        $startTime = microtime(true);

        try {
            $this->logger?->smartLog("Preparing Mailgun API request", [
                'to' => $message->getTo(),
                'subject' => $message->getSubject(),
                'endpoint' => $this->endpoint
            ]);

            $postData = $this->preparePayload($message);
            $response = $this->makeRequest($postData);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            $this->logger?->smartLog("Mailgun API request successful", [
                'to' => $message->getTo(),
                'subject' => $message->getSubject(),
                'duration_ms' => $duration,
                'mailgun_id' => $response['id'] ?? null,
                'message' => $response['message'] ?? null
            ]);
        } catch (\Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);

            $this->logger?->error("Mailgun API request failed", [
                'to' => $message->getTo(),
                'subject' => $message->getSubject(),
                'duration_ms' => $duration,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
    }

    private function setupEndpoint(): void
    {
        $baseUrl = $this->region === MailgunRegion::EU->value ? 'https://api.eu.mailgun.net' : 'https://api.mailgun.net';
        $this->endpoint = "{$baseUrl}/v3/{$this->domain}/messages";
    }

    /**
     * @param Message $message
     * @return array<string, mixed>  // Associative array of post data with mixed values (strings, CURLFile objects, arrays)
     */
    private function preparePayload(Message $message): array
    {
        // If from is not set in the message, use our configured from
        $from = $message->getFrom();
        if (empty($from)) {
            $from = "{$this->from['name']} <{$this->from['address']}>";
        }

        $postData = [
            'from' => $from,
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

    /**
     * @param array<string, mixed> $postData Passed by reference
     * @param Message $message
     * @return void
     */
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
                $postData['html'] = $message->getContent();
                // You might want to add a method to get plain text version (TODO)
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

    /**
     * @param array<string, mixed> $postData Passed by reference
     * @param Message $message
     * @return void
     */
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

            $this->logger?->smartLog("Adding DKIM signature to Mailgun headers", [
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

    /**
     * @param array<string, mixed> $postData Passed by reference
     * @param Message $message
     * @return void
     */
    private function addAttachments(array &$postData, Message $message): void
    {
        $attachmentIndex = 0;

        foreach ($message->getAttachments() as $attachment) {
            /** @var array<string, mixed>|string $attachment */
            try {
                $normalized = normalizeAttachment($attachment, base_path('/public'), true);
            } catch (\RuntimeException $e) {
                $this->logger?->warning("Attachment error: " . $e->getMessage(), [
                    'file' => $attachment,
                    'to' => $message->getTo()
                ]);
                continue;
            }

            $postData["attachment[{$attachmentIndex}]"] = new CURLFile(
                $normalized['full_path'] ?? $normalized['path'],
                $normalized['mime_type'],
                $normalized['filename']
            );

            $attachmentIndex++;
        }
    }


    /**
     * @param array<string, mixed> $postData Passed by reference
     * @return void
     */
    private function addOptionalParameters(array &$postData): void
    {
        // Add tracking options
        if (isset($this->tracking['clicks'])) {
            $postData['o:tracking-clicks'] = $this->tracking['clicks'] ? 'yes' : 'no';
        }

        if (isset($this->tracking['opens'])) {
            $postData['o:tracking-opens'] = $this->tracking['opens'] ? 'yes' : 'no';
        }

        // Add delivery time if configured
        if ($this->deliveryTime) {
            $postData['o:deliverytime'] = $this->deliveryTime;
        }

        // Add tags if configured
        if (!empty($this->tags)) {
            $postData['o:tag'] = $this->tags;
        }

        // Add custom variables if configured
        foreach ($this->variables as $key => $value) {
            $postData["v:{$key}"] = $value;
        }
    }

    /**
     * Make the actual API request to Mailgun
     * @param array<string, mixed> $postData Data to send in the POST request
     * @return array<string, mixed> Decoded JSON response from Mailgun API
     * @throws \RuntimeException If the request fails or response is invalid
     */
    protected function makeRequest(array $postData): array
    {
        $ch = curl_init();

        // Check if we have file uploads (CURLFile objects)
        $hasFiles = $this->hasFileUploads($postData);

        $curlOptions = [
            CURLOPT_URL => $this->endpoint,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_USERPWD => 'api:' . $this->apiKey,
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

        if (!$hasFiles) {
            $curlOptions[CURLOPT_POSTFIELDS] = http_build_query($postData);
            $curlOptions[CURLOPT_HTTPHEADER][] = 'Content-Type: application/x-www-form-urlencoded';
        }

        curl_setopt_array($ch, $curlOptions);

        /** @var string|false $response */
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);

        curl_close($ch);

        if ($response === false) {
            $this->logger?->error("cURL request failed", [
                'errno' => $curlErrno,
                'error' => $curlError,
            ]);
            throw new \RuntimeException("cURL request failed: {$curlError}");
        }

        if ($curlErrno !== 0) {
            $this->logger?->error("cURL error", [
                'errno' => $curlErrno,
                'error' => $curlError,
                'endpoint' => $this->endpoint,
            ]);
            throw new \RuntimeException("cURL error ({$curlErrno}): {$curlError}");
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $this->logger?->error("Mailgun API returned HTTP {$httpCode}", [
                'response' => $response,
                'endpoint' => $this->endpoint,
            ]);
            $this->handleApiError($httpCode, $response);
        }

        /** @var array<string, mixed> $decodedResponse */
        $decodedResponse = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger?->error("Invalid JSON from Mailgun API", [
                'error' => json_last_error_msg(),
                'response' => $response,
                'endpoint' => $this->endpoint,
            ]);
            throw new \RuntimeException("Invalid JSON response from Mailgun API: " . json_last_error_msg());
        }

        return $decodedResponse;
    }

    /**
     * Check if the post data contains file uploads (CURLFile objects)
     * @param array<string, mixed> $data The post data to check
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
        if (!is_array($decodedResponse)) {
            throw new \RuntimeException("Invalid API response format");
        }
        $message = 'Unknown error';

        if (isset($decodedResponse['message'])) {
            if (is_string($decodedResponse['message'])) {
                $message = $decodedResponse['message'];
            } elseif (is_scalar($decodedResponse['message'])) {
                $message = (string)$decodedResponse['message'];
            } else {
                $message = json_encode($decodedResponse['message'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: 'Unknown error';
            }
        }

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
        return $this->domain;
    }

    /**
     * Get the configured region
     */
    public function getRegion(): string
    {
        return $this->region;
    }

    public function getName(): string
    {
        return MailDriverName::MAILGUN->value;
    }
}
