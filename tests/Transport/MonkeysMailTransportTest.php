<?php

declare(strict_types=1);

namespace MonkeysLegion\Mailer\Tests\Transport;

use MonkeysLegion\Logger\Contracts\MonkeysLoggerInterface;
use MonkeysLegion\Mail\Message;
use MonkeysLegion\Mail\Transport\MonkeysMailTransport;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\CoversClass;

use InvalidArgumentException;
use RuntimeException;

#[CoversClass(MonkeysMailTransport::class)]
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

        $this->expectException(InvalidArgumentException::class);
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
            ->will($this->throwException(new RuntimeException('API Error')));

        $this->logger->expects($this->once())
            ->method('error');

        $this->expectException(RuntimeException::class);
        $transport->send($message);
    }

    #[Test]
    public function testConstructorInvalidApiKeyType(): void
    {
        $invalidConfig = ['api_key' => 123]; // Not a string

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("MonkeysMail configuration is missing a valid 'api_key'");

        new MonkeysMailTransport($invalidConfig, $this->logger);
    }

    #[Test]
    public function testSendWithMultipleRecipients(): void
    {
        $transport = $this->getMockBuilder(MonkeysMailTransport::class)
            ->setConstructorArgs([$this->validConfig, $this->logger])
            ->onlyMethods(['makeRequest'])
            ->getMock();

        $message = $this->createMock(Message::class);
        $message->method('getTo')->willReturn('to1@example.com, to2@example.com, to3@example.com');
        $message->method('getSubject')->willReturn('Test Subject');
        $message->method('getContentType')->willReturn(Message::CONTENT_TYPE_TEXT);
        $message->method('getContent')->willReturn('Plain text');
        $message->method('getFromEmail')->willReturn('no-reply@monkeys.cloud');
        $message->method('getFromName')->willReturn('Support');
        $message->method('getTextBody')->willReturn('Plain text');
        $message->method('getHtmlBody')->willReturn('');

        $transport->expects($this->once())
            ->method('makeRequest')
            ->with($this->callback(function (array $payload) {
                return $payload['to'] === ['to1@example.com', 'to2@example.com', 'to3@example.com'];
            }));

        $transport->send($message);
    }

    #[Test]
    public function testSendWithCustomTrackingConfig(): void
    {
        $customConfig = [
            'api_key' => 'test-api-key',
            'tracking_opens' => false,
            'tracking_clicks' => false,
        ];

        $transport = $this->getMockBuilder(MonkeysMailTransport::class)
            ->setConstructorArgs([$customConfig, $this->logger])
            ->onlyMethods(['makeRequest'])
            ->getMock();

        $message = $this->createMock(Message::class);
        $message->method('getTo')->willReturn('to@example.com');
        $message->method('getSubject')->willReturn('Test Subject');
        $message->method('getContentType')->willReturn(Message::CONTENT_TYPE_HTML);
        $message->method('getContent')->willReturn('<h1>Hello</h1>');
        $message->method('getFromEmail')->willReturn('sender@example.com');
        $message->method('getFromName')->willReturn('Sender');
        $message->method('getTextBody')->willReturn('');
        $message->method('getHtmlBody')->willReturn('<h1>Hello</h1>');

        $transport->expects($this->once())
            ->method('makeRequest')
            ->with($this->callback(function (array $payload) {
                return $payload['tracking']['opens'] === false &&
                       $payload['tracking']['clicks'] === false;
            }));

        $transport->send($message);
    }

    #[Test]
    public function testSendLogsSuccessfulRequest(): void
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
        $message->method('getFromEmail')->willReturn('sender@example.com');
        $message->method('getFromName')->willReturn('Sender');
        $message->method('getTextBody')->willReturn('');
        $message->method('getHtmlBody')->willReturn('<h1>Hello</h1>');

        $this->logger->expects($this->once())
            ->method('smartLog')
            ->with(
                'MonkeysMail API request successful',
                $this->callback(function (array $context) {
                    return isset($context['to']) &&
                           isset($context['subject']) &&
                           isset($context['duration_ms']) &&
                           $context['to'] === 'to@example.com' &&
                           $context['subject'] === 'Test Subject';
                })
            );

        $transport->send($message);
    }

    #[Test]
    public function testConstructorWithoutLogger(): void
    {
        $transport = new MonkeysMailTransport($this->validConfig);
        $this->assertEquals('monkeys_mail', $transport->getName());
    }

    #[Test]
    public function testSendWithoutLogger(): void
    {
        $transport = $this->getMockBuilder(MonkeysMailTransport::class)
            ->setConstructorArgs([$this->validConfig, null])
            ->onlyMethods(['makeRequest'])
            ->getMock();

        $message = $this->createMock(Message::class);
        $message->method('getTo')->willReturn('to@example.com');
        $message->method('getSubject')->willReturn('Test Subject');
        $message->method('getContentType')->willReturn(Message::CONTENT_TYPE_TEXT);
        $message->method('getContent')->willReturn('Text');
        $message->method('getFromEmail')->willReturn('sender@example.com');
        $message->method('getFromName')->willReturn('Sender');
        $message->method('getTextBody')->willReturn('Text');
        $message->method('getHtmlBody')->willReturn('');

        $transport->expects($this->once())->method('makeRequest');

        // Should not throw even without logger
        $transport->send($message);
        $this->assertTrue(true);
    }

    #[Test]
    public function testMakeRequestThrowsOnCurlFailure(): void
    {
        $transport = new class(['api_key' => 'test-key']) extends MonkeysMailTransport {
            public function publicMakeRequest(array $payload): void {
                parent::makeRequest($payload);
            }
        };

        // Mock curl to return false
        $payload = [
            'from' => ['email' => 'sender@example.com', 'name' => 'Sender'],
            'to' => ['to@example.com'],
            'subject' => 'Test',
            'text' => 'Text',
            'html' => '',
            'tracking' => ['opens' => true, 'clicks' => true]
        ];

        // This will fail because we can't actually connect to the API
        $this->expectException(RuntimeException::class);
        $transport->publicMakeRequest($payload);
    }

    #[Test]
    public function testMakeRequestThrowsOnJsonEncodeFailure(): void
    {
        $transport = new class(['api_key' => 'test-key']) extends MonkeysMailTransport {
            public function publicMakeRequest(array $payload): void {
                parent::makeRequest($payload);
            }
        };

        // Create a payload that will fail JSON encoding (e.g., with invalid UTF-8)
        // Actually, PHP's json_encode rarely fails, so let's test with recursive data
        $recursive = [];
        $recursive['self'] = &$recursive;
        
        $payload = [
            'from' => ['email' => 'sender@example.com'],
            'to' => ['to@example.com'],
            'subject' => 'Test',
            'text' => 'Text',
            'html' => '',
            'tracking' => ['opens' => true],
            'recursive' => $recursive
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to encode payload as JSON');
        $transport->publicMakeRequest($payload);
    }
}
