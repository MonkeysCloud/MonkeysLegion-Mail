<?php

declare(strict_types=1);

namespace MonkeysLegion\Mailer\Tests\Jobs;

use MonkeysLegion\DI\Container;
use MonkeysLegion\Logger\Contracts\MonkeysLoggerInterface;
use MonkeysLegion\Mail\Jobs\SendMailJob;
use MonkeysLegion\Mail\Message;
use MonkeysLegion\Mail\TransportInterface;
use MonkeysLegion\Mailer\Tests\Abstracts\AbstractBaseTest;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;

#[CoversClass(SendMailJob::class)]
#[AllowMockObjectsWithoutExpectations]
class SendMailJobTest extends AbstractBaseTest
{
    private Message $message;
    private TransportInterface&MockObject $transport;
    private MonkeysLoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        // Bootstrap first to create the container instance
        $this->bootstrapServices();

        $this->message = new Message(
            'test@example.com',
            'Test Subject',
            'Test Content'
        );

        $this->transport = $this->createMock(TransportInterface::class);
        $this->logger = $this->createMock(MonkeysLoggerInterface::class);

        // Get container through static method on Container
        $container = new Container();
        Container::setInstance($container);
        
        // Register transport in container
        $container->set(TransportInterface::class, fn() => $this->transport);
        $container->set(MonkeysLoggerInterface::class, fn() => $this->logger);
    }

    #[Test]
    #[TestDox('Constructor resolves transport from container')]
    public function constructorResolvesTransportFromContainer(): void
    {
        $job = new SendMailJob($this->message);
        $this->assertInstanceOf(SendMailJob::class, $job);
    }

    #[Test]
    #[TestDox('Constructor throws exception when transport not configured')]
    public function constructorThrowsExceptionWhenTransportNotConfigured(): void
    {
        // Remove transport from container
        $container = new Container();
        Container::setInstance($container);
        $container->set(MonkeysLoggerInterface::class, fn() => $this->logger);
        // No transport set

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('No mail transport configured'),
                $this->anything()
            );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No mail transport configured');

        new SendMailJob($this->message);
    }

    #[Test]
    #[TestDox('Handle method sends message via transport')]
    public function handleMethodSendsMessageViaTransport(): void
    {
        $this->transport->expects($this->once())
            ->method('send')
            ->with($this->message);

        $job = new SendMailJob($this->message);
        $job->handle();
    }

    #[Test]
    #[TestDox('Handle method logs error and rethrows exception on failure')]
    public function handleMethodLogsErrorAndRethrowsExceptionOnFailure(): void
    {
        $exception = new \Exception('Transport failure');

        $this->transport->expects($this->once())
            ->method('send')
            ->with($this->message)
            ->willThrowException($exception);

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('SendMailJob failed'),
                $this->callback(function ($context) {
                    return isset($context['content'])
                        && isset($context['to'])
                        && isset($context['subject'])
                        && isset($context['attachments']);
                })
            );

        $job = new SendMailJob($this->message);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Transport failure');

        $job->handle();
    }

    #[Test]
    #[TestDox('GetData returns message information')]
    public function getDataReturnsMessageInformation(): void
    {
        $message = new Message(
            'recipient@example.com',
            'Important Subject',
            'Email content',
            Message::CONTENT_TYPE_HTML,
            ['/path/to/attachment.pdf']
        );

        $job = new SendMailJob($message);
        $data = $job->getData();

        $this->assertIsArray($data);
        $this->assertEquals('Email content', $data['content']);
        $this->assertEquals('recipient@example.com', $data['to']);
        $this->assertEquals('Important Subject', $data['subject']);
        $this->assertEquals(['/path/to/attachment.pdf'], $data['attachments']);
    }

    #[Test]
    #[TestDox('GetData returns empty attachments when none provided')]
    public function getDataReturnsEmptyAttachmentsWhenNoneProvided(): void
    {
        $message = new Message('test@example.com', 'Subject', 'Content');

        $job = new SendMailJob($message);
        $data = $job->getData();

        $this->assertIsArray($data['attachments']);
        $this->assertEmpty($data['attachments']);
    }

    #[Test]
    #[TestDox('Job handles message with HTML content type')]
    public function jobHandlesMessageWithHtmlContentType(): void
    {
        $message = new Message(
            'test@example.com',
            'HTML Email',
            '<h1>HTML Content</h1>',
            Message::CONTENT_TYPE_HTML
        );

        $this->transport->expects($this->once())
            ->method('send')
            ->with($this->callback(function (Message $msg) {
                return $msg->getContentType() === Message::CONTENT_TYPE_HTML
                    && $msg->getContent() === '<h1>HTML Content</h1>';
            }));

        $job = new SendMailJob($message);
        $job->handle();
    }

    #[Test]
    #[TestDox('Job handles message with text content type')]
    public function jobHandlesMessageWithTextContentType(): void
    {
        $message = new Message(
            'test@example.com',
            'Text Email',
            'Plain text content',
            Message::CONTENT_TYPE_TEXT
        );

        $this->transport->expects($this->once())
            ->method('send')
            ->with($this->callback(function (Message $msg) {
                return $msg->getContentType() === Message::CONTENT_TYPE_TEXT;
            }));

        $job = new SendMailJob($message);
        $job->handle();
    }

    #[Test]
    #[TestDox('Job handles message with multiple attachments')]
    public function jobHandlesMessageWithMultipleAttachments(): void
    {
        $message = new Message(
            'test@example.com',
            'Email with attachments',
            'Content',
            Message::CONTENT_TYPE_TEXT,
            ['/path/to/file1.pdf', '/path/to/file2.jpg', '/path/to/file3.doc']
        );

        $this->transport->expects($this->once())
            ->method('send')
            ->with($this->callback(function (Message $msg) {
                return count($msg->getAttachments()) === 3;
            }));

        $job = new SendMailJob($message);
        $job->handle();
    }

    #[Test]
    #[TestDox('Job executes without logger when not available')]
    public function jobExecutesWithoutLoggerWhenNotAvailable(): void
    {
        // Remove logger from container but keep transport
        $container = new Container();
        Container::setInstance($container);
        $container->set(TransportInterface::class, fn() => $this->transport);
        // No logger set

        $this->transport->expects($this->once())
            ->method('send')
            ->with($this->message);

        $job = new SendMailJob($this->message);
        $job->handle();
    }

    #[Test]
    #[TestDox('Job rethrows runtime exceptions from transport')]
    public function jobRethrowsRuntimeExceptionsFromTransport(): void
    {
        $this->transport->expects($this->once())
            ->method('send')
            ->willThrowException(new \RuntimeException('SMTP connection failed'));

        $job = new SendMailJob($this->message);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SMTP connection failed');

        $job->handle();
    }

    #[Test]
    #[TestDox('Job rethrows invalid argument exceptions from transport')]
    public function jobRethrowsInvalidArgumentExceptionsFromTransport(): void
    {
        $this->transport->expects($this->once())
            ->method('send')
            ->willThrowException(new \InvalidArgumentException('Invalid email format'));

        $job = new SendMailJob($this->message);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid email format');

        $job->handle();
    }
}
