<?php

declare(strict_types=1);

namespace MonkeysLegion\Mailer\Tests\Mail;

use MonkeysLegion\DI\Container;
use MonkeysLegion\Logger\Contracts\MonkeysLoggerInterface;
use MonkeysLegion\Mail\Mail\Mailable;
use MonkeysLegion\Mail\Mailer;
use MonkeysLegion\Mail\Template\Renderer;
use MonkeysLegion\Mailer\Tests\Abstracts\AbstractBaseTest;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;

#[CoversClass(Mailable::class)]
#[AllowMockObjectsWithoutExpectations]
class MailableTest extends AbstractBaseTest
{
    private Mailer&MockObject $mailer;
    private Renderer&MockObject $renderer;
    private MonkeysLoggerInterface $logger;
    protected function setUp(): void
    {
        parent::setUp();
        $this->mailer = $this->createMock(Mailer::class);
        $this->renderer = $this->createMock(Renderer::class);
        $this->logger = $this->createStub(MonkeysLoggerInterface::class);

        // Replace the stubs created by AbstractBaseTest with our mocks
        $container = Container::instance();
        $container->set(Mailer::class, $this->mailer);
        $container->set(Renderer::class, $this->renderer);
        $container->set(MonkeysLoggerInterface::class, $this->logger);
    }

