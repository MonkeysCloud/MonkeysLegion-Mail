<?php

namespace MonkeysLegion\Mailer\Tests\Transport;

use MonkeysLegion\Core\Contracts\FrameworkLoggerInterface;
use MonkeysLegion\Mail\Message;
use MonkeysLegion\Mail\Transport\MailgunTransport;
use PHPUnit\Framework\TestCase;

class MailgunTransportTest extends TestCase
{
    private FrameworkLoggerInterface $logger;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(FrameworkLoggerInterface::class);
    }

    public function testConstructorValidConfigSetsEndpoint()
    {
        $config = [
            'api_key' => 'test-key',
            'domain' => 'mg.example.com',
            'region' => 'eu'
        ];
        $transport = new MailgunTransport($config, $this->logger);
        $this->assertStringContainsString('api.eu.mailgun.net', $transport->getEndpoint());
        $this->assertEquals('mg.example.com', $transport->getDomain());
        $this->assertEquals('eu', $transport->getRegion());
    }

    public function testConstructorMissingApiKeyThrowsException()
    {
        $config = ['domain' => 'mg.example.com'];
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Mailgun configuration is incomplete');
        new MailgunTransport($config, $this->logger);
    }

    public function testConstructorMissingDomainThrowsException()
    {
        $config = ['api_key' => 'test-key'];
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Mailgun configuration is incomplete');
        new MailgunTransport($config, $this->logger);
    }

    public function testConstructorInvalidRegionThrowsException()
    {
        $config = [
            'api_key' => 'test-key',
            'domain' => 'mg.example.com',
            'region' => 'asia'
        ];
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid Mailgun region');
        new MailgunTransport($config, $this->logger);
    }

    public function testConstructorInvalidTimeoutThrowsException()
    {
        $config = [
            'api_key' => 'test-key',
            'domain' => 'mg.example.com',
            'timeout' => 0
        ];
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Timeout must be a positive integer');
        new MailgunTransport($config, $this->logger);
    }

    public function testConstructorInvalidConnectTimeoutThrowsException()
    {
        $config = [
            'api_key' => 'test-key',
            'domain' => 'mg.example.com',
            'connect_timeout' => 0
        ];
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Connect timeout must be a positive integer');
        new MailgunTransport($config, $this->logger);
    }

    public function testSendLogsSuccessAndFailure()
    {
        $config = [
            'api_key' => 'test-key',
            'domain' => 'mg.example.com'
        ];
        $transport = $this->getMockBuilder(MailgunTransport::class)
            ->setConstructorArgs([$config, $this->logger])
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
            ->method('log');

        $transport->send($message);
    }

    public function testSendThrowsExceptionOnApiError()
    {
        $config = [
            'api_key' => 'test-key',
            'domain' => 'mg.example.com'
        ];
        $transport = $this->getMockBuilder(MailgunTransport::class)
            ->setConstructorArgs([$config, $this->logger])
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
            ->method('log');

        $this->expectException(\RuntimeException::class);
        $transport->send($message);
    }

    public function testGetEndpointReturnsCorrectUrl()
    {
        $config = [
            'api_key' => 'test-key',
            'domain' => 'mg.example.com'
        ];
        $transport = new MailgunTransport($config, $this->logger);
        $this->assertStringContainsString('api.mailgun.net', $transport->getEndpoint());
    }

    public function testGetDomainReturnsConfiguredDomain()
    {
        $config = [
            'api_key' => 'test-key',
            'domain' => 'mg.example.com'
        ];
        $transport = new MailgunTransport($config, $this->logger);
        $this->assertEquals('mg.example.com', $transport->getDomain());
    }

    public function testGetRegionDefaultsToUs()
    {
        $config = [
            'api_key' => 'test-key',
            'domain' => 'mg.example.com'
        ];
        $transport = new MailgunTransport($config, $this->logger);
        $this->assertEquals('us', $transport->getRegion());
    }
}
