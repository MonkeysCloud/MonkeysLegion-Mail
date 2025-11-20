<?php

declare(strict_types=1);

namespace MonkeysLegion\Mail\Template;

use MonkeysLegion\Logger\Contracts\MonkeysLoggerInterface;
use MonkeysLegion\Template\MLView;

class Renderer
{
    public function __construct(
        private MLView $mlView,
        private MonkeysLoggerInterface $logger
    ) {}

    /**
     * Render a template with the given data
     *
     * @param string $template Template name (e.g., 'emails.welcome')
     * @param array<string, mixed> $data Variables to pass to the template
     * @return string Rendered HTML content
     */
    public function render(string $template, array $data = []): string
    {
        try {
            $result = $this->mlView->render($template, $data);
            return $result;
        } catch (\Exception $e) {
            $this->logger->error("Template rendering failed", [
                'template' => $template,
                'exception' => $e,
                'error_message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw new \RuntimeException("Failed to render template '{$template}': " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Clear the template cache
     *
     * @return bool
     */
    public function clearCache(): bool
    {
        try {
            $this->mlView->clearCache();
            return true;
        } catch (\Exception $e) {
            $this->logger->error("Failed to clear template cache", [
                'exception' => $e,
                'error_message' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Get the template renderer instance for advanced usage
     *
     * @return MLView
     */
    public function getTemplateRenderer(): MLView
    {
        return $this->mlView;
    }
}
