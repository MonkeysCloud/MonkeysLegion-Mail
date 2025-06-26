<?php

declare(strict_types=1);

namespace MonkeysLegion\Mail\Jobs;

use MonkeysLegion\Mail\Mailer;
use MonkeysLegion\Mail\Service\ServiceContainer;

/**
 * Job class for sending emails asynchronously
 * This job will be processed by the queue worker
 */
class SendMailJob
{
    private array $data;

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    /**
     * Handle the job execution
     * This method will be called by the worker
     */
    public function handle(): void
    {
        try {
            $container = ServiceContainer::getInstance();

            // Ensure mailer service is available
            if (!$container->getConfig('mail')) {
                throw new \RuntimeException("Mail configuration not found. Services may not be properly bootstrapped.");
            }

            $mailer = $container->get(Mailer::class);

            // Send the email using the mailer
            $mailer->send(
                $this->data['to'],
                $this->data['subject'],
                $this->data['content'],
                $this->data['contentType'] ?? 'text/html',
                $this->data['attachments'] ?? [],
                $this->data['inlineImages'] ?? []
            );
        } catch (\Exception $e) {
            error_log("SendMailJob failed: " . $e->getMessage());
            error_log("Job data: " . json_encode($this->data));
            throw $e; // Re-throw to trigger job failure handling
        }
    }

    /**
     * Get job data for debugging/logging
     */
    public function getData(): array
    {
        return $this->data;
    }
}
