<?php

declare(strict_types=1);

namespace MonkeysLegion\Mailer\Tests\Abstracts;

use MonkeysLegion\Logger\Logger\NullLogger;
use PHPUnit\Framework\TestCase;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

#[AllowMockObjectsWithoutExpectations]
abstract class AbstractBaseTest extends TestCase
{
    protected function setUp(): void
    {
        $this->bootstrapServices();
    }

    protected function bootstrapServices(): void
    {
        try {
            $container = new \MonkeysLegion\DI\Container();
            \MonkeysLegion\DI\Container::setInstance($container);
            // Logger
            $logger = new NullLogger();
            $container->set(\MonkeysLegion\Logger\Contracts\MonkeysLoggerInterface::class, $logger);
            // Transport
            $transport = $this->createStub(\MonkeysLegion\Mail\TransportInterface::class);
            $container->set(\MonkeysLegion\Mail\TransportInterface::class, $transport);
            // Mailer
            $container->set(\MonkeysLegion\Mail\Mailer::class, $this->createStub(\MonkeysLegion\Mail\Mailer::class));
            
            // Renderer
            $container->set(\MonkeysLegion\Mail\Template\Renderer::class, $this->createStub(\MonkeysLegion\Mail\Template\Renderer::class));
            
            // RateLimiter
            $container->set(\MonkeysLegion\Mail\RateLimiter\RateLimiter::class, $this->createStub(\MonkeysLegion\Mail\RateLimiter\RateLimiter::class));
            
            // Queue Dispatcher
            $container->set(\MonkeysLegion\Queue\Contracts\QueueDispatcherInterface::class, $this->createStub(\MonkeysLegion\Queue\Contracts\QueueDispatcherInterface::class));

        } catch (\Exception $e) {
            throw new \RuntimeException("Failed to bootstrap mail services: " . $e->getMessage(), 0, $e);
        }
    }
}
