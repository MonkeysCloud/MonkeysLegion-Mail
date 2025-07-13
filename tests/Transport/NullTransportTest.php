<?php

namespace MonkeysLegion\Mailer\Tests\Transport;

use MonkeysLegion\Mail\Logger\Logger;
use MonkeysLegion\Mail\Message;
use MonkeysLegion\Mail\Transport\NullTransport;
use PHPUnit\Framework\TestCase;

class NullTransportTest extends TestCase
{
    public function testSendDoesNotThrowException()
    {
        $transport = new NullTransport(new Logger());
        $message = new Message('test@example.com', 'Subject', 'Body');

        // Should not throw any exception
        $transport->send($message);

        $this->assertTrue(true);
    }

    public function testGetNameReturnsNull()
    {
        $transport = new NullTransport(new Logger());

        $this->assertEquals('null', $transport->getName());
    }

    public function testSendWithComplexMessage()
    {
        $transport = new NullTransport(new Logger());
        $message = new Message(
            'test@example.com',
            'Complex Subject',
            'Complex Body',
            Message::CONTENT_TYPE_HTML,
            ['/path/to/file.pdf'],
            ['/path/to/image.png']
        );

        // Should handle complex messages without issue
        $transport->send($message);

        $this->assertTrue(true);
    }
}
