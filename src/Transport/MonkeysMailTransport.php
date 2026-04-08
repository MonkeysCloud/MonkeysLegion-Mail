<?php

declare(strict_types=1);

namespace MonkeysLegion\Mail\Transport;

use MonkeysLegion\Logger\Contracts\MonkeysLoggerInterface;
use MonkeysLegion\Mail\Message;
use MonkeysLegion\Mail\TransportInterface;

use Exception;
use InvalidArgumentException;
use MonkeysLegion\Mail\Enums\MailDriverName;
use RuntimeException;

class MonkeysMailTransport implements TransportInterface
{
    private string $apiKey;
    private string $fromEmail;
    private string $fromName;
    /** @var array<string, mixed> */
    private array $config;

    /**
     * @param array<string, mixed> $config
     * @param MonkeysLoggerInterface|null $logger
     */
    public function __construct(
        array $config,
        private ?MonkeysLoggerInterface $logger = null
    ) {
        $this->validateAndSetConfig($config);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function validateAndSetConfig(array $config): void
    {
        $required = ['api_key'];

        foreach ($required as $key) {
            if (!isset($config[$key])) {
                throw new InvalidArgumentException("MonkeysMail configuration is missing a valid '$key'");
            }
        }
        if (!is_string($config['api_key'])) {
            throw new InvalidArgumentException("MonkeysMail configuration is missing a valid 'api_key'");
        }
        if (
            !is_array($config['from'] ?? null) ||
            !isset($config['from']['address'], $config['from']['name']) ||
            !is_string($config['from']['address']) ||
            !is_string($config['from']['name'])
        ) {
            $config['from']['name'] = 'MonkeysMail';
        }
        $this->apiKey = $config['api_key'];
        $this->fromEmail = $config['from']['address'] ?? '';
        $this->fromName = $config['from']['name'];
        $this->config = $config;
    }

    public function send(Message $message): void
    {
        $startTime = microtime(true);

        try {
            $to = array_map('trim', explode(',', $message->getTo()));

            $email = empty($message->getFromEmail()) || !filter_var($message->getFromEmail(), FILTER_VALIDATE_EMAIL)
                ? $this->fromEmail
                : $message->getFromEmail();
            $name = empty($message->getFromName()) || !is_string($message->getFromName())
                ? $this->fromName
                : $message->getFromName();

            $payload = [
                'from' => [
                    'email' => $email,
                    'name'  => $name
                ],
                'to'      => $to,
                'subject' => $message->getSubject(),
                'text'    => $message->getTextBody(),
                'html'    => $message->getHtmlBody(),
                'tracking' => [
                    'opens'  => (bool)($this->config['tracking_opens'] ?? true),
                    'clicks' => (bool)($this->config['tracking_clicks'] ?? true)
                ]
            ];

            $this->makeRequest($payload);

            $duration = round((microtime(true) - $startTime) * 1000, 2);
            $this->logger?->smartLog("MonkeysMail API request successful", [
                'to' => $message->getTo(),
                'subject' => $message->getSubject(),
                'duration_ms' => $duration
            ]);
        } catch (Exception $e) {
            $this->logger?->error("MonkeysMail API request failed", [
                'to' => $message->getTo(),
                'subject' => $message->getSubject(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function makeRequest(array $payload): void
    {
        $url = isset($this->config['api_url']) ? $this->config['url'] : null;
        if ($url !== null && !filter_var($url, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException("Invalid 'api_url' provided in MonkeysMail configuration");
        }
        $ch = curl_init($url);

        $headers = [
            'Content-Type: application/json',
            'X-API-Key: ' . $this->apiKey,
        ];

        $jsonPayload = json_encode($payload);
        if ($jsonPayload === false) {
            throw new RuntimeException('Failed to encode payload as JSON');
        }

        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        if ($response === false) {
            throw new RuntimeException("cURL request failed: {$curlError}");
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException("MonkeysMail API returned HTTP {$httpCode}: {$response}");
        }
    }

    public function getName(): string
    {
        return MailDriverName::MONKEYS_MAIL->value;
    }
}
