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

    private ?MonkeysLoggerInterface $logger;
    private TransportInterface $transport;

    public function __construct(private Message $m)
    {
        /** @var MonkeysLoggerInterface|null */
        $logger = $this->has(MonkeysLoggerInterface::class) ? $this->resolve(MonkeysLoggerInterface::class) : null;
        $this->logger = $logger;

        /** @var TransportInterface|null */
        $transport = $this->has(TransportInterface::class) ? $this->resolve(TransportInterface::class) : null;
        if (!$transport) {
            $this->logger?->error("No mail transport configured. SendMailJob cannot be processed.");
            throw new \RuntimeException("No mail transport configured.");
        }
        $this->transport = $transport;
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
            $this->logger?->error("SendMailJob failed: " . $e->getMessage(), [
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
