<?php

namespace MonkeysLegion\Mailer\Tests\Transport;

use MonkeysLegion\Mail\Logger\Logger;
use MonkeysLegion\Mail\Message;
use MonkeysLegion\Mail\Transport\SmtpTransport;
use PHPUnit\Framework\TestCase;

class SmtpTransportTest extends TestCase
{
    private Logger $logger;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(Logger::class);
    }

    public function testConstructorSetsConfiguration()
    {
        $config = [
            'host' => 'smtp.example.com',
            'port' => 587,
            'encryption' => 'tls',
            'username' => 'user@example.com',
            'password' => 'password'
        ];

        $transport = new SmtpTransport($config, $this->logger);

        $this->assertEquals('smtp.example.com', $transport->getHost());
        $this->assertEquals(587, $transport->getPort());
        $this->assertEquals('tls', $transport->getEncryption());
        $this->assertEquals('user@example.com', $transport->getUsername());
    }

    public function testSetAndGetAuth()
    {
        $config = ['host' => 'smtp.example.com', 'port' => 587];
        $transport = new SmtpTransport($config, $this->logger);

        $transport->setAuth('newuser@example.com', 'newpassword');

        $this->assertEquals('newuser@example.com', $transport->getUsername());
    }

    public function testSetAndGetFrom()
    {
        $config = ['host' => 'smtp.example.com', 'port' => 587];
        $transport = new SmtpTransport($config, $this->logger);

        $transport->setFrom('sender@example.com', 'Sender Name');

        $this->assertEquals('sender@example.com', $transport->getFromAddress());
        $this->assertEquals('Sender Name', $transport->getFromName());
    }

    public function testSetFromWithInvalidEmailThrowsException()
    {
        $config = ['host' => 'smtp.example.com', 'port' => 587];
        $transport = new SmtpTransport($config, $this->logger);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid email address');

        $transport->setFrom('invalid-email');
    }

    public function testSetAndGetHost()
    {
        $config = ['host' => 'smtp.example.com', 'port' => 587];
        $transport = new SmtpTransport($config, $this->logger);

        $transport->setHost('new.smtp.example.com');

        $this->assertEquals('new.smtp.example.com', $transport->getHost());
    }

    public function testSetHostWithEmptyValueThrowsException()
    {
        $config = ['host' => 'smtp.example.com', 'port' => 587];
        $transport = new SmtpTransport($config, $this->logger);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('SMTP host cannot be empty');

        $transport->setHost('');
    }

    public function testSetAndGetPort()
    {
        $config = ['host' => 'smtp.example.com', 'port' => 587];
        $transport = new SmtpTransport($config, $this->logger);

        $transport->setPort(465);

        $this->assertEquals(465, $transport->getPort());
    }

    public function testSetPortWithInvalidValueThrowsException()
    {
        $config = ['host' => 'smtp.example.com', 'port' => 587];
        $transport = new SmtpTransport($config, $this->logger);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid SMTP port');

        $transport->setPort(0);
    }

    public function testSetAndGetEncryption()
    {
        $config = ['host' => 'smtp.example.com', 'port' => 587];
        $transport = new SmtpTransport($config, $this->logger);

        $transport->setEncryption('ssl');

        $this->assertEquals('ssl', $transport->getEncryption());
    }

    public function testSetEncryptionWithInvalidValueThrowsException()
    {
        $config = ['host' => 'smtp.example.com', 'port' => 587];
        $transport = new SmtpTransport($config, $this->logger);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid encryption method');

        $transport->setEncryption('invalid');
    }

    public function testSetAndGetTimeout()
    {
        $config = ['host' => 'smtp.example.com', 'port' => 587];
        $transport = new SmtpTransport($config, $this->logger);

        $transport->setTimeout(60);

        $this->assertEquals(60, $transport->getTimeout());
    }

    public function testSetTimeoutWithInvalidValueThrowsException()
    {
        $config = ['host' => 'smtp.example.com', 'port' => 587];
        $transport = new SmtpTransport($config, $this->logger);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid timeout');

        $transport->setTimeout(-1);
    }

    public function testGetNameReturnsSmtp()
    {
        $config = ['host' => 'smtp.example.com', 'port' => 587];
        $transport = new SmtpTransport($config, $this->logger);

        $this->assertEquals('smtp', $transport->getName());
    }

    public function testGetConfigReturnsConfiguration()
    {
        $config = [
            'host' => 'smtp.example.com',
            'port' => 587,
            'encryption' => 'tls'
        ];
        $transport = new SmtpTransport($config, $this->logger);

        $returnedConfig = $transport->getConfig();

        $this->assertEquals('smtp.example.com', $returnedConfig['host']);
        $this->assertEquals(587, $returnedConfig['port']);
        $this->assertEquals('tls', $returnedConfig['encryption']);
    }

    public function testSetConfigMergesConfiguration()
    {
        $initialConfig = ['host' => 'smtp.example.com', 'port' => 587];
        $transport = new SmtpTransport($initialConfig, $this->logger);

        $newConfig = ['encryption' => 'ssl', 'timeout' => 60];
        $transport->setConfig($newConfig);

        $finalConfig = $transport->getConfig();
        $this->assertEquals('smtp.example.com', $finalConfig['host']);
        $this->assertEquals(587, $finalConfig['port']);
        $this->assertEquals('ssl', $finalConfig['encryption']);
        $this->assertEquals(60, $finalConfig['timeout']);
    }
}
