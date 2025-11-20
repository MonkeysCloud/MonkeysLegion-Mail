<?php

namespace MonkeysLegion\Mailer\Tests\Transport;

use MonkeysLegion\Logger\Contracts\MonkeysLoggerInterface;
use MonkeysLegion\Mail\Transport\SmtpTransport;
use PHPUnit\Framework\TestCase;

class SmtpTransportTest extends TestCase
{
    private MonkeysLoggerInterface $logger;
    /** @var array<string, mixed> */
    private array $validConfig;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(MonkeysLoggerInterface::class);

        // Setup a valid config to be used in most tests
        $this->validConfig = [
            'host' => 'smtp.example.com',
            'port' => 587,
            'encryption' => 'tls',
            'username' => 'user@example.com',
            'password' => 'password',
            'timeout' => 30,
            'from' => [
                'address' => 'from@example.com',
                'name' => 'From Name'
            ]
        ];
    }

    public function testConstructorSetsConfiguration(): void
    {
        $transport = new SmtpTransport($this->validConfig, $this->logger);

        $this->assertEquals('smtp.example.com', $transport->getHost());
        $this->assertEquals(587, $transport->getPort());
        $this->assertEquals('tls', $transport->getEncryption());
        $this->assertEquals('user@example.com', $transport->getUsername());
        $this->assertEquals('from@example.com', $transport->getFromAddress());
        $this->assertEquals('From Name', $transport->getFromName());
        $this->assertEquals(30, $transport->getTimeout());
    }

    public function testConstructorWithMissingConfigKeysThrowsException(): void
    {
        $invalidConfig = [
            'host' => 'smtp.example.com',
            // Missing required keys
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Missing required config key: 'port'");

        new SmtpTransport($invalidConfig, $this->logger);
    }

    public function testConstructorWithInvalidHostTypeThrowsException(): void
    {
        $invalidConfig = $this->validConfig;
        $invalidConfig['host'] = 123; // Not a string

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Config 'host' must be a string");

        new SmtpTransport($invalidConfig, $this->logger);
    }

    public function testConstructorWithInvalidPortTypeThrowsException(): void
    {
        $invalidConfig = $this->validConfig;
        $invalidConfig['port'] = '587'; // Not an integer

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Config 'port' must be an integer");

        new SmtpTransport($invalidConfig, $this->logger);
    }

    public function testConstructorWithInvalidEncryptionValueThrowsException(): void
    {
        $invalidConfig = $this->validConfig;
        $invalidConfig['encryption'] = 'invalid'; // Not a valid encryption value

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid encryption value 'invalid'. Supported: ssl, tls, none");

        new SmtpTransport($invalidConfig, $this->logger);
    }

    public function testConstructorWithInvalidFromConfigThrowsException(): void
    {
        $invalidConfig = $this->validConfig;
        $invalidConfig['from'] = 'not an array';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Config 'from' must be an array");

        new SmtpTransport($invalidConfig, $this->logger);
    }

    public function testConstructorWithInvalidFromKeysThrowsException(): void
    {
        $invalidConfig = $this->validConfig;
        $invalidConfig['from'] = [
            // Missing required keys
            'invalid' => 'value'
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Config 'from' must be an array with string keys 'address' and 'name'");

        new SmtpTransport($invalidConfig, $this->logger);
    }

    public function testValidateEncryptionWithNullValue(): void
    {
        $config = $this->validConfig;
        $config['encryption'] = null;

        $transport = new SmtpTransport($config, $this->logger);

        $this->assertEquals('none', $transport->getEncryption());
    }

    public function testValidateEncryptionWithEmptyStringValue(): void
    {
        $config = $this->validConfig;
        $config['encryption'] = '';

        $transport = new SmtpTransport($config, $this->logger);

        $this->assertEquals('none', $transport->getEncryption());
    }

    public function testSetAndGetAuth(): void
    {
        $transport = new SmtpTransport($this->validConfig, $this->logger);

        $transport->setAuth('newuser@example.com', 'newpassword');

        $this->assertEquals('newuser@example.com', $transport->getUsername());
    }

    public function testSetAndGetFrom(): void
    {
        $transport = new SmtpTransport($this->validConfig, $this->logger);

        $transport->setFrom('sender@example.com', 'Sender Name');

        $this->assertEquals('sender@example.com', $transport->getFromAddress());
        $this->assertEquals('Sender Name', $transport->getFromName());
    }

    public function testSetFromWithInvalidEmailThrowsException(): void
    {
        $transport = new SmtpTransport($this->validConfig, $this->logger);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid email address');

        $transport->setFrom('invalid-email');
    }

    public function testSetAndGetHost(): void
    {
        $transport = new SmtpTransport($this->validConfig, $this->logger);

        $transport->setHost('new.smtp.example.com');

        $this->assertEquals('new.smtp.example.com', $transport->getHost());
    }

    public function testSetHostWithEmptyValueThrowsException(): void
    {
        $transport = new SmtpTransport($this->validConfig, $this->logger);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('SMTP host cannot be empty');

        $transport->setHost('');
    }

    public function testSetAndGetPort(): void
    {
        $transport = new SmtpTransport($this->validConfig, $this->logger);

        $transport->setPort(465);

        $this->assertEquals(465, $transport->getPort());
    }

    public function testSetPortWithInvalidValueThrowsException(): void
    {
        $transport = new SmtpTransport($this->validConfig, $this->logger);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid SMTP port');

        $transport->setPort(0);
    }

    public function testSetAndGetEncryption(): void
    {
        $transport = new SmtpTransport($this->validConfig, $this->logger);

        $transport->setEncryption('ssl');

        $this->assertEquals('ssl', $transport->getEncryption());
    }

    public function testSetEncryptionWithInvalidValueThrowsException(): void
    {
        $transport = new SmtpTransport($this->validConfig, $this->logger);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid encryption method');

        $transport->setEncryption('invalid');
    }

    public function testSetAndGetTimeout(): void
    {
        $transport = new SmtpTransport($this->validConfig, $this->logger);

        $transport->setTimeout(60);

        $this->assertEquals(60, $transport->getTimeout());
    }

    public function testSetTimeoutWithInvalidValueThrowsException(): void
    {
        $transport = new SmtpTransport($this->validConfig, $this->logger);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid timeout');

        $transport->setTimeout(-1);
    }

    public function testGetNameReturnsSmtp(): void
    {
        $transport = new SmtpTransport($this->validConfig, $this->logger);

        $this->assertEquals('smtp', $transport->getName());
    }

    public function testGetConfigReturnsConfiguration(): void
    {
        $transport = new SmtpTransport($this->validConfig, $this->logger);

        $returnedConfig = $transport->getConfig();

        $this->assertEquals('smtp.example.com', $returnedConfig['host']);
        $this->assertEquals(587, $returnedConfig['port']);
        $this->assertEquals('tls', $returnedConfig['encryption']);
    }

    public function testSetConfigMergesConfiguration(): void
    {
        $transport = new SmtpTransport($this->validConfig, $this->logger);

        $newConfig = ['encryption' => 'ssl', 'timeout' => 60];
        $transport->setConfig($newConfig);

        $finalConfig = $transport->getConfig();
        $this->assertEquals('smtp.example.com', $finalConfig['host']);
        $this->assertEquals(587, $finalConfig['port']);
        $this->assertEquals('ssl', $finalConfig['encryption']);
        $this->assertEquals(60, $finalConfig['timeout']);
    }
}
