<?php

declare(strict_types=1);

namespace MonkeysLegion\Mailer\Tests\Abstracts;

use MonkeysLegion\Core\Logger\MonkeyLogger;
use MonkeysLegion\DI\ContainerBuilder;
use MonkeysLegion\Mail\Provider\MailServiceProvider;
use PHPUnit\Framework\TestCase;

class AbstractBaseTest extends TestCase
{
    protected function setUp(): void
    {
        $this->bootstrapServices();
    }

    protected function bootstrapServices(): void
    {
        try {
            MailServiceProvider::setLogger(new MonkeyLogger());
            MailServiceProvider::register(new ContainerBuilder());
        } catch (\Exception $e) {
            throw new \RuntimeException("Failed to bootstrap mail services: " . $e->getMessage(), 0, $e);
        }
    }
}
