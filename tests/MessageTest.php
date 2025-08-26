<?php

namespace MonkeysLegion\Mailer\Tests;

use MonkeysLegion\Mail\Message;
use PHPUnit\Framework\TestCase;

class MessageTest extends TestCase
{
    public function testMessageConstructorSetsProperties(): void
    {
        $message = new Message(
            'test@example.com',
            'Test Subject',
            'Test Content',
            Message::CONTENT_TYPE_HTML,
            ['/path/to/file.pdf']
        );

        $this->assertEquals('test@example.com', $message->getTo());
        $this->assertEquals('Test Subject', $message->getSubject());
        $this->assertEquals('Test Content', $message->getContent());
        $this->assertEquals(Message::CONTENT_TYPE_HTML, $message->getContentType());
        $this->assertEquals(['/path/to/file.pdf'], $message->getAttachments());
    }

    public function testMessageDefaultValues(): void
    {
        $message = new Message('test@example.com', 'Subject');

        $this->assertEquals('', $message->getContent());
        $this->assertEquals(Message::CONTENT_TYPE_TEXT, $message->getContentType());
        $this->assertEquals([], $message->getAttachments());
    }

    public function testGetHeadersReturnsCorrectFormat(): void
    {
        $message = new Message('test@example.com', 'Test Subject', 'Content');
        $message->setFrom('sender@example.com');

        $headers = $message->getHeaders();

        $this->assertArrayHasKey('From', $headers);
        $this->assertArrayHasKey('To', $headers);
        $this->assertArrayHasKey('Subject', $headers);
        $this->assertArrayHasKey('Date', $headers);
        $this->assertArrayHasKey('Message-ID', $headers);
        $this->assertArrayHasKey('Content-Type', $headers);
        $this->assertArrayHasKey('MIME-Version', $headers);

        $this->assertEquals('sender@example.com', $headers['From']);
        $this->assertEquals('test@example.com', $headers['To']);
        $this->assertEquals('Test Subject', $headers['Subject']);
        $this->assertEquals('1.0', $headers['MIME-Version']);
        $this->assertStringContainsString('charset=UTF-8', $headers['Content-Type']);
    }

    public function testSetAndGetFrom(): void
    {
        $message = new Message('test@example.com', 'Subject');

        $message->setFrom('John Doe <john@example.com>');

        $this->assertEquals('John Doe <john@example.com>', $message->getFrom());
    }

    public function testSetAndGetDkimSignature(): void
    {
        $message = new Message('test@example.com', 'Subject');
        $dkimSignature = 'DKIM-Signature: v=1; a=rsa-sha256; d=example.com; s=default; b=...';

        $message->setDkimSignature($dkimSignature);

        $this->assertEquals($dkimSignature, $message->getDkimSignature());
    }

    public function testMessageIdIsGenerated(): void
    {
        $message = new Message('test@example.com', 'Subject');

        $messageId = $message->getMessageId();

        $this->assertStringStartsWith('<', $messageId);
        $this->assertStringEndsWith('>', $messageId);
        $this->assertStringContainsString('@', $messageId);
    }

    public function testDateIsGenerated(): void
    {
        $message = new Message('test@example.com', 'Subject');

        $date = $message->getDate();

        $this->assertNotEmpty($date);
        // Verify it's a valid RFC 2822 date
        $this->assertNotFalse(strtotime($date));
    }

    public function testWithSubjectCreatesNewInstance(): void
    {
        $original = new Message('test@example.com', 'Original Subject');
        $modified = $original->withSubject('New Subject');

        $this->assertNotSame($original, $modified);
        $this->assertEquals('Original Subject', $original->getSubject());
        $this->assertEquals('New Subject', $modified->getSubject());
    }

    public function testWithContentTypeCreatesNewInstance(): void
    {
        $original = new Message('test@example.com', 'Subject', 'Content', Message::CONTENT_TYPE_TEXT);
        $modified = $original->withContentType(Message::CONTENT_TYPE_HTML);

        $this->assertNotSame($original, $modified);
        $this->assertEquals(Message::CONTENT_TYPE_TEXT, $original->getContentType());
        $this->assertEquals(Message::CONTENT_TYPE_HTML, $modified->getContentType());
    }

    public function testEqualsReturnsTrueForIdenticalMessages(): void
    {
        $message1 = new Message('test@example.com', 'Subject', 'Content');
        $message2 = new Message('test@example.com', 'Subject', 'Content');

        $this->assertTrue($message1->equals($message2));
    }

    public function testEqualsReturnsFalseForDifferentMessages(): void
    {
        $message1 = new Message('test@example.com', 'Subject', 'Content');
        $message2 = new Message('other@example.com', 'Subject', 'Content');

        $this->assertFalse($message1->equals($message2));
    }

    public function testToStringReturnsFormattedMessage(): void
    {
        $message = new Message('test@example.com', 'Subject', 'Content');
        $message->setFrom('sender@example.com');

        $string = $message->toString();

        $this->assertStringContainsString('From: sender@example.com', $string);
        $this->assertStringContainsString('To: test@example.com', $string);
        $this->assertStringContainsString('Subject: Subject', $string);
        $this->assertStringContainsString('Content', $string);
        $this->assertStringContainsString("\r\n\r\n", $string); // Headers/body separator
    }

    public function testContentTypeConstants(): void
    {
        $this->assertEquals('text/plain', Message::CONTENT_TYPE_TEXT);
        $this->assertEquals('text/html', Message::CONTENT_TYPE_HTML);
        $this->assertEquals('multipart/mixed', Message::CONTENT_TYPE_MIXED);
        $this->assertEquals('multipart/alternative', Message::CONTENT_TYPE_ALTERNATIVE);
    }
}
