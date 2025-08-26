<?php

namespace MonkeysLegion\Mailer\Tests\Transport;

use MonkeysLegion\Core\Contracts\FrameworkLoggerInterface;
use MonkeysLegion\Mail\Message;
use MonkeysLegion\Mail\Transport\MailgunTransport;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

class MailgunTransportTest extends TestCase
{
    private FrameworkLoggerInterface&MockObject $logger;
    /** @var array<string, mixed> */
    private array $validConfig;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(FrameworkLoggerInterface::class);

        // Setup a valid config to be used in most tests
        $this->validConfig = [
            'api_key' => 'test-key',
            'domain' => 'mg.example.com',
            'region' => 'us',
            'timeout' => 30,
            'connect_timeout' => 10,
            'from' => [
                'address' => 'from@example.com',
                'name' => 'From Name'
            ],
            'tracking' => [
                'clicks' => true,
                'opens' => true
            ]
        ];
    }

    public function testConstructorValidConfigSetsEndpoint(): void
    {
        $config = $this->validConfig;
        $config['region'] = 'eu';

        $transport = new MailgunTransport($config, $this->logger);

        $this->assertStringContainsString('api.eu.mailgun.net', $transport->getEndpoint());
        $this->assertEquals('mg.example.com', $transport->getDomain());
        $this->assertEquals('eu', $transport->getRegion());
    }

    public function testConstructorMissingApiKeyThrowsException(): void
    {
        $invalidConfig = $this->validConfig;
        unset($invalidConfig['api_key']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Mailgun configuration is incomplete. Missing or Not Valid: api_key');

        new MailgunTransport($invalidConfig, $this->logger);
    }

    public function testConstructorMissingDomainThrowsException(): void
    {
        $invalidConfig = $this->validConfig;
        unset($invalidConfig['domain']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Mailgun configuration is incomplete. Missing or Not Valid: domain');

        new MailgunTransport($invalidConfig, $this->logger);
    }

    public function testConstructorEmptyApiKeyThrowsException(): void
    {
        $invalidConfig = $this->validConfig;
        $invalidConfig['api_key'] = '';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Mailgun configuration is incomplete. Missing or Not Valid: api_key');

        new MailgunTransport($invalidConfig, $this->logger);
    }

    public function testConstructorEmptyDomainThrowsException(): void
    {
        $invalidConfig = $this->validConfig;
        $invalidConfig['domain'] = '';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Mailgun configuration is incomplete. Missing or Not Valid: domain');

        new MailgunTransport($invalidConfig, $this->logger);
    }

    public function testConstructorInvalidRegionThrowsException(): void
    {
        $invalidConfig = $this->validConfig;
        $invalidConfig['region'] = 'asia';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid Mailgun region');

        new MailgunTransport($invalidConfig, $this->logger);
    }

    public function testConstructorInvalidTimeoutThrowsException(): void
    {
        $invalidConfig = $this->validConfig;
        $invalidConfig['timeout'] = 0;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid timeout value. Must be a positive integer.');

        new MailgunTransport($invalidConfig, $this->logger);
    }

    public function testConstructorInvalidTimeoutTypeThrowsException(): void
    {
        $invalidConfig = $this->validConfig;
        $invalidConfig['timeout'] = '30'; // String instead of int

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid timeout value. Must be a positive integer.');

        new MailgunTransport($invalidConfig, $this->logger);
    }

    public function testConstructorInvalidConnectTimeoutThrowsException(): void
    {
        $invalidConfig = $this->validConfig;
        $invalidConfig['connect_timeout'] = 0;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid connect_timeout value. Must be a positive integer.');

        new MailgunTransport($invalidConfig, $this->logger);
    }

    public function testConstructorInvalidConnectTimeoutTypeThrowsException(): void
    {
        $invalidConfig = $this->validConfig;
        $invalidConfig['connect_timeout'] = '10'; // String instead of int

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid connect_timeout value. Must be a positive integer.');

        new MailgunTransport($invalidConfig, $this->logger);
    }

    public function testConstructorMissingFromConfigThrowsException(): void
    {
        $invalidConfig = $this->validConfig;
        unset($invalidConfig['from']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Mailgun configuration must include 'from' address");

        new MailgunTransport($invalidConfig, $this->logger);
    }

    public function testConstructorInvalidFromConfigThrowsException(): void
    {
        $invalidConfig = $this->validConfig;
        $invalidConfig['from'] = 'not an array';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Mailgun configuration must include 'from' address");

        new MailgunTransport($invalidConfig, $this->logger);
    }

    public function testConstructorInvalidFromEmailThrowsException(): void
    {
        $invalidConfig = $this->validConfig;
        if (is_array($invalidConfig['from'])) {
            $invalidConfig['from']['address'] = 'invalid-email';
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid \'from\' email address format');

        new MailgunTransport($invalidConfig, $this->logger);
    }

    public function testConstructorWithDefaultRegion(): void
    {
        $config = $this->validConfig;
        unset($config['region']); // Should default to 'us'

        $transport = new MailgunTransport($config, $this->logger);

        $this->assertEquals('us', $transport->getRegion());
        $this->assertStringContainsString('api.mailgun.net', $transport->getEndpoint());
    }

    public function testSendLogsSuccessAndFailure(): void
    {
        $transport = $this->getMockBuilder(MailgunTransport::class)
            ->setConstructorArgs([$this->validConfig, $this->logger])
            ->onlyMethods(['makeRequest'])
            ->getMock();

        $message = $this->createMock(Message::class);
        $message->method('getTo')->willReturn('to@example.com');
        $message->method('getSubject')->willReturn('Test Subject');
        $message->method('getAttachments')->willReturn([]);
        $message->method('getContentType')->willReturn(Message::CONTENT_TYPE_TEXT);
        $message->method('getContent')->willReturn('Test body');
        $message->method('getHeaders')->willReturn([]);
        $message->method('getDkimSignature')->willReturn(null);
        $message->method('getFrom')->willReturn('from@example.com');

        $transport->expects($this->once())
            ->method('makeRequest')
            ->willReturn(['id' => 'test-id', 'message' => 'Queued']);

        $this->logger->expects($this->atLeastOnce())
            ->method('smartLog');

        $transport->send($message);
    }

    public function testSendThrowsExceptionOnApiError(): void
    {
        $transport = $this->getMockBuilder(MailgunTransport::class)
            ->setConstructorArgs([$this->validConfig, $this->logger])
            ->onlyMethods(['makeRequest'])
            ->getMock();

        $message = $this->createMock(Message::class);
        $message->method('getTo')->willReturn('to@example.com');
        $message->method('getSubject')->willReturn('Test Subject');
        $message->method('getAttachments')->willReturn([]);
        $message->method('getContentType')->willReturn(Message::CONTENT_TYPE_TEXT);
        $message->method('getContent')->willReturn('Test body');
        $message->method('getHeaders')->willReturn([]);
        $message->method('getDkimSignature')->willReturn(null);
        $message->method('getFrom')->willReturn('from@example.com');

        $transport->expects($this->once())
            ->method('makeRequest')
            ->will($this->throwException(new \RuntimeException('API error')));

        $this->logger->expects($this->atLeastOnce())
            ->method('error');

        $this->expectException(\RuntimeException::class);
        $transport->send($message);
    }

    public function testGetEndpointReturnsCorrectUrl(): void
    {
        $transport = new MailgunTransport($this->validConfig, $this->logger);
        $this->assertStringContainsString('api.mailgun.net', $transport->getEndpoint());
    }

    public function testGetDomainReturnsConfiguredDomain(): void
    {
        $transport = new MailgunTransport($this->validConfig, $this->logger);
        $this->assertEquals('mg.example.com', $transport->getDomain());
    }

    public function testGetRegionDefaultsToUs(): void
    {
        $config = $this->validConfig;
        unset($config['region']);

        $transport = new MailgunTransport($config, $this->logger);
        $this->assertEquals('us', $transport->getRegion());
    }

    public function testGetNameReturnsMailgun(): void
    {
        $transport = new MailgunTransport($this->validConfig, $this->logger);
        $this->assertEquals('mailgun', $transport->getName());
    }
}