    public function test_constructor_throws_when_no_mailer(): void
    {
        $container = Container::instance();
        $container->set(Mailer::class, fn() => null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No mailer configured');
        new TestMailable();
    }

    #[Test]
    #[TestDox('Constructor throws when no Renderer is configured')]
    public function test_constructor_throws_when_no_renderer(): void
    {
        $container = Container::instance();
        $container->set(Renderer::class, fn() => null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No renderer configured');
        new TestMailable();
    }

    #[Test]
    #[TestDox('Setters and getters work properly')]
    public function test_setters_and_getters(): void
    {
        $mailable = new TestMailable();

        $mailable->setTo('test@example.com')
            ->setSubject('Test Subject')
            ->setView('emails.test')
            ->setTimeout(120)
            ->setMaxTries(5)
            ->setQueue('high_priority');

        $this->assertEquals('test@example.com', $mailable->getTo());
        $this->assertEquals('Test Subject', $mailable->getSubject());
        $this->assertEquals('emails.test', $mailable->getView());
        $this->assertEquals(120, $mailable->getTimeout());
        $this->assertEquals(5, $mailable->getMaxTries());

        // We can't access getQueue directly, but it should be set
        $mailable->setContentType('text/plain');
        $this->assertEquals('text/plain', $mailable->getContentType());

        $mailable->setAttachments([['path' => '/test']]);
        $this->assertEquals([['path' => '/test']], $mailable->getAttachments());
    }

    #[Test]
    #[TestDox('Fluent builder methods work properly')]
    public function test_fluent_builder_methods(): void
    {
        $mailable = new TestMailable();

        // Use the fluent test wrapper to access protected fluent methods
        $mailable->testView('emails.custom', ['name' => 'John']);
        $this->assertEquals('emails.custom', $mailable->getView());
        $this->assertEquals(['name' => 'John'], $mailable->getViewData());

        $mailable->testSubject('Custom Subject');
        $this->assertEquals('Custom Subject', $mailable->getSubject());

        $mailable->testFrom('from@example.com'); // Not implemented but should return self

        $mailable->testAttach('/path/here', 'name.txt', 'text/plain');
        $attachments = $mailable->getAttachments();
        $this->assertCount(1, $attachments);
        $this->assertEquals('/path/here', $attachments[0]['path']);

        $mailable->onQueue('low');

        $mailable->testContentType('application/json');
        $this->assertEquals('application/json', $mailable->getContentType());

        $mailable->testWith('age', 30);
        $this->assertEquals('John', $mailable->getViewData()['name']);
        $this->assertEquals(30, $mailable->getViewData()['age']);

        $mailable->testWithData(['city' => 'NY']);
        $this->assertEquals('NY', $mailable->getViewData()['city']);
    }

    #[Test]
    #[TestDox('Runtime view data updates work')]
    public function test_runtime_view_data(): void
    {
        $mailable = new TestMailable();

        $mailable->setViewData(['a' => 1]);
        $this->assertEquals(['a' => 1], $mailable->getViewData());

        $mailable->mergeViewData(['b' => 2]);
        $this->assertEquals(['a' => 1, 'b' => 2], $mailable->getViewData());
    }

    #[Test]
    #[TestDox('Configure method applies array configuration successfully')]
    public function test_configure_method(): void
    {
        $mailable = new TestMailable();

        $mailable->configure([
            'to' => 'config@example.com',
            'subject' => 'Config Subject',
            'view' => 'emails.config',
            'queue' => 'main',
            'viewData' => ['key' => 'value'],
            'timeout' => 45,
            'maxTries' => 3,
            'unknown' => 'ignore'
        ]);

        $this->assertEquals('config@example.com', $mailable->getTo());
        $this->assertEquals('Config Subject', $mailable->getSubject());
        $this->assertEquals('emails.config', $mailable->getView());
        $this->assertEquals(['key' => 'value'], $mailable->getViewData());
        $this->assertEquals(45, $mailable->getTimeout());
        $this->assertEquals(3, $mailable->getMaxTries());
    }

    #[Test]
    #[TestDox('Configure throws error on wrong types')]
    public function test_configure_throws_on_wrong_types(): void
    {
        $mailable = new TestMailable();

        $this->expectException(\InvalidArgumentException::class);
        $mailable->configure(['to' => 123]);
    }

    #[Test]
    #[TestDox('Tap applies callback')]
    public function test_tap(): void
    {
        $mailable = new TestMailable();
        $mailable->tap(function ($m) {
            $m->setTo('tap@example.com');
        });

        $this->assertEquals('tap@example.com', $mailable->getTo());
    }

    #[Test]
    #[TestDox('When applies conditionally')]
    public function test_when(): void
    {
        $mailable = new TestMailable();
        $mailable->when(true, function ($m) {
            $m->setSubject('True');
        });
        $mailable->when(false, function ($m) {
            $m->setSubject('False');
        });

        $this->assertEquals('True', $mailable->getSubject());
    }

    #[Test]
    #[TestDox('Unless applies conditionally')]
    public function test_unless(): void
    {
        $mailable = new TestMailable();
        $mailable->unless(false, function ($m) {
            $m->setSubject('True');
        });
        $mailable->unless(true, function ($m) {
            $m->setSubject('False');
        });

        $this->assertEquals('True', $mailable->getSubject());
    }

    #[Test]
    #[TestDox('Validation requires to and subject')]
    public function test_validation_requires_to_and_subject(): void
    {
        $mailable = new TestMailable();
        // Just build, validate should throw exception since to and subject are empty

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Recipient email address is required');
        $mailable->send();
    }

    #[Test]
    #[TestDox('Validation requires valid email')]
    public function test_validation_requires_valid_email(): void
    {
        $mailable = new TestMailable();
        $mailable->setTo('invalid-email')->setSubject('A');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid recipient email address');
        $mailable->send();
    }

    #[Test]
    #[TestDox('Render Content throws if no view')]
    public function test_render_content_throws_without_view(): void
    {
        $mailable = new class extends Mailable {
            public function build(): self
            {
                $this->setTo('a@b.com')->setSubject('a');
                return $this;
            }
        };

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('No view specified');
        $mailable->send();
    }

    #[Test]
    #[TestDox('send() sets mailable context on the mailer')]
    public function test_send_sets_mailable_context(): void
    {
        $mailable = new TestMailable();
        $mailable->setTo('test@example.com');
        $this->renderer->method('render')->willReturn('content');

        // Expect context to be set to TestMailable::class, then null
        $this->mailer->expects($this->exactly(2))
            ->method('setMailableContext')
            ->with($this->logicalOr($this->equalTo(TestMailable::class), $this->isNull()));

        $mailable->send();
    }

    #[Test]
    #[TestDox('queue() sets mailable context on the mailer')]
    public function test_queue_sets_mailable_context(): void
    {
        $mailable = new TestMailable();
        $mailable->setTo('test@example.com');
        $this->renderer->method('render')->willReturn('content');

        // Expect context to be set to TestMailable::class, then null
        $this->mailer->expects($this->exactly(2))
            ->method('setMailableContext')
            ->with($this->logicalOr($this->equalTo(TestMailable::class), $this->isNull()));

        $mailable->queue();
    }

    #[Test]
    #[TestDox('Send validates, renders and delegates to Mailer')]
    public function test_send_success(): void
    {
        $mailable = new TestMailable();
        $mailable->setTo('a@b.com'); // Subject is set in build()

        $this->renderer->expects($this->once())
            ->method('render')
            ->with('emails.test', [])
            ->willReturn('<html/>');

        $this->mailer->expects($this->once())
            ->method('send')
            ->with('a@b.com', 'Test Email', '<html/>', 'text/html', []);

        $mailable->send();
    }

    #[Test]
    #[TestDox('Send throws exception if mailer component fails')]
    public function test_send_handles_exception(): void
    {
        $mailable = new TestMailable();
        $mailable->setTo('a@b.com');

        $this->renderer->expects($this->once())
            ->method('render')
            ->willThrowException(new \RuntimeException("Render error"));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to render mail content: Render error');
        $mailable->send();
    }

    #[Test]
    #[TestDox('Queue validates, renders and delegates to Mailer queue')]
    public function test_queue_success(): void
    {
        $mailable = new TestMailable();
        $mailable->setTo('a@b.com')->setQueue('my_queue');

        $this->renderer->expects($this->once())
            ->method('render')
            ->willReturn('<html/>');

        $this->mailer->expects($this->once())
            ->method('queue')
            ->with('a@b.com', 'Test Email', '<html/>', 'text/html', [], 'my_queue')
            ->willReturn('job-123');

        $result = $mailable->queue();
        $this->assertEquals('job-123', $result);
    }

    #[Test]
    #[TestDox('Queue handles exception')]
    public function test_queue_handles_exception(): void
    {
        $mailable = new TestMailable();
        $mailable->setTo('a@b.com');

        $this->renderer->expects($this->once())
            ->method('render')
            ->willReturn('<html/>');

        $this->mailer->expects($this->once())
            ->method('queue')
            ->willThrowException(new \RuntimeException("Queue failed"));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Queue failed');
        $mailable->queue();
    }

    #[Test]
    #[TestDox('SetDriver delegates to mailer')]
    public function test_set_driver_delegates(): void
    {
        $mailable = new TestMailable();

        $this->mailer->expects($this->once())
            ->method('setDriver')
            ->with('mailgun', ['key' => 'auth']);

        $mailable->setDriver('mailgun', ['key' => 'auth']);
    }

    #[Test]
    #[TestDox('SetDriver handles exception')]
    public function test_set_driver_exception(): void
    {
        $mailable = new TestMailable();

        $this->mailer->expects($this->once())
            ->method('setDriver')
            ->willThrowException(new \InvalidArgumentException("Driver bad"));

        $this->expectException(\InvalidArgumentException::class);
        $mailable->setDriver('bad');
    }
}

class TestMailable extends Mailable
{
    public function build(): self
    {
        $this->setView('emails.test')->setSubject('Test Email');
        return $this;
    }

    // Wrappers for protected methods to test them fluently
    public function testView(string $view, array $data = []): self
    {
        return $this->view($view, $data);
    }

    public function testSubject(string $subject): self
    {
        return $this->subject($subject);
    }

    public function testFrom(string $email, ?string $name = null): self
    {
        return $this->from($email, $name);
    }

    public function testAttach(string $path, ?string $name = null, ?string $mimeType = null): self
    {
        return $this->attach($path, $name, $mimeType);
    }

    public function testContentType(string $type): self
    {
        return $this->contentType($type);
    }

    public function testWith(string $key, mixed $value): self
    {
        return $this->with($key, $value);
    }

    public function testWithData(array $data): self
    {
        return $this->withData($data);
    }
}
