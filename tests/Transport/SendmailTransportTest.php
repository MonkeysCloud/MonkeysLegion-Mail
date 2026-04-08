<?php

declare(strict_types=1);

/**
 * Namespace-level function mocks for the SendmailTransport namespace.
 * These override PHP built-in functions when called from MonkeysLegion\Mail\Transport.
 */
namespace MonkeysLegion\Mail\Transport;

use MonkeysLegion\Mailer\Tests\Transport\SendmailTransportTest;
use MonkeysLegion\Mailer\Tests\Transport\SmtpTransportTest;

if (!function_exists('MonkeysLegion\Mail\Transport\is_executable')) {
    function is_executable(string $path): bool
    {
        return SendmailTransportTest::$isExecutable ?? \is_executable($path);
    }
}

if (!function_exists('MonkeysLegion\Mail\Transport\proc_open')) {
    function proc_open(string $command, array $descriptorspec, array &$pipes): mixed
    {
        if (SendmailTransportTest::$procOpenReturn !== null) {
            $pipes = SendmailTransportTest::$mockedPipes;
            return SendmailTransportTest::$procOpenReturn;
        }
        return \proc_open($command, $descriptorspec, $pipes);
    }
}

if (!function_exists('MonkeysLegion\Mail\Transport\proc_close')) {
    function proc_close(mixed $process): int
    {
        return SendmailTransportTest::$procCloseReturn ?? 0;
    }
}

if (!function_exists('MonkeysLegion\Mail\Transport\fwrite')) {
    function fwrite(mixed $handle, string $string): int|false
    {
        if (isset(SmtpTransportTest::$socketMock) && SmtpTransportTest::$socketMock === $handle) {
            SmtpTransportTest::$lastWrittenData .= $string;
            return strlen($string);
        }
        if (SendmailTransportTest::$procOpenReturn !== null) {
            // Sendmail uses pipes
            return strlen($string);
        }
        return \fwrite($handle, $string);
    }
}

if (!function_exists('MonkeysLegion\Mail\Transport\fclose')) {
    function fclose(mixed $handle): bool
    {
        return true;
    }
}

if (!function_exists('MonkeysLegion\Mail\Transport\stream_get_contents')) {
    function stream_get_contents(mixed $handle): string|false
    {
        return SendmailTransportTest::$streamContents ?? '';
    }
}

namespace MonkeysLegion\Mailer\Tests\Transport;

use InvalidArgumentException;
use MonkeysLegion\Logger\Contracts\MonkeysLoggerInterface;
use MonkeysLegion\Mail\Message;
use MonkeysLegion\Mail\Transport\SendmailTransport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use RuntimeException;

#[CoversClass(SendmailTransport::class)]
#[AllowMockObjectsWithoutExpectations]
class SendmailTransportTest extends TestCase
{
    private MonkeysLoggerInterface&MockObject $logger;
    /** @var array<string, mixed> */
    private array $validConfig;

    // Static state for namespace mocks
    public static ?bool $isExecutable = null;
    /** @var resource|false|null */
    public static mixed $procOpenReturn = null;
    /** @var array<int, mixed> */
    public static array $mockedPipes = [];
    public static ?int $procCloseReturn = null;
    public static ?string $streamContents = null;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(MonkeysLoggerInterface::class);

        $this->validConfig = [
            'path' => '/usr/sbin/sendmail',
            'from' => [
                'address' => 'from@example.com',
                'name'    => 'From Name',
            ],
        ];

