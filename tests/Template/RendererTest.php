<?php

declare(strict_types=1);

namespace MonkeysLegion\Mailer\Tests\Template;

use MonkeysLegion\Logger\Contracts\MonkeysLoggerInterface;
use MonkeysLegion\Mail\Template\Renderer;
use MonkeysLegion\Template\MLView;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(Renderer::class)]
#[AllowMockObjectsWithoutExpectations]
class RendererTest extends TestCase
{
    private MLView&MockObject $mlView;
    private MonkeysLoggerInterface&MockObject $logger;
    private Renderer $renderer;

    protected function setUp(): void
    {
        $this->mlView = $this->createMock(MLView::class);
        $this->logger = $this->createMock(MonkeysLoggerInterface::class);
        
        $this->renderer = new Renderer($this->mlView, $this->logger);
    }

    #[Test]
    #[TestDox('Successfully renders a template')]
    public function test_render_success(): void
    {
        $this->mlView->expects($this->once())
            ->method('render')
            ->with('emails.welcome', ['name' => 'John'])
            ->willReturn('<html>Welcome John</html>');

        $result = $this->renderer->render('emails.welcome', ['name' => 'John']);
        
        $this->assertSame('<html>Welcome John</html>', $result);
    }

    #[Test]
    #[TestDox('Throws RuntimeException on render failure and delegates to logger')]
    public function test_render_failure(): void
    {
        $exception = new \Exception('View not found');
        
        $this->mlView->expects($this->once())
            ->method('render')
            ->willThrowException($exception);
            
        $this->logger->expects($this->once())
            ->method('error')
            ->with("Template rendering failed", $this->callback(function ($context) use ($exception) {
                return $context['template'] === 'emails.error' &&
                       $context['exception'] === $exception &&
                       $context['error_message'] === 'View not found';
            }));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Failed to render template 'emails.error': View not found");
        
        $this->renderer->render('emails.error');
    }

    #[Test]
    #[TestDox('Successfully clears cache')]
    public function test_clear_cache_success(): void
    {
        $this->mlView->expects($this->once())
            ->method('clearCache');
            
        $result = $this->renderer->clearCache();
        
        $this->assertTrue($result);
    }

    #[Test]
    #[TestDox('Returns false and logs on clearCache exception')]
    public function test_clear_cache_failure(): void
    {
        $exception = new \Exception('Permission denied');
        
        $this->mlView->expects($this->once())
            ->method('clearCache')
            ->willThrowException($exception);
            
        $this->logger->expects($this->once())
            ->method('error')
            ->with("Failed to clear template cache", $this->callback(function ($context) use ($exception) {
                return $context['exception'] === $exception &&
                       $context['error_message'] === 'Permission denied';
            }));
            
        $result = $this->renderer->clearCache();
        
        $this->assertFalse($result);
    }

    #[Test]
    #[TestDox('Returns the underlying template renderer instance')]
    public function test_get_template_renderer(): void
    {
        $this->assertSame($this->mlView, $this->renderer->getTemplateRenderer());
    }
}
