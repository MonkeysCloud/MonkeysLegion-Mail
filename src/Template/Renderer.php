<?php

declare(strict_types=1);

namespace MonkeysLegion\Mail\Template;

use MonkeysLegion\Template\MLView;
use MonkeysLegion\Mail\Logger\Logger;
use MonkeysLegion\Mail\Service\ServiceContainer;

class Renderer
{
    private MLView $view;

    public function __construct(
        private string $viewsPath,
        private string $cachePath,
        private Logger $logger
    ) {
        $this->view = new MLView(
            viewsPath: $viewsPath,
            cacheDir: $cachePath
        );
    }

    /**
     * Render a template with the given data
     *
     * @param string $template Template name (e.g., 'emails.welcome')
     * @param array $data Variables to pass to the template
     * @return string Rendered HTML content
     */
    public function render(string $template, array $data = []): string
    {
        try {
            return $this->view->render($template, $data);
        } catch (\Exception $e) {
            $this->logger->log("Template rendering failed", [
                'template' => $template,
                'exception' => $e,
                'error_message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw new \RuntimeException("Failed to render template '{$template}': " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Check if a template exists
     *
     * @param string $template
     * @return bool
     */
    public function templateExists(string $template): bool
    {
        try {
            // Try to get the template path through MLView's loader
            // This is a simple check - MLView will handle the actual file resolution
            $viewsPath = $this->viewsPath;
            $templatePath = $viewsPath . '/' . str_replace('.', '/', $template) . '.ml.php';

            return file_exists($templatePath);
        } catch (\Exception $e) {
            $this->logger->log("Template existence check failed", [
                'template' => $template,
                'error' => $e->getMessage()
            ]);
            return false;
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
            $this->view->clearCache();

            return true;
        } catch (\Exception $e) {
            $this->logger->log("Failed to clear template cache", [
                'exception' => $e,
                'error_message' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Get the MLView instance for advanced usage
     *
     * @return MLView
     */
    public function getView(): MLView
    {
        return $this->view;
    }
}
