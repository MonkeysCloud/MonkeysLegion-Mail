<?php

declare(strict_types=1);

namespace MonkeysLegion\Mail\Mail;

use MonkeysLegion\Core\Contracts\FrameworkLoggerInterface;
use MonkeysLegion\Mail\Mailer;
use MonkeysLegion\Mail\Template\Renderer;
use MonkeysLegion\Mail\Service\ServiceContainer;

/**
 * Base Mailable class for creating structured mail classes
 */
abstract class Mailable
{
    // =================================================================
    // PROPERTIES
    // =================================================================

    /** The view template to use for this mail */
    protected ?string $view = null;

    /** The subject of the email */
    protected ?string $subject = null;

    /** The recipient email address */
    protected ?string $to = null;

    /** Content type for the email */
    protected string $contentType = 'text/html';

    /** @var array<string|array{path: string, name?: string|null, mime_type?: string|null}> */
    protected array $attachments = [];

    /** Queue name for background processing */
    protected ?string $queue = null;

    /** Mail-specific timeout override (can be set as property in child classes) */
    protected ?int $timeout = null;

    /** Max retry attempts override (can be set as property in child classes) */
    protected ?int $maxTries = null;

    /** @var array<string, mixed> */
    protected array $viewData = [];

    /** Service container instance */
    private ServiceContainer $container;

    /** Logger instance */
    private FrameworkLoggerInterface $logger;

    // =================================================================
    // CONSTRUCTOR & ABSTRACT METHODS
    // =================================================================

    public function __construct()
    {
        $this->container = ServiceContainer::getInstance();
        /** @var FrameworkLoggerInterface $logger */
        $logger = $this->container->get(FrameworkLoggerInterface::class);
        $this->logger = $logger;
    }

    /**
     * Build the mail message
     * This method should be implemented by child classes
     */
    abstract public function build(): self;

    // =================================================================
    // MAIN ACTIONS
    // =================================================================