        // Reset all static mocks
        self::$isExecutable  = null;
        self::$procOpenReturn = null;
        self::$mockedPipes   = [];
        self::$procCloseReturn = null;
        self::$streamContents  = null;
    }

    protected function tearDown(): void
    {
        self::$isExecutable  = null;
        self::$procOpenReturn = null;
        self::$mockedPipes   = [];
        self::$procCloseReturn = null;
        self::$streamContents  = null;
    }

    // ──────────────────────────────────── Constructor ─────────────────────────────

    #[Test]
    #[TestDox('Constructor with valid config creates transport')]
    public function test_constructor_sets_configuration(): void
    {
        $transport = new SendmailTransport($this->validConfig, $this->logger);
        $this->assertInstanceOf(SendmailTransport::class, $transport);
        $this->assertEquals('sendmail', $transport->getName());
    }

    #[Test]
    #[TestDox('Constructor with missing from config throws exception')]
    public function test_constructor_throws_on_missing_from(): void
    {
        $config = $this->validConfig;
        unset($config['from']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Sendmail configuration must include 'from' address");
        new SendmailTransport($config, $this->logger);
    }

    #[Test]
    #[TestDox('Constructor with non-array from throws exception')]
    public function test_constructor_throws_on_invalid_from_type(): void
    {
        $config = $this->validConfig;
        $config['from'] = 'not-an-array';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Sendmail configuration must include 'from' address");
        new SendmailTransport($config, $this->logger);
    }

    #[Test]
    #[TestDox('Constructor with invalid from email throws exception')]
    public function test_constructor_throws_on_invalid_from_email(): void
    {
        $config = $this->validConfig;
        $config['from']['address'] = 'bad-email';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid 'from' email address format");
        new SendmailTransport($config, $this->logger);
    }

    #[Test]
    #[TestDox('Constructor with empty from email throws exception')]
    public function test_constructor_throws_on_empty_from_email(): void
    {
        $config = $this->validConfig;
        $config['from']['address'] = '';

        $this->expectException(InvalidArgumentException::class);
        new SendmailTransport($config, $this->logger);
    }

    #[Test]
    #[TestDox('Constructor warns when sendmail binary is not found')]
    public function test_constructor_warns_when_binary_not_found(): void
    {
        $config = $this->validConfig;
        $config['path'] = '/nonexistent/sendmail';

        $this->logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('Sendmail binary path not found'));

        new SendmailTransport($config, $this->logger);
    }

    #[Test]
    #[TestDox('Constructor uses default path when path not provided')]
    public function test_constructor_uses_default_path(): void
    {
        $config = $this->validConfig;
        unset($config['path']);

        // Default path usually doesn't exist in CI – just ensure object is made
        $transport = new SendmailTransport($config, $this->logger);
        $this->assertInstanceOf(SendmailTransport::class, $transport);
    }

    #[Test]
    #[TestDox('Constructor logs debug on success')]
    public function test_constructor_logs_debug(): void
    {
        $this->logger->expects($this->atLeastOnce())
            ->method('debug')
            ->with('Sendmail transport initialized', $this->isArray());

        new SendmailTransport($this->validConfig, $this->logger);
    }

    #[Test]
    #[TestDox('Constructor works without logger')]
    public function test_constructor_without_logger(): void
    {
        $transport = new SendmailTransport($this->validConfig);
        $this->assertInstanceOf(SendmailTransport::class, $transport);
    }

    #[Test]
    #[TestDox('Constructor handles empty from name')]
    public function test_constructor_handles_empty_from_name(): void
    {
        $config = $this->validConfig;
        $config['from']['name'] = '';

        $transport = new SendmailTransport($config, $this->logger);
        $this->assertInstanceOf(SendmailTransport::class, $transport);
    }

    // ──────────────────────────────────── Send ────────────────────────────────────

    #[Test]
    #[TestDox('Send throws when binary is not executable')]
    public function test_send_throws_when_not_executable(): void
    {
        self::$isExecutable = false;

        $transport = new SendmailTransport($this->validConfig, $this->logger);
        $message   = new Message('to@example.com', 'Subject', 'Body');

        $this->logger->expects($this->atLeastOnce())->method('error');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Sendmail binary not found or not executable');
        $transport->send($message);
    }

    #[Test]
    #[TestDox('Send sets from address when message has none')]
    public function test_send_sets_from_address_when_message_has_none(): void
    {
        self::$isExecutable    = true;
        self::$procOpenReturn  = fopen('php://memory', 'r+'); // fake resource
        self::$mockedPipes     = [fopen('php://memory', 'r+'), fopen('php://memory', 'r+'), fopen('php://memory', 'r+')];
        self::$procCloseReturn = 0;
        self::$streamContents  = '';

        $transport = new SendmailTransport($this->validConfig, $this->logger);
        $message   = new Message('to@example.com', 'Subject', 'Body');
        // From is empty – transport must fill it
        $this->assertEmpty($message->getFrom());

        $transport->send($message);

        $this->assertNotEmpty($message->getFrom());
        $this->assertStringContainsString('from@example.com', $message->getFrom());
    }

    #[Test]
    #[TestDox('Send preserves existing from address in message')]
    public function test_send_preserves_existing_from_address(): void
    {
        self::$isExecutable    = true;
        self::$procOpenReturn  = fopen('php://memory', 'r+');
        self::$mockedPipes     = [fopen('php://memory', 'r+'), fopen('php://memory', 'r+'), fopen('php://memory', 'r+')];
        self::$procCloseReturn = 0;
        self::$streamContents  = '';

        $transport = new SendmailTransport($this->validConfig, $this->logger);
        $message   = new Message('to@example.com', 'Subject', 'Body');
        $message->setFrom('original@example.com');

        $transport->send($message);

        $this->assertEquals('original@example.com', $message->getFrom());
    }

    #[Test]
    #[TestDox('Send includes all message headers')]
    public function test_send_includes_all_message_headers(): void
    {
        self::$isExecutable    = true;
        self::$procOpenReturn  = fopen('php://memory', 'r+');
        self::$mockedPipes     = [fopen('php://memory', 'r+'), fopen('php://memory', 'r+'), fopen('php://memory', 'r+')];
        self::$procCloseReturn = 0;
        self::$streamContents  = '';

        $transport = new SendmailTransport($this->validConfig, $this->logger);
        $message   = new Message('to@example.com', 'Subject', 'Body');

        // Should not throw
        $transport->send($message);
        $this->assertIsArray($message->getHeaders());
    }

    #[Test]
    #[TestDox('Send throws when proc_open fails')]
    public function test_send_throws_when_proc_open_fails(): void
    {
        self::$isExecutable   = true;
        self::$procOpenReturn = false; // simulate proc_open failure

        $transport = new SendmailTransport($this->validConfig, $this->logger);
        $message   = new Message('to@example.com', 'Subject', 'Body');

        $this->logger->expects($this->atLeastOnce())->method('error');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to open sendmail process');
        $transport->send($message);
    }

    #[Test]
    #[TestDox('Send throws when proc_close returns non-zero exit code')]
    public function test_send_throws_on_non_zero_exit_code(): void
    {
        self::$isExecutable    = true;
        self::$procOpenReturn  = fopen('php://memory', 'r+');
        self::$mockedPipes     = [fopen('php://memory', 'r+'), fopen('php://memory', 'r+'), fopen('php://memory', 'r+')];
        self::$procCloseReturn = 1; // Non-zero = failure
        self::$streamContents  = 'Some error output';

        $transport = new SendmailTransport($this->validConfig, $this->logger);
        $message   = new Message('to@example.com', 'Subject', 'Body');

        $this->logger->expects($this->atLeastOnce())->method('error');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Sendmail failed with exit code 1');
        $transport->send($message);
    }

    #[Test]
    #[TestDox('Send logs debug with command details')]
    public function test_send_logs_debug_with_command_details(): void
    {
        self::$isExecutable    = true;
        self::$procOpenReturn  = fopen('php://memory', 'r+');
        self::$mockedPipes     = [fopen('php://memory', 'r+'), fopen('php://memory', 'r+'), fopen('php://memory', 'r+')];
        self::$procCloseReturn = 0;
        self::$streamContents  = '';

        $transport = new SendmailTransport($this->validConfig, $this->logger);
        $message   = new Message('to@example.com', 'Subject', 'Body');

        $this->logger->expects($this->atLeastOnce())
            ->method('debug')
            ->with($this->stringContains('Sending email via sendmail'), $this->isArray());

        $transport->send($message);
    }

    #[Test]
    #[TestDox('Send logs info on success')]
    public function test_send_logs_success_message(): void
    {
        self::$isExecutable    = true;
        self::$procOpenReturn  = fopen('php://memory', 'r+');
        self::$mockedPipes     = [fopen('php://memory', 'r+'), fopen('php://memory', 'r+'), fopen('php://memory', 'r+')];
        self::$procCloseReturn = 0;
        self::$streamContents  = '';

        $transport = new SendmailTransport($this->validConfig, $this->logger);
        $message   = new Message('to@example.com', 'Subject', 'Body');

        $this->logger->expects($this->atLeastOnce())
            ->method('info')
            ->with('Email sent successfully via sendmail');

        $transport->send($message);
    }

    #[Test]
    #[TestDox('Send logs error with output details when sendmail fails')]
    public function test_send_logs_error_on_failure_with_output_details(): void
    {
        self::$isExecutable    = true;
        self::$procOpenReturn  = fopen('php://memory', 'r+');
        self::$mockedPipes     = [fopen('php://memory', 'r+'), fopen('php://memory', 'r+'), fopen('php://memory', 'r+')];
        self::$procCloseReturn = 2;
        self::$streamContents  = 'Relay denied';

        $transport = new SendmailTransport($this->validConfig, $this->logger);
        $message   = new Message('to@example.com', 'Subject', 'Body');

        $this->logger->expects($this->atLeastOnce())
            ->method('error')
            ->with($this->stringContains('Sendmail failed'), $this->isArray());

        $this->expectException(RuntimeException::class);
        $transport->send($message);
    }

    #[Test]
    #[TestDox('Get name returns sendmail')]
    public function test_get_name_returns_sendmail(): void
    {
        $transport = new SendmailTransport($this->validConfig, $this->logger);
        $this->assertEquals('sendmail', $transport->getName());
    }
}
