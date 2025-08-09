<?php

namespace MonkeysLegion\Mailer\Tests\Queue;

use MonkeysLegion\Mail\Message;
use MonkeysLegion\Mail\Queue\RedisQueue;
use MonkeysLegion\Mail\Jobs\SendMailJob;
use PHPUnit\Framework\TestCase;
use Redis;

class RedisQueueTest extends TestCase
{
    private RedisQueue $queue;
    private Redis $redis;

    protected function setUp(): void
    {
        if (!extension_loaded('redis')) {
            $this->markTestSkipped('Redis extension not available');
        }

        try {
            $this->queue = new RedisQueue('127.0.0.1', 6379, 'test_queue', 'test:');
            $this->redis = $this->queue->getRedis();
            $this->redis->ping();
        } catch (\Exception $e) {
            $this->markTestSkipped('Redis server not available: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->redis)) {
            // Clean up test data
            $this->redis->del('test:test_queue');
            $this->redis->del('test:failed');
        }
    }

    public function testPushAddsJobToQueue(): void
    {
        $message = new Message('test@example.com', 'Subject', 'Body');

        $jobId = $this->queue->push(SendMailJob::class, $message);

        $this->assertIsString($jobId);
        $this->assertEquals(1, $this->queue->size());
    }

    public function testPopReturnsJobFromQueue(): void
    {
        $message = new Message('test@example.com', 'Subject', 'Body');
        $this->queue->push(SendMailJob::class, $message);

        $job = $this->queue->pop();

        $this->assertNotNull($job);
        $this->assertEquals(0, $this->queue->size());
    }

    public function testPopReturnsNullWhenQueueEmpty(): void
    {
        $job = $this->queue->pop();

        $this->assertNull($job);
    }

    public function testSizeReturnsCorrectCount(): void
    {
        $this->assertEquals(0, $this->queue->size());

        $message = new Message('test@example.com', 'Subject', 'Body');
        $this->queue->push(SendMailJob::class, $message);
        $this->queue->push(SendMailJob::class, $message);

        $this->assertEquals(2, $this->queue->size());
    }

    public function testClearRemovesAllJobs(): void
    {
        $message = new Message('test@example.com', 'Subject', 'Body');
        $this->queue->push(SendMailJob::class, $message);
        $this->queue->push(SendMailJob::class, $message);

        $this->assertTrue($this->queue->clear());
        $this->assertEquals(0, $this->queue->size());
    }

    public function testPushToFailedAddsToFailedQueue(): void
    {
        $jobData = [
            'id' => 'test_job_123',
            'job' => SendMailJob::class,
            'message' => serialize(new Message('test@example.com', 'Subject', 'Body')),
            'attempts' => 3
        ];
        $exception = new \Exception('Test failure');

        $result = $this->queue->pushToFailed($jobData, $exception);

        $this->assertTrue($result);
        $this->assertEquals(1, $this->queue->getFailedJobsCount());
    }

    public function testGetFailedJobsReturnsFailedJobs(): void
    {
        $jobData = [
            'id' => 'test_job_123',
            'job' => SendMailJob::class,
            'message' => serialize(new Message('test@example.com', 'Subject', 'Body')),
            'attempts' => 3
        ];
        $exception = new \Exception('Test failure');
        $this->queue->pushToFailed($jobData, $exception);

        $failedJobs = $this->queue->getFailedJobs();

        $this->assertCount(1, $failedJobs);
        $this->assertEquals('test_job_123', $failedJobs[0]['id']);
        $this->assertArrayHasKey('exception', $failedJobs[0]);
        if (isset($failedJobs[0]['exception'])) {
            $this->assertEquals('Test failure', $failedJobs[0]['exception']['message']);
        }
    }

    public function testRetryFailedJobMovesJobBackToQueue(): void
    {
        $message = new Message('test@example.com', 'Subject', 'Body');
        $jobData = [
            'id' => 'test_job_123',
            'job' => SendMailJob::class,
            'message' => serialize($message),
            'attempts' => 2
        ];
        $exception = new \Exception('Test failure');
        $this->queue->pushToFailed($jobData, $exception);

        $result = $this->queue->retryFailedJob('test_job_123');

        $this->assertTrue($result);
        $this->assertEquals(1, $this->queue->size());
        $this->assertEquals(0, $this->queue->getFailedJobsCount());
    }

    public function testRetryFailedJobReturnsFalseForNonExistentJob(): void
    {
        $result = $this->queue->retryFailedJob('non_existent_job');

        $this->assertFalse($result);
    }

    public function testClearFailedJobsRemovesAllFailedJobs(): void
    {
        $jobData = [
            'id' => 'test_job_123',
            'job' => SendMailJob::class,
            'message' => serialize(new Message('test@example.com', 'Subject', 'Body')),
            'attempts' => 3
        ];
        $exception = new \Exception('Test failure');
        $this->queue->pushToFailed($jobData, $exception);
        $this->queue->pushToFailed($jobData, $exception);

        $result = $this->queue->clearFailedJobs();

        $this->assertTrue($result);
        $this->assertEquals(0, $this->queue->getFailedJobsCount());
    }

    public function testGetDefaultQueueReturnsQueueName(): void
    {
        $this->assertEquals('test_queue', $this->queue->getDefaultQueue());
    }
}
