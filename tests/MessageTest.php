<?php

declare(strict_types=1);

namespace MonkeysLegion\Mailer\Tests;

use MonkeysLegion\Mail\Message;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(Message::class)]
#[AllowMockObjectsWithoutExpectations]
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

    #[Test]
    public function testGetFromEmailExtractsEmailFromFormat(): void
    {
        $message = new Message('test@example.com', 'Subject');
        $message->setFrom('John Doe <john@example.com>');

        $this->assertEquals('john@example.com', $message->getFromEmail());
    }

    #[Test]
    public function testGetFromEmailReturnsPlainEmail(): void
    {
        $message = new Message('test@example.com', 'Subject');
        $message->setFrom('john@example.com');

        $this->assertEquals('john@example.com', $message->getFromEmail());
    }

    #[Test]
    public function testGetFromNameExtractsNameFromFormat(): void
    {
        $message = new Message('test@example.com', 'Subject');
        $message->setFrom('John Doe <john@example.com>');

        $this->assertEquals('John Doe', $message->getFromName());
    }

    #[Test]
    public function testGetFromNameExtractsNameWithQuotes(): void
    {
        $message = new Message('test@example.com', 'Subject');
        $message->setFrom('"John Doe" <john@example.com>');

        $this->assertEquals('John Doe', $message->getFromName());
    }

    #[Test]
    public function testGetFromNameReturnsEmptyForPlainEmail(): void
    {
        $message = new Message('test@example.com', 'Subject');
        $message->setFrom('john@example.com');

        $this->assertEquals('', $message->getFromName());
    }

    #[Test]
    public function testGetTextBodyReturnsContentForTextType(): void
    {
        $message = new Message('test@example.com', 'Subject', 'Plain text content', Message::CONTENT_TYPE_TEXT);

        $this->assertEquals('Plain text content', $message->getTextBody());
    }

    #[Test]
    public function testGetTextBodyReturnsEmptyForHtmlType(): void
    {
        $message = new Message('test@example.com', 'Subject', '<h1>HTML</h1>', Message::CONTENT_TYPE_HTML);

        $this->assertEquals('', $message->getTextBody());
    }

    #[Test]
    public function testGetHtmlBodyReturnsContentForHtmlType(): void
    {
        $message = new Message('test@example.com', 'Subject', '<h1>HTML</h1>', Message::CONTENT_TYPE_HTML);

        $this->assertEquals('<h1>HTML</h1>', $message->getHtmlBody());
    }

    #[Test]
    public function testGetHtmlBodyReturnsEmptyForTextType(): void
    {
        $message = new Message('test@example.com', 'Subject', 'Plain text', Message::CONTENT_TYPE_TEXT);

        $this->assertEquals('', $message->getHtmlBody());
    }

    #[Test]
    public function testGetHeadersIncludesDkimSignature(): void
    {
        $message = new Message('test@example.com', 'Subject', 'Content');
        $message->setFrom('sender@example.com');
        $message->setDkimSignature('DKIM-Signature: v=1; a=rsa-sha256; d=example.com; s=selector; b=abc123');

        $headers = $message->getHeaders();

        $this->assertArrayHasKey('DKIM-Signature', $headers);
        $this->assertEquals('v=1; a=rsa-sha256; d=example.com; s=selector; b=abc123', $headers['DKIM-Signature']);
    }

    #[Test]
    public function testGetHeadersExcludesDkimSignatureWhenEmpty(): void
    {
        $message = new Message('test@example.com', 'Subject', 'Content');
        $message->setFrom('sender@example.com');

        $headers = $message->getHeaders();

        $this->assertArrayNotHasKey('DKIM-Signature', $headers);
    }

    #[Test]
    public function testGetHeadersExcludesDkimSignatureWhenNotInCorrectFormat(): void
    {
        $message = new Message('test@example.com', 'Subject', 'Content');
        $message->setFrom('sender@example.com');
        $message->setDkimSignature('Invalid signature format');

        $headers = $message->getHeaders();

        // Should not have DKIM-Signature if format is invalid
        $this->assertArrayNotHasKey('DKIM-Signature', $headers);
    }

    #[Test]
    public function testToStringWithAttachments(): void
    {
        $message = new Message('test@example.com', 'Subject', 'Content', Message::CONTENT_TYPE_TEXT, ['file.pdf']);
        $message->setFrom('sender@example.com');

        $string = $message->toString();

        $this->assertStringContainsString('--boundary', $string);
        $this->assertStringContainsString('multipart/mixed', $string);
        $this->assertStringContainsString('Content', $string);
    }

    #[Test]
    public function testToStringWithInvalidAttachment(): void
    {
        // Create message with an attachment that will trigger normalizeAttachment error
        $message = new Message('test@example.com', 'Subject', 'Content', Message::CONTENT_TYPE_TEXT, ['nonexistent.pdf']);
        $message->setFrom('sender@example.com');

        $string = $message->toString();

        // Should contain the error message instead of crashing
        $this->assertStringContainsString('[Attachment skipped:', $string);
    }

    #[Test]
    public function testToStringWithAttachmentsAsArray(): void
    {
        $attachment = [
            'path' => '/path/to/file.pdf',
            'name' => 'document.pdf',
            'mime' => 'application/pdf'
        ];
        
        $message = new Message('test@example.com', 'Subject', 'Content', Message::CONTENT_TYPE_TEXT, [$attachment]);
        $message->setFrom('sender@example.com');

        $string = $message->toString();

        $this->assertStringContainsString('--boundary', $string);
        $this->assertStringContainsString('multipart/mixed', $string);
    }

    #[Test]
    public function testEqualsChecksAllProperties(): void
    {
        $message1 = new Message('test@example.com', 'Subject', 'Content', Message::CONTENT_TYPE_HTML, ['file1.pdf']);
        $message2 = new Message('test@example.com', 'Subject', 'Content', Message::CONTENT_TYPE_HTML, ['file1.pdf']);

        $this->assertTrue($message1->equals($message2));
    }

    #[Test]
    public function testEqualsReturnsFalseWhenContentTypesDiffer(): void
    {
        $message1 = new Message('test@example.com', 'Subject', 'Content', Message::CONTENT_TYPE_TEXT);
        $message2 = new Message('test@example.com', 'Subject', 'Content', Message::CONTENT_TYPE_HTML);

        $this->assertFalse($message1->equals($message2));
    }

    #[Test]
    public function testEqualsReturnsFalseWhenAttachmentsDiffer(): void
    {
        $message1 = new Message('test@example.com', 'Subject', 'Content', Message::CONTENT_TYPE_TEXT, ['file1.pdf']);
        $message2 = new Message('test@example.com', 'Subject', 'Content', Message::CONTENT_TYPE_TEXT, ['file2.pdf']);

        $this->assertFalse($message1->equals($message2));
    }

    #[Test]
    public function testEqualsReturnsFalseWhenSubjectsDiffer(): void
    {
        $message1 = new Message('test@example.com', 'Subject 1', 'Content');
        $message2 = new Message('test@example.com', 'Subject 2', 'Content');

        $this->assertFalse($message1->equals($message2));
    }

    #[Test]
    public function testEqualsReturnsFalseWhenContentsDiffer(): void
    {
        $message1 = new Message('test@example.com', 'Subject', 'Content 1');
        $message2 = new Message('test@example.com', 'Subject', 'Content 2');

        $this->assertFalse($message1->equals($message2));
    }

    #[Test]
    public function testWithSubjectDoesNotModifyOriginal(): void
    {
        $original = new Message('test@example.com', 'Original Subject', 'Content');
        $modified = $original->withSubject('New Subject');

        // Verify independence
        $this->assertEquals('Original Subject', $original->getSubject());
        $this->assertEquals('New Subject', $modified->getSubject());
        $this->assertEquals('test@example.com', $modified->getTo());
        $this->assertEquals('Content', $modified->getContent());
    }

    #[Test]
    public function testWithContentTypeDoesNotModifyOriginal(): void
    {
        $original = new Message('test@example.com', 'Subject', 'Content', Message::CONTENT_TYPE_TEXT);
        $modified = $original->withContentType(Message::CONTENT_TYPE_HTML);

        // Verify independence
        $this->assertEquals(Message::CONTENT_TYPE_TEXT, $original->getContentType());
        $this->assertEquals(Message::CONTENT_TYPE_HTML, $modified->getContentType());
        $this->assertEquals('test@example.com', $modified->getTo());
        $this->assertEquals('Content', $modified->getContent());
    }

    #[Test]
    public function testToStringHandlesEmptyFrom(): void
    {
        $message = new Message('test@example.com', 'Subject', 'Content');
        // Don't set From

        $string = $message->toString();

        // Should still generate valid output even with empty From
        $this->assertStringContainsString('To: test@example.com', $string);
        $this->assertStringContainsString('Subject: Subject', $string);
    }

    #[Test]
    public function testMessageIdFormatIsValid(): void
    {
        $message = new Message('test@example.com', 'Subject');
        $messageId = $message->getMessageId();

        // Should start with <, end with >, contain @ and timestamp
        $this->assertMatchesRegularExpression('/^<[\w.]+@[\w.-]+>$/', $messageId);
    }

    #[Test]
    public function testDateFormatIsRfc2822(): void
    {
        $message = new Message('test@example.com', 'Subject');
        $date = $message->getDate();

        // Should be parseable as RFC 2822 date
        $timestamp = strtotime($date);
        $this->assertNotFalse($timestamp);
        $this->assertGreaterThan(0, $timestamp);
    }
}
