<?php

declare(strict_types=1);

namespace MonkeysLegion\Mail\Queue;

interface JobInterface
{
    /**
     * Handle the job execution.
     *
     * @return void
     */
    public function handle(): void;

    /**
     * Get the job data.
     *
     * @return array
     */
    public function getData(): array;

    /**
     * Get the job identifier.
     *
     * @return string
     */
    public function getId(): string;

    /**
     * Get the number of attempts made.
     *
     * @return int
     */
    public function attempts(): int;

    /**
     * Mark the job as failed.
     *
     * @param \Exception $exception
     * @return void
     */
    public function fail(\Exception $exception): void;
}
