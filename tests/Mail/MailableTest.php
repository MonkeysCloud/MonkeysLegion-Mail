<?php

namespace MonkeysLegion\Mailer\Tests\Mail;

use MonkeysLegion\Mail\Mail\Mailable;
use MonkeysLegion\Mailer\Tests\Abstracts\AbstractBaseTest;

class MailableTest extends AbstractBaseTest
{
    public function testMailableCanSetAndGetProperties(): void
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

    public function testMailableContentTypeConfiguration(): void
    {
        $mailable = new TestMailable();

        $mailable->setContentType('text/plain');

        $this->assertEquals('text/plain', $mailable->getContentType());
    }

    public function testMailableAttachments(): void
    {
        $mailable = new TestMailable();

        $mailable->addAttachment('/path/to/file.pdf', 'document.pdf')
            ->addAttachment('/path/to/image.jpg');

        $attachments = $mailable->getAttachments();
        $this->assertCount(2, $attachments);

        if (is_array($attachments[0])) {
            $this->assertEquals('/path/to/file.pdf', $attachments[0]['path'] ?? '');
            $this->assertEquals('document.pdf', $attachments[0]['name'] ?? '');
        }
    }

    public function testMailableConfigureMethod(): void
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

    public function testMailableConditionalMethods(): void
    {
        $mailable = new TestMailable();

        $mailable->when(true, function (TestMailable $mail) {
            $mail->setSubject('Conditional Subject');
        });

        $mailable->unless(false, function (TestMailable $mail) {
            $mail->setTimeout(300);
        });

        $this->assertEquals('Conditional Subject', $mailable->getSubject());
        $this->assertEquals(300, $mailable->getTimeout());
    }

    public function testMailableTapMethod(): void
    {
        $mailable = new TestMailable();

        $mailable->tap(function (TestMailable $mail) {
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
        $this->view('emails.test')
            ->subject('Test Email');

        return $this;
    }
}
