<?php

declare(strict_types=1);

namespace MonkeysLegion\Mailer\Tests\Transport;

use MonkeysLegion\Logger\Contracts\MonkeysLoggerInterface;
use MonkeysLegion\Mail\Message;
use MonkeysLegion\Mail\Transport\MonkeysMailTransport;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

#[AllowMockObjectsWithoutExpectations]
class MonkeysMailTransportTest extends TestCase
{
    private MonkeysLoggerInterface&MockObject $logger;
    /** @var array<string, mixed> */
    private array $validConfig;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(MonkeysLoggerInterface::class);

        $this->validConfig = [
            'api_key' => 'test-api-key',
            'domain' => 'monkeys.cloud',
            'tracking_opens' => true,
            'tracking_clicks' => true,
            'from' => [
                'address' => 'no-reply@monkeys.cloud',
                'name' => 'Support'
            ]
        ];
    }

    public function testConstructorValidConfig(): void
    {
        $transport = new MonkeysMailTransport($this->validConfig, $this->logger);
        $this->assertEquals('monkeys_mail', $transport->getName());
    }

    public function testConstructorMissingApiKeyThrowsException(): void
    {
        $invalidConfig = $this->validConfig;
        unset($invalidConfig['api_key']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("MonkeysMail configuration is missing a valid 'api_key'");

        new MonkeysMailTransport($invalidConfig, $this->logger);
    }

    public function testSendSuccessfullyDispatchesRequest(): void
    {
        $transport = $this->getMockBuilder(MonkeysMailTransport::class)
            ->setConstructorArgs([$this->validConfig, $this->logger])
            ->onlyMethods(['makeRequest'])
            ->getMock();

        $message = $this->createMock(Message::class);
        $message->method('getTo')->willReturn('to@example.com');
        $message->method('getSubject')->willReturn('Test Subject');
        $message->method('getContentType')->willReturn(Message::CONTENT_TYPE_HTML);
        $message->method('getContent')->willReturn('<h1>Hello</h1>');
        $message->method('getFromEmail')->willReturn('no-reply@monkeys.cloud');
        $message->method('getFromName')->willReturn('Support');
        $message->method('getTextBody')->willReturn('');
        $message->method('getHtmlBody')->willReturn('<h1>Hello</h1>');

        $transport->expects($this->once())
            ->method('makeRequest')
            ->with($this->callback(function (array $payload) {
                return $payload['from']['email'] === 'no-reply@monkeys.cloud' &&
                       $payload['to'] === ['to@example.com'] &&
                       $payload['subject'] === 'Test Subject' &&
                       $payload['html'] === '<h1>Hello</h1>' &&
                       $payload['tracking']['opens'] === true;
            }));

        $transport->send($message);
    }

    public function testSendHandlesFailure(): void
    {
        $transport = $this->getMockBuilder(MonkeysMailTransport::class)
            ->setConstructorArgs([$this->validConfig, $this->logger])
            ->onlyMethods(['makeRequest'])
            ->getMock();

        $message = $this->createMock(Message::class);
        $message->method('getTo')->willReturn('to@example.com');
        $message->method('getSubject')->willReturn('Test Subject');
        $message->method('getContentType')->willReturn(Message::CONTENT_TYPE_HTML);
        $message->method('getContent')->willReturn('<h1>Hello</h1>');

        $transport->method('makeRequest')
            ->will($this->throwException(new \RuntimeException('API Error')));

        $this->logger->expects($this->once())
            ->method('error');

        $this->expectException(\RuntimeException::class);
        $transport->send($message);
    }
}
