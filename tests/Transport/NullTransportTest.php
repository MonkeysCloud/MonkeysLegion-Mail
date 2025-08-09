<?php

namespace MonkeysLegion\Mailer\Tests\Transport;

use MonkeysLegion\Core\Logger\MonkeyLogger;
use MonkeysLegion\Mail\Message;
use MonkeysLegion\Mail\Transport\NullTransport;
use PHPUnit\Framework\TestCase;

class NullTransportTest extends TestCase
{
    private MonkeyLogger $logger;
    /** @var array<string, mixed> */
    private array $validConfig;

    public function setUp(): void
    {
        $this->logger = $this->createMock(MonkeyLogger::class);

        // Setup a valid config to be used in most tests
        $this->validConfig = [
            'from' => [
                'address' => 'from@example.com',
                'name' => 'From Name'
            ]
        ];
    }

    public function testConstructorWithValidConfig(): void
    {
        $transport = new NullTransport($this->validConfig, $this->logger);

        $this->assertEquals('null', $transport->getName());
    }

    public function testConstructorWithMissingFromConfigThrowsException(): void
    {
        $invalidConfig = [
            // Missing 'from' configuration
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid or missing 'from.address' in config");

        new NullTransport($invalidConfig, $this->logger);
    }

    public function testConstructorWithInvalidFromConfigThrowsException(): void
    {
        $invalidConfig = [
            'from' => 'not an array'
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid or missing 'from.address' in config");

        new NullTransport($invalidConfig, $this->logger);
    }

    public function testConstructorWithMissingFromAddressThrowsException(): void
    {
        $invalidConfig = [
            'from' => [
                'name' => 'From Name'
                // Missing 'address'
            ]
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid or missing 'from.address' in config");

        new NullTransport($invalidConfig, $this->logger);
    }

    public function testConstructorWithInvalidEmailAddressThrowsException(): void
    {
        $invalidConfig = [
            'from' => [
                'address' => 'invalid-email',
                'name' => 'From Name'
            ]
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid or missing 'from.address' in config");

        new NullTransport($invalidConfig, $this->logger);
    }

    public function testConstructorWithValidConfigButMissingName(): void
    {
        $config = [
            'from' => [
                'address' => 'from@example.com'
                // Missing 'name' - should use default
            ]
        ];

        $transport = new NullTransport($config, $this->logger);

        $this->assertEquals('null', $transport->getName());
    }

    public function testSendDoesNotThrowException(): void
    {
        $this->expectNotToPerformAssertions();

        $transport = new NullTransport($this->validConfig, $this->logger);
        $message = new Message('test@example.com', 'Subject', 'Body');

        // Should not throw any exception
        $transport->send($message);
    }

    public function testSendWithInvalidEmailThrowsException(): void
    {
        $transport = new NullTransport($this->validConfig, $this->logger);
        $message = new Message('invalid-email', 'Subject', 'Body');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid email address');

        $transport->send($message);
    }

    public function testSendWithEmptySubjectThrowsException(): void
    {
        $transport = new NullTransport($this->validConfig, $this->logger);
        $message = new Message('test@example.com', '', 'Body');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Email subject cannot be empty');

        $transport->send($message);
    }

    public function testGetNameReturnsNull(): void
    {
        $transport = new NullTransport($this->validConfig, $this->logger);

        $this->assertEquals('null', $transport->getName());
    }

    public function testSendWithComplexMessage(): void
    {
        $this->expectNotToPerformAssertions();

        $transport = new NullTransport($this->validConfig, $this->logger);
        $message = new Message(
            'test@example.com',
            'Complex Subject',
            'Complex Body',
            Message::CONTENT_TYPE_HTML,
            ['/path/to/file.pdf']
        );

        // Should handle complex messages without issue
        $transport->send($message);
    }
}