    /**
     * Send the mail immediately
     */
    public function send(): void
    {
        // Build the mail first
        $this->build();

        // Validate required fields
        $this->validate();

        try {
            $this->validateBeforeSend();

            /** @var Mailer $mailer */
            $mailer = $this->container->get(Mailer::class);

            // Render content if view is specified
            $content = $this->renderContent();

            $mailer->send(
                $this->to ?? '',
                $this->subject ?? '',
                $content,
                $this->contentType,
                $this->attachments
            );
        } catch (\Exception $e) {
            $this->logger->error("Mailable sending failed", [
                'class' => static::class,
                'to' => $this->to,
                'exception' => $e,
                'error_message' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Queue the mail for background processing
     */
    public function queue(): mixed
    {

        // Build the mail first
        $this->build();

        // Validate required fields
        $this->validate();

        try {
            $this->validateBeforeSend();

            /** @var Mailer $mailer */
            $mailer = $this->container->get(Mailer::class);

            // Render content if view is specified
            $content = $this->renderContent();

            $jobId = $mailer->queue(
                $this->to ?? '',
                $this->subject ?? '',
                $content,
                $this->contentType,
                $this->attachments,
                $this->queue
            );

            $this->logger->smartLog("Mailable queued successfully", [
                'class' => static::class,
                'job_id' => $jobId,
                'to' => $this->to
            ]);

            return $jobId;
        } catch (\Exception $e) {
            $this->logger->error("Mailable queueing failed", [
                'class' => static::class,
                'to' => $this->to,
                'exception' => $e,
                'error_message' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    // =================================================================
    // FLUENT BUILDER METHODS
    // =================================================================

    /**
     * Set the view template for this mail
     * @param string $view The view template name
     * @param array<string, mixed> $data The data to pass to the view
     * @return $this
     */
    protected function view(string $view, array $data = []): self
    {
        $this->view = $view;
        $this->viewData = array_merge($this->viewData, $data);
        return $this;
    }

    /**
     * Set the recipient
     */
    public function to(string $email): self
    {
        $this->to = $email;
        return $this;
    }

    /**
     * Set the subject
     */
    protected function subject(string $subject): self
    {
        $this->subject = $subject;
        return $this;
    }

    /**
     * Set the sender
     */
    protected function from(string $email, ?string $name = null): self
    {
        // Note: This would need to be implemented in the transport layer
        return $this;
    }

    /**
     * Add an attachment
     */
    protected function attach(string $path, ?string $name = null, ?string $mimeType = null): self
    {
        $this->attachments[] = [
            'path' => $path,
            'name' => $name,
            'mime_type' => $mimeType
        ];
        return $this;
    }

    /**
     * Set the queue for background processing
     */
    public function onQueue(string $queue): self
    {
        $this->queue = $queue;
        return $this;
    }

    /**
     * Set content type
     */
    protected function contentType(string $type): self
    {
        $this->contentType = $type;
        return $this;
    }

    /**
     * Add data to pass to the view
     */
    protected function with(string $key, mixed $value): self
    {
        $this->viewData[$key] = $value;
        return $this;
    }

    /**
     * Add multiple data items to pass to the view
     * @param array<string, mixed> $data
     */
    protected function withData(array $data): self
    {
        $this->viewData = array_merge($this->viewData, $data);
        return $this;
    }

    // =================================================================
    // RUNTIME SETTERS
    // =================================================================

    /**
     * Set the recipient dynamically at runtime
     */
    public function setTo(string $email): self
    {
        $this->to = $email;
        return $this;
    }

    /**
     * Set the subject dynamically at runtime
     */
    public function setSubject(string $subject): self
    {
        $this->subject = $subject;
        return $this;
    }

    /**
     * Set the view dynamically at runtime
     */
    public function setView(string $view): self
    {
        $this->view = $view;
        return $this;
    }

    /**
     * Set the queue dynamically at runtime
     */
    public function setQueue(string $queue): self
    {
        $this->queue = $queue;
        return $this;
    }

    /**
     * Add view data dynamically at runtime
     * @param array<string, mixed> $data
     * @return $this
     */
    public function setViewData(array $data): self
    {
        $this->viewData = $data;
        return $this;
    }

    /**
     * Merge view data dynamically at runtime
     * @param array<string, mixed> $data
     * @return $this
     */
    public function mergeViewData(array $data): self
    {
        $this->viewData = array_merge($this->viewData, $data);
        return $this;
    }

    // =================================================================
    // ADVANCED CONFIGURATION
    // =================================================================

    /**
     * Configure the mail with an array of properties at runtime
     * 
     * @param array{
     *     to?: string,
     *     subject?: string,
     *     view?: string,
     *     queue?: string,
     *     viewData?: array<string, mixed>,
     *     timeout?: int,
     *     maxTries?: int
     * } $config
     */
    public function configure(array $config): self
    {
        foreach ($config as $key => $value) {
            match ($key) {
                'to' => $this->setTo($value),
                'subject' => $this->setSubject($value),
                'view' => $this->setView($value),
                'queue' => $this->setQueue($value),
                'viewData' => $this->mergeViewData($value),
                'timeout' => $this->setTimeout($value),
                'maxTries' => $this->setMaxTries($value),
            };
        }
        return $this;
    }

    /**
     * Apply a callback to modify this mailable
     */
    public function tap(callable $callback): self
    {
        $callback($this);
        return $this;
    }

    /**
     * Conditionally apply a callback
     */
    public function when(bool $condition, callable $callback): self
    {
        if ($condition) {
            $callback($this);
        }
        return $this;
    }

    /**
     * Apply a callback when the condition is false
     */
    public function unless(bool $condition, callable $callback): self
    {
        if (!$condition) {
            $callback($this);
        }
        return $this;
    }

    // =================================================================
    // GETTERS
    // =================================================================

    /**
     * Get the recipient email
     */
    public function getTo(): ?string
    {
        return $this->to;
    }

    /**
     * Get the subject
     */
    public function getSubject(): ?string
    {
        return $this->subject;
    }

    /**
     * Get the view template
     */
    public function getView(): ?string
    {
        return $this->view;
    }

    /**
     * Get the view data
     * @return array<string, mixed>
     */
    public function getViewData(): array
    {
        return $this->viewData;
    }

    /**
     * Get timeout setting
     */
    public function getTimeout(): ?int
    {
        return $this->timeout;
    }

    /**
     * Get maximum retry attempts
     */
    public function getMaxTries(): ?int
    {
        return $this->maxTries;
    }

    /**
     * Get content type
     */
    public function getContentType(): string
    {
        return $this->contentType;
    }

    /**
     * Get all attachments
     * @return array<string|array{path: string, name?: string|null, mime_type?: string|null}>
     */
    public function getAttachments(): array
    {
        return $this->attachments;
    }

    // =================================================================
    // PRIVATE METHODS
    // =================================================================

    /**
     * Render the mail content
     */
    private function renderContent(): string
    {
        if ($this->view === null) {
            throw new \InvalidArgumentException("No view specified for mail class " . static::class);
        }

        try {
            /** @var Renderer $renderer */
            $renderer = $this->container->get(Renderer::class);

            $content = $renderer->render($this->view, $this->viewData);
            return $content;
        } catch (\Exception $e) {
            $this->logger->error("Failed to render mail content", [
                'class' => static::class,
                'view' => $this->view,
                'exception' => $e,
                'error_message' => $e->getMessage()
            ]);
            throw new \RuntimeException("Failed to render mail content: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Validate that all required fields are set
     */
    private function validate(): void
    {
        $errors = [];

        if (empty($this->to)) {
            $errors[] = "Recipient email address is required";
        }

        if (empty($this->subject)) {
            $errors[] = "Subject is required";
        }

        if (!filter_var($this->to, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Invalid recipient email address: '{$this->to}'";
        }

        if (!empty($errors)) {
            $this->logger->error("Mail validation failed", [
                'class' => static::class,
                'errors' => $errors
            ]);
            throw new \InvalidArgumentException(implode(', ', $errors));
        }
    }

    /**
     * Set the mail driver dynamically at runtime
     * @param string $driver The driver name (e.g., 'smtp', 'sendmail')
     * @param array<string, mixed> $config Optional configuration for the driver
     * @return $this
     * @throws \Exception If the driver cannot be set
     */
    public function setDriver(string $driver, array $config = []): self
    {
        $this->logger->smartLog("Setting mail driver from Mailable", [
            'class' => static::class,
            'driver' => $driver,
            'has_custom_config' => !empty($config)
        ]);

        try {
            /** @var Mailer $mailer */
            $mailer = $this->container->get(Mailer::class);
            $mailer->setDriver($driver, $config);

            $this->logger->smartLog("Mail driver set successfully from Mailable", [
                'class' => static::class,
                'driver' => $driver,
                'new_driver_class' => $mailer->getCurrentDriver()
            ]);
        } catch (\Exception $e) {
            $this->logger->error("Failed to set mail driver from Mailable", [
                'class' => static::class,
                'driver' => $driver,
                'exception' => $e,
                'error_message' => $e->getMessage()
            ]);
            throw $e;
        }

        return $this;
    }

    // =================================================================
    // PROPERTY CONFIGURATION METHODS
    // =================================================================

    /**
     * Set timeout for mail processing
     */
    public function setTimeout(int $timeout): self
    {
        $this->timeout = $timeout;
        return $this;
    }

    /**
     * Set maximum retry attempts
     */
    public function setMaxTries(int $maxTries): self
    {
        $this->maxTries = $maxTries;
        return $this;
    }

    /**
     * Set content type for this mail
     */
    public function setContentType(string $type): self
    {
        $this->contentType = $type;
        return $this;
    }

    /**
     * Add attachment to this mail
     */
    public function addAttachment(string $path, ?string $name = null, ?string $mimeType = null): self
    {
        $this->attachments[] = [
            'path' => $path,
            'name' => $name,
            'mime_type' => $mimeType
        ];
        return $this;
    }

    /**
     * Set all attachments
     * @param array<string|array{path: string, name?: string|null, mime_type?: string|null}> $attachments
     * @return $this
     */
    public function setAttachments(array $attachments): self
    {
        $this->attachments = $attachments;
        return $this;
    }

    private function validateBeforeSend(): void
    {
        if (!is_string($this->to) || empty($this->to) || !filter_var($this->to, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException("Valid recipient email address is required");
        }

        if (!is_string($this->subject)) {
            throw new \InvalidArgumentException("Subject must be a valid string");
        }
    }
}
