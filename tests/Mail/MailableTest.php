<?php

namespace MonkeysLegion\Mailer\Tests\Mail;

use MonkeysLegion\DI\ContainerBuilder;
use MonkeysLegion\Mail\Mail\Mailable;
use MonkeysLegion\Mail\Provider\MailServiceProvider;
use PHPUnit\Framework\TestCase;

class MailableTest extends TestCase
{
    protected function setUp(): void
    {
        $this->bootstrapServices();
    }

    private function bootstrapServices(): void
    {
        try {
            MailServiceProvider::register(new ContainerBuilder());
        } catch (\Exception $e) {
            throw new \RuntimeException("Failed to bootstrap mail services: " . $e->getMessage(), 0, $e);
        }
    }

    public function testMailableCanSetAndGetProperties()
    {
        $mailable = new TestMailable();

        $mailable->setTo('test@example.com')
            ->setSubject('Test Subject')
            ->setView('emails.test')
            ->setTimeout(120)
            ->setMaxTries(5);

        $this->assertEquals('test@example.com', $mailable->getTo());
        $this->assertEquals('Test Subject', $mailable->getSubject());
        $this->assertEquals('emails.test', $mailable->getView());
        $this->assertEquals(120, $mailable->getTimeout());
        $this->assertEquals(5, $mailable->getMaxTries());
    }

    public function testMailableContentTypeConfiguration()
    {
        $mailable = new TestMailable();

        $mailable->setContentType('text/plain');

        $this->assertEquals('text/plain', $mailable->getContentType());
    }

    public function testMailableAttachments()
    {
        $mailable = new TestMailable();

        $mailable->addAttachment('/path/to/file.pdf', 'document.pdf')
            ->addAttachment('/path/to/image.jpg');

        $attachments = $mailable->getAttachments();
        $this->assertCount(2, $attachments);
        $this->assertEquals('/path/to/file.pdf', $attachments[0]['path']);
        $this->assertEquals('document.pdf', $attachments[0]['name']);
    }

    public function testMailableInlineImages()
    {
        $mailable = new TestMailable();

        $mailable->addInlineImage('/path/to/logo.png', 'logo')
            ->addInlineImage('/path/to/banner.jpg', 'banner');

        $images = $mailable->getInlineImages();
        $this->assertCount(2, $images);
        $this->assertEquals('/path/to/logo.png', $images[0]['path']);
        $this->assertEquals('logo', $images[0]['cid']);
    }

    public function testMailableConfigureMethod()
    {
        $mailable = new TestMailable();

        $config = [
            'to' => 'configured@example.com',
            'subject' => 'Configured Subject',
            'timeout' => 180,
            'maxTries' => 10
        ];

        $mailable->configure($config);

        $this->assertEquals('configured@example.com', $mailable->getTo());
        $this->assertEquals('Configured Subject', $mailable->getSubject());
        $this->assertEquals(180, $mailable->getTimeout());
        $this->assertEquals(10, $mailable->getMaxTries());
    }

    public function testMailableConditionalMethods()
    {
        $mailable = new TestMailable();

        $mailable->when(true, function ($mail) {
            $mail->setSubject('Conditional Subject');
        });

        $mailable->unless(false, function ($mail) {
            $mail->setTimeout(300);
        });

        $this->assertEquals('Conditional Subject', $mailable->getSubject());
        $this->assertEquals(300, $mailable->getTimeout());
    }

    public function testMailableTapMethod()
    {
        $mailable = new TestMailable();

        $mailable->tap(function ($mail) {
            $mail->setSubject('Tapped Subject')
                ->setTimeout(150);
        });

        $this->assertEquals('Tapped Subject', $mailable->getSubject());
        $this->assertEquals(150, $mailable->getTimeout());
    }
}

// Test helper class
class TestMailable extends Mailable
{
    public function build(): self
    {
        return $this->view('emails.test')
            ->subject('Test Email');
    }
}
