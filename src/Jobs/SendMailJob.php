<?php

declare(strict_types=1);

namespace MonkeysLegion\Mail\Jobs;

use MonkeysLegion\Core\Contracts\FrameworkLoggerInterface;
use MonkeysLegion\Mail\Message;
use MonkeysLegion\Mail\Service\ServiceContainer;
use MonkeysLegion\Mail\TransportInterface;

/**
 * Job class for sending emails asynchronously
 * This job will be processed by the queue worker
 */
class SendMailJob
{
    private FrameworkLoggerInterface $logger;
    private ServiceContainer $container;

    public function __construct(private Message $m)
    {
        $this->container = ServiceContainer::getInstance();
        /** @var FrameworkLoggerInterface $logger */
        $logger = $this->container->get(FrameworkLoggerInterface::class);
        $this->logger = $logger;
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
                $this->logger->critical("Mail configuration not found. Services may not be properly bootstrapped.", ['data' => $this->m]);
                throw new \RuntimeException("Mail configuration not found. Services may not be properly bootstrapped.");
            }

            /** @var TransportInterface $transport */
            $transport = $this->container->get(TransportInterface::class);
            $transport->send($this->m);
        } catch (\Exception $e) {
            $this->logger->error("SendMailJob failed: " . $e->getMessage(), [
                'content' => $this->m->getContent(),
                'to' => $this->m->getTo(),
                'subject' => $this->m->getSubject(),
                'attachments' => $this->m->getAttachments()
            ]);
            throw $e; // Re-throw to trigger job failure handling
        }
    }

    /**
     * Get job data for debugging/logging
     *
     * @return array{
     *   content: string,
     *   to: string,
     *   subject: string,
     *   attachments: array<string|array{path: string, name?: string|null, mime_type?: string|null}>
     * } Data associated with the job
     */
    public function getData(): array
    {
        return [
            'content' => $this->m->getContent(),
            'to' => $this->m->getTo(),
            'subject' => $this->m->getSubject(),
            'attachments' => $this->m->getAttachments()
        ];
    }
}
