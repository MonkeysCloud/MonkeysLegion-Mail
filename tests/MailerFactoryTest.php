<?php

declare(strict_types=1);

namespace MonkeysLegion\Mailer\Tests;

use MonkeysLegion\DI\Container;
use MonkeysLegion\Logger\Contracts\MonkeysLoggerInterface;
use MonkeysLegion\Mail\MailerFactory;
use MonkeysLegion\Mail\Transport\MailgunTransport;
use MonkeysLegion\Mail\Transport\MonkeysMailTransport;
use MonkeysLegion\Mail\Transport\NullTransport;
use MonkeysLegion\Mail\Transport\SendmailTransport;
use MonkeysLegion\Mail\Transport\SmtpTransport;

use InvalidArgumentException;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;

#[CoversClass(MailerFactory::class)]
#[AllowMockObjectsWithoutExpectations]
class MailerFactoryTest extends TestCase
{
    /** @var MonkeysLoggerInterface&MockObject */
    private MonkeysLoggerInterface $logger;
    private Container $container;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(MonkeysLoggerInterface::class);
        $this->container = new Container();
    }

    #[Test]
    #[TestDox('Make creates SMTP transport')]
    public function makeCreatesSmtpTransport(): void
    {
        $config = [
            'driver' => 'smtp',
            'drivers' => [
                'smtp' => [
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
                ]
            ]
        ];

        $transport = MailerFactory::make($config, $this->logger);

        $this->assertInstanceOf(SmtpTransport::class, $transport);
        $this->assertEquals('smtp', $transport->getName());
    }

    #[Test]
    #[TestDox('Make creates Sendmail transport')]
    public function makeCreatesSendmailTransport(): void
    {
        $config = [
            'driver' => 'sendmail',
            'drivers' => [
                'sendmail' => [
                    'path' => '/usr/sbin/sendmail',
                    'from' => [
                        'address' => 'from@example.com',
                        'name' => 'From Name'
                    ]
                ]
            ]
        ];

        $transport = MailerFactory::make($config, $this->logger);

        $this->assertInstanceOf(SendmailTransport::class, $transport);
        $this->assertEquals('sendmail', $transport->getName());
    }

    #[Test]
    #[TestDox('Make creates Mailgun transport')]
    public function makeCreatesMailgunTransport(): void
    {
        $config = [
            'driver' => 'mailgun',
            'drivers' => [
                'mailgun' => [
                    'api_key' => 'test-key',
                    'domain' => 'mg.example.com',
                    'region' => 'us',
                    'timeout' => 30,
                    'connect_timeout' => 10,
                    'from' => [
                        'address' => 'from@example.com',
                        'name' => 'From Name'
                    ]
                ]
            ]
        ];

        $transport = MailerFactory::make($config, $this->logger);

        $this->assertInstanceOf(MailgunTransport::class, $transport);
        $this->assertEquals('mailgun', $transport->getName());
    }

    #[Test]
    #[TestDox('Make creates MonkeysMail transport')]
    public function makeCreatesMonkeysMailTransport(): void
    {
        $config = [
            'driver' => 'monkeys_mail',
            'drivers' => [
                'monkeys_mail' => [
                    'api_key' => 'test-key',
                    'endpoint' => 'https://api.monkeysmail.com',
                    'timeout' => 30,
                    'from' => [
                        'address' => 'from@example.com',
                        'name' => 'From Name'
                    ]
                ]
            ]
        ];

        $transport = MailerFactory::make($config, $this->logger);

        $this->assertInstanceOf(MonkeysMailTransport::class, $transport);
        $this->assertEquals('monkeys_mail', $transport->getName());
    }

    #[Test]
    #[TestDox('Make creates Null transport')]
    public function makeCreatesNullTransport(): void
    {
        $config = [
            'driver' => 'null',
            'drivers' => [
                'null' => [
                    'from' => [
                        'address' => 'from@example.com',
                        'name' => 'From Name'
                    ]
                ]
            ]
        ];

        $transport = MailerFactory::make($config, $this->logger);

        $this->assertInstanceOf(NullTransport::class, $transport);
        $this->assertEquals('null', $transport->getName());
    }

    #[Test]
    #[TestDox('Make defaults to null driver when no driver specified')]
    public function makeDefaultsToNullDriverWhenNoDriverSpecified(): void
    {
        $config = [
            'drivers' => [
                'null' => [
                    'from' => [
                        'address' => 'from@example.com',
                        'name' => 'From Name'
                    ]
                ]
            ]
        ];

        $transport = MailerFactory::make($config, $this->logger);

        $this->assertInstanceOf(NullTransport::class, $transport);
    }

    #[Test]
    #[TestDox('Make throws exception for unknown driver')]
    public function makeThrowsExceptionForUnknownDriver(): void
    {
        $config = [
            'driver' => 'unknown_driver',
            'drivers' => []
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown driver');

        MailerFactory::make($config, $this->logger);
    }

    #[Test]
    #[TestDox('Make throws exception when drivers config is missing')]
    public function makeThrowsExceptionWhenDriversConfigIsMissing(): void
    {
        $config = [
            'driver' => 'smtp'
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('No drivers configured');

        MailerFactory::make($config, $this->logger);
    }

    #[Test]
    #[TestDox('CreateTransport creates transport with custom config')]
    public function createTransportCreatesTransportWithCustomConfig(): void
    {
        $config = [
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

        $fullConfig = [
            'driver' => 'smtp',
            'drivers' => [
                'smtp' => $config
            ]
        ];

        $transport = MailerFactory::createTransport('smtp', $fullConfig, $this->logger);

        $this->assertInstanceOf(SmtpTransport::class, $transport);
    }

    #[Test]
    #[TestDox('SetDriver updates container with new transport')]
    public function setDriverUpdatesContainerWithNewTransport(): void
    {
        $config = [
            'driver' => 'smtp',  // Set initial driver to smtp
            'drivers' => [
                'smtp' => [
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
                ],
                'null' => [
                    'from' => [
                        'address' => 'from@example.com',
                        'name' => 'From Name'
                    ]
                ]
            ]
        ];

        $factory = new MailerFactory($this->logger, $config, $this->container);
        $transport = $factory->setDriver('smtp');

        $this->assertInstanceOf(SmtpTransport::class, $transport);
    }

    #[Test]
    #[TestDox('SetDriver logs error when driver change fails')]
    public function setDriverLogsErrorWhenDriverChangeFails(): void
    {
        $config = [
            'driver' => 'null',
            'drivers' => []
        ];

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                'Failed to set mail driver',
                $this->callback(function ($context) {
                    return isset($context['driver']) && isset($context['error']);
                })
            );

        $factory = new MailerFactory($this->logger, $config, $this->container);

        $this->expectException(InvalidArgumentException::class);

        $factory->setDriver('invalid_driver');
    }

    #[Test]
    #[TestDox('SetDriver throws exception for invalid driver name')]
    public function setDriverThrowsExceptionForInvalidDriverName(): void
    {
        $config = [
            'driver' => 'null',
            'drivers' => [
                'null' => [
                    'from' => [
                        'address' => 'from@example.com',
                        'name' => 'From Name'
                    ]
                ]
            ]
        ];

        $factory = new MailerFactory($this->logger, $config, $this->container);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown driver');

        $factory->setDriver('invalid');
    }

    #[Test]
    #[TestDox('Make works without logger')]
    public function makeWorksWithoutLogger(): void
    {
        $config = [
            'driver' => 'null',
            'drivers' => [
                'null' => [
                    'from' => [
                        'address' => 'from@example.com',
                        'name' => 'From Name'
                    ]
                ]
            ]
        ];

        $transport = MailerFactory::make($config);

        $this->assertInstanceOf(NullTransport::class, $transport);
    }

    #[Test]
    #[TestDox('CreateTransport works without logger')]
    public function createTransportWorksWithoutLogger(): void
    {
        $config = [
            'drivers' => [
                'null' => [
                    'from' => [
                        'address' => 'from@example.com',
                        'name' => 'From Name'
                    ]
                ]
            ]
        ];

        $transport = MailerFactory::createTransport('null', $config);

        $this->assertInstanceOf(NullTransport::class, $transport);
    }

    #[Test]
    #[TestDox('Make handles uppercase driver name')]
    public function makeHandlesUppercaseDriverName(): void
    {
        $config = [
            'driver' => 'SMTP',
            'drivers' => [
                'smtp' => [
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
                ]
            ]
        ];

        $transport = MailerFactory::make($config, $this->logger);

        $this->assertInstanceOf(SmtpTransport::class, $transport);
    }

    #[Test]
    #[TestDox('Make handles mixed case driver name')]
    public function makeHandlesMixedCaseDriverName(): void
    {
        $config = [
            'driver' => 'MailGun',
            'drivers' => [
                'mailgun' => [
                    'api_key' => 'test-key',
                    'domain' => 'mg.example.com',
                    'region' => 'us',
                    'timeout' => 30,
                    'connect_timeout' => 10,
                    'from' => [
                        'address' => 'from@example.com',
                        'name' => 'From Name'
                    ]
                ]
            ]
        ];

        $transport = MailerFactory::make($config, $this->logger);

        $this->assertInstanceOf(MailgunTransport::class, $transport);
    }
}
