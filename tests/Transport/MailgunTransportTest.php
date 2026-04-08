<?php

declare(strict_types=1);

namespace MonkeysLegion\Mail\Transport;

/**
 * Namespace-level cURL mocks for MailgunTransport testing
 */
function curl_init($url = null) {
    if (!\MonkeysLegion\Mailer\Tests\Transport\MailgunTransportTest::$mockingEnabled) return \curl_init($url);
    return \MonkeysLegion\Mailer\Tests\Transport\MailgunTransportTest::$curlInitReturn ?? \curl_init($url);
}

function curl_setopt_array($ch, $options) {
    if (!\MonkeysLegion\Mailer\Tests\Transport\MailgunTransportTest::$mockingEnabled) return \curl_setopt_array($ch, $options);
    \MonkeysLegion\Mailer\Tests\Transport\MailgunTransportTest::$lastCurlOptions = $options;
    return true;
}

function curl_exec($ch) {
    if (!\MonkeysLegion\Mailer\Tests\Transport\MailgunTransportTest::$mockingEnabled) return \curl_exec($ch);
    return \MonkeysLegion\Mailer\Tests\Transport\MailgunTransportTest::$curlExecReturn ?? false;
}

function curl_getinfo($ch, $opt = null) {
    if (!\MonkeysLegion\Mailer\Tests\Transport\MailgunTransportTest::$mockingEnabled) return \curl_getinfo($ch, $opt);
    if ($opt === CURLINFO_HTTP_CODE) {
        return \MonkeysLegion\Mailer\Tests\Transport\MailgunTransportTest::$curlHttpCode ?? 200;
    }
    return \curl_getinfo($ch, $opt);
}

function curl_error($ch) {
    if (!\MonkeysLegion\Mailer\Tests\Transport\MailgunTransportTest::$mockingEnabled) return \curl_error($ch);
    return \MonkeysLegion\Mailer\Tests\Transport\MailgunTransportTest::$curlError ?? '';
}

function curl_errno($ch) {
    if (!\MonkeysLegion\Mailer\Tests\Transport\MailgunTransportTest::$mockingEnabled) return \curl_errno($ch);
    return \MonkeysLegion\Mailer\Tests\Transport\MailgunTransportTest::$curlErrno ?? 0;
}

function curl_close($ch) {
    if (!\MonkeysLegion\Mailer\Tests\Transport\MailgunTransportTest::$mockingEnabled) return \curl_close($ch);
    return null;
}

if (!function_exists('MonkeysLegion\Mail\Transport\normalizeAttachment')) {
    function normalizeAttachment($attachment, $baseDir = null, $forCurl = false) {
        return [
            'full_path' => is_string($attachment) ? $attachment : ($attachment['path'] ?? 'path'),
            'mime_type' => 'text/plain',
            'filename' => 'test.txt',
            'is_url' => false,
            'boundary_encoded' => "MOCK_ATTACHMENT"
        ];
    }
}

namespace MonkeysLegion\Mailer\Tests\Transport;

use MonkeysLegion\Logger\Contracts\MonkeysLoggerInterface;
use MonkeysLegion\Mail\Message;
use MonkeysLegion\Mail\Transport\MailgunTransport;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(MailgunTransport::class)]
#[AllowMockObjectsWithoutExpectations]
class MailgunTransportTest extends TestCase
{
    private MonkeysLoggerInterface&MockObject $logger;
    private array $validConfig;

    // Static state for cURL mocks
    public static $curlInitReturn = 'curl_resource';
    public static $curlExecReturn = '{"id": "test-id", "message": "Queued"}';
    public static $curlHttpCode = 200;
    public static $curlError = '';
    public static $curlErrno = 0;
    public static $lastCurlOptions = [];
    public static $mockingEnabled = false;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(MonkeysLoggerInterface::class);

        $this->validConfig = [
            'api_key' => 'mg-key',
            'domain' => 'example.com',
            'from' => [
                'address' => 'from@example.com',
                'name' => 'Sender'
            ],
            'timeout' => 30,
            'connect_timeout' => 10,
            'region' => 'us',
            'tracking' => [
                'clicks' => true,
                'opens' => false
            ],
            'tags' => ['test', 'unit'],
            'variables' => ['user_id' => 123]
        ];

