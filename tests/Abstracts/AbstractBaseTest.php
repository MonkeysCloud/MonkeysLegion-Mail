<?php

declare(strict_types=1);

namespace MonkeysLegion\Mailer\Tests\Abstracts;

use MonkeysLegion\DI\ContainerBuilder;
use MonkeysLegion\Logger\Logger\NullLogger;
use MonkeysLegion\Mail\Provider\MailServiceProvider;
use PHPUnit\Framework\TestCase;

abstract class AbstractBaseTest extends TestCase
{
    protected function setUp(): void
    {
        $this->bootstrapServices();
    }

    protected function bootstrapServices(): void
    {
        try {
            MailServiceProvider::setLogger(new NullLogger());
            MailServiceProvider::register(base_path(), new ContainerBuilder());
        } catch (\Exception $e) {
            throw new \RuntimeException("Failed to bootstrap mail services: " . $e->getMessage(), 0, $e);
        }
    }
}
