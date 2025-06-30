<?php

declare(strict_types=1);

namespace MonkeysLegion\Mail\Jobs;

use MonkeysLegion\Mail\Logger\Logger;
use MonkeysLegion\Mail\Mailer;
use MonkeysLegion\Mail\Service\ServiceContainer;

/**
 * Job class for sending emails asynchronously
 * This job will be processed by the queue worker
 */
class SendMailJob
{
    private Logger $logger;
    private ServiceContainer $container;

    public function __construct(private array $data)
    {
        $this->container = ServiceContainer::getInstance();
        $this->logger = $this->container->get(Logger::class);
    }

    /**
     * Handle the job execution
     * This method will be called by the worker
     */
    public function handle(): void
    {
        try {

            // Ensure mailer service is available
            if (!$this->container->getConfig('mail')) {
                $this->logger->log("Mail configuration not found. Services may not be properly bootstrapped.", ['data' => $this->data]);
                throw new \RuntimeException("Mail configuration not found. Services may not be properly bootstrapped.");
            }

            $mailer = $this->container->get(Mailer::class);

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
            $this->logger->log("SendMailJob failed: " . $e->getMessage(), $this->data);
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