        // Reset cURL mock state
        self::$curlInitReturn = 'curl_resource';
        self::$curlExecReturn = '{"id": "test-id", "message": "Queued"}';
        self::$curlHttpCode = 200;
        self::$curlError = '';
        self::$curlErrno = 0;
        self::$lastCurlOptions = [];
        self::$mockingEnabled = true;
    }

    protected function tearDown(): void
    {
        self::$curlInitReturn = null;
        self::$curlExecReturn = null;
        self::$curlHttpCode = null;
        self::$curlError = null;
        self::$curlErrno = null;
        self::$lastCurlOptions = [];
        self::$mockingEnabled = false;
    }

    #[Test]
    #[TestDox('Constructor and basic getters')]
    public function test_constructor_and_getters(): void
    {
        $transport = new MailgunTransport($this->validConfig, $this->logger);
        
        $this->assertEquals('mailgun', $transport->getName());
        $this->assertEquals('example.com', $transport->getDomain());
        $this->assertEquals('us', $transport->getRegion());
        $this->assertStringContainsString('api.mailgun.net', $transport->getEndpoint());
    }

    #[Test]
    #[TestDox('Constructor with EU region')]
    public function test_constructor_eu_region(): void
    {
        $config = $this->validConfig;
        $config['region'] = 'eu';
        $transport = new MailgunTransport($config);
        
        $this->assertEquals('eu', $transport->getRegion());
        $this->assertStringContainsString('api.eu.mailgun.net', $transport->getEndpoint());
    }

    #[Test]
    #[TestDox('Constructor throws on invalid region')]
    public function test_constructor_invalid_region(): void
    {
        $config = $this->validConfig;
        $config['region'] = 'invalid';
        
        $this->expectException(\InvalidArgumentException::class);
        new MailgunTransport($config);
    }

    #[Test]
    #[TestDox('Constructor throws on missing config')]
    public function test_constructor_missing_config(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new MailgunTransport([]);
    }

    #[Test]
    #[TestDox('Send success path')]
    public function test_send_success(): void
    {
        $transport = new MailgunTransport($this->validConfig, $this->logger);
        $message = new Message('to@example.com', 'Subject', 'Content');
        
        $transport->send($message);
        
        $this->assertNotEmpty(self::$lastCurlOptions);
        $this->assertStringContainsString('api:mg-key', self::$lastCurlOptions[CURLOPT_USERPWD]);
    }

    #[Test]
    #[TestDox('Send different content types')]
    public function test_send_content_types(): void
    {
        $transport = new MailgunTransport($this->validConfig);
        
        // HTML
        $msgHtml = new Message('to@example.com', 'Sub', '<b>body</b>', Message::CONTENT_TYPE_HTML);
        $transport->send($msgHtml);
        $this->assertStringContainsString('html=%3Cb%3Ebody%3C%2Fb%3E', (string)self::$lastCurlOptions[CURLOPT_POSTFIELDS]);
        
        // Text
        $msgText = new Message('to@example.com', 'Sub', 'body', Message::CONTENT_TYPE_TEXT);
        $transport->send($msgText);
        $this->assertStringContainsString('text=body', self::$lastCurlOptions[CURLOPT_POSTFIELDS]);

        // Mixed/Alt (defaults to HTML in implementation)
        $msgMixed = new Message('to@example.com', 'Sub', 'body', Message::CONTENT_TYPE_MIXED);
        $transport->send($msgMixed);
        $this->assertStringContainsString('html=body', self::$lastCurlOptions[CURLOPT_POSTFIELDS]);
    }

    #[Test]
    #[TestDox('Send with attachments')]
    public function test_send_with_attachments(): void
    {
        $transport = new MailgunTransport($this->validConfig);
        
        $tempFile = '/tmp/mailgun_test_' . uniqid();
        file_put_contents($tempFile, 'test content');
        
        // Pass absolute path - our mock normalizeAttachment will handle it
        $message = new Message('to@example.com', 'Subject', 'Content', Message::CONTENT_TYPE_TEXT, [$tempFile]);
        
        $transport->send($message);
        
        // When attachments are present, POSTFIELDS is an array (multipart/form-data)
        $this->assertIsArray(self::$lastCurlOptions[CURLOPT_POSTFIELDS] ?? null);
        $this->assertArrayHasKey('attachment[0]', self::$lastCurlOptions[CURLOPT_POSTFIELDS]);
        
        @unlink($tempFile);
    }

    #[Test]
    #[TestDox('Send with DKIM signature and custom headers')]
    public function test_send_with_dkim_and_custom_headers(): void
    {
        $transport = new MailgunTransport($this->validConfig);
        $message = new Message('to@example.com', 'Subject', 'Content');
        $message->setDkimSignature('DKIM-Signature: v=1; a=rsa-sha256; ...');
        
        $transport->send($message);
        
        $payload = self::$lastCurlOptions[CURLOPT_POSTFIELDS];
        $this->assertStringContainsString('h%3ADKIM-Signature=v%3D1%3B+a%3Drsa-sha256%3B+...', $payload);
    }

    #[Test]
    #[TestDox('Send handles optional parameters like delivery time and tags')]
    public function test_send_optional_parameters(): void
    {
        $config = $this->validConfig;
        $config['delivery_time'] = 'tomorrow';
        $transport = new MailgunTransport($config);
        $message = new Message('to@example.com', 'Sub', 'Body');
        
        $transport->send($message);
        
        $payload = self::$lastCurlOptions[CURLOPT_POSTFIELDS];
        $this->assertStringContainsString('o%3Adeliverytime=tomorrow', $payload);
        $this->assertStringContainsString('o%3Atag%5B0%5D=test', $payload);
        $this->assertStringContainsString('v%3Auser_id=123', $payload);
    }

    #[Test]
    #[TestDox('Send handles cURL failure')]
    public function test_send_curl_failure(): void
    {
        self::$curlExecReturn = false;
        self::$curlError = 'Connection timeout';
        self::$curlErrno = 28;
        
        $transport = new MailgunTransport($this->validConfig, $this->logger);
        $message = new Message('a@b.com', 'S', 'B');
        
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cURL request failed');
        $transport->send($message);
    }

    #[Test]
    #[TestDox('Send handles API errors with various HTTP codes')]
    public function test_send_api_errors(): void
    {
        $transport = new MailgunTransport($this->validConfig);
        $message = new Message('a@b.com', 'S', 'B');

        $errors = [
            400 => \InvalidArgumentException::class,
            401 => \RuntimeException::class,
            402 => \RuntimeException::class,
            404 => \RuntimeException::class,
            413 => \RuntimeException::class,
            429 => \RuntimeException::class,
            500 => \RuntimeException::class,
            503 => \RuntimeException::class,
            418 => \RuntimeException::class, // default
        ];

        foreach ($errors as $code => $exception) {
            self::$curlHttpCode = $code;
            self::$curlExecReturn = json_encode(['message' => "Error $code"]);
            
            try {
                $transport->send($message);
                $this->fail("Should have thrown $exception for code $code");
            } catch (\Exception $e) {
                $this->assertInstanceOf($exception, $e, "Failed for code $code");
            }
        }
    }

    #[Test]
    #[TestDox('Send handles invalid JSON response')]
    public function test_send_invalid_json(): void
    {
        self::$curlExecReturn = 'invalid json';
        
        $transport = new MailgunTransport($this->validConfig);
        
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid JSON response');
        $transport->send(new Message('a@b.com', 'S', 'B'));
    }

    #[Test]
    #[TestDox('Setup handles non-standard from configs')]
    public function test_constructor_invalid_from(): void
    {
        $config = $this->validConfig;
        $config['from'] = 'invalid';
        $this->expectException(\InvalidArgumentException::class);
        new MailgunTransport($config);
    }

    #[Test]
    #[TestDox('Constructor handles missing connect_timeout')]
    public function test_constructor_timeouts(): void
    {
        $config = $this->validConfig;
        
        // Invalid timeout
        $config['timeout'] = 0;
        try {
            new MailgunTransport($config);
            $this->fail('Should throw for 0 timeout');
        } catch (\InvalidArgumentException) {}

        // Invalid connect_timeout
        $config = $this->validConfig;
        $config['connect_timeout'] = 0;
        try {
            new MailgunTransport($config);
            $this->fail('Should throw for 0 connect_timeout');
        } catch (\InvalidArgumentException) {}
    }
}
