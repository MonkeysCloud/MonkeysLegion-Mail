<?php

namespace MonkeysLegion\Mailer\Tests\Transport;

use MonkeysLegion\Mail\Transport\NullTransport;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class NullTransportTest extends TestCase
{
    public function testConstructor(): void
    {
        $transport = new NullTransport();

        $this->assertEquals('null', $transport->getName());
    }
}
