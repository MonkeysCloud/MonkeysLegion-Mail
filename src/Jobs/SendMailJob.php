<?php

declare(strict_types=1);

namespace MonkeysLegion\Mail\Jobs;

use MonkeysLegion\DI\Traits\ContainerAware;
use MonkeysLegion\Logger\Contracts\MonkeysLoggerInterface;
use MonkeysLegion\Mail\Message;
use MonkeysLegion\Mail\TransportInterface;
use MonkeysLegion\Queue\Contracts\DispatchableJobInterface;
use MonkeysLegion\Queue\Contracts\ShouldQueue;

/**
 * Job class for sending emails asynchronously
 * This job will be processed by the queue worker
 */
class SendMailJob implements DispatchableJobInterface, ShouldQueue
{
    use ContainerAware;

    private MonkeysLoggerInterface $logger;
    private TransportInterface $transport;

    public function __construct(private Message $m)
    {
        $this->logger = $this->resolve(MonkeysLoggerInterface::class);
        $this->transport = $this->resolve(TransportInterface::class);
    }

    /**
     * Handle the job execution
     * This method will be called by the worker
     */
    public function handle(): void
    {
        try {
            $this->transport->send($this->m);
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
     * @return array<string, mixed> Data associated with the job
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
