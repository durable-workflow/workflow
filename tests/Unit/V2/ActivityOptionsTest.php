<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\TestCase;
use Workflow\V2\Support\ActivityOptions;
use Workflow\V2\Support\WorkerSessionOptions;

final class ActivityOptionsTest extends TestCase
{
    public function testSchedulingBuildersAreImmutableAndPreserveActivityOverrides(): void
    {
        $session = new WorkerSessionOptions(
            sessionId: 'gpu-render',
            connection: 'redis',
            queue: 'gpu',
            requirements: ['gpu:nvidia-l4'],
            leaseSeconds: 120,
            ttlSeconds: 600,
            maxConcurrentActivities: 1,
        );
        $original = new ActivityOptions(
            connection: 'sqs',
            queue: 'images',
            maxAttempts: 3,
            backoff: [1, 5],
            startToCloseTimeout: 300,
            scheduleToStartTimeout: 30,
            scheduleToCloseTimeout: 600,
            heartbeatTimeout: 20,
            nonRetryableErrorTypes: ['InvalidImage'],
            workerSession: $session,
        );

        $prioritized = $original->withPriority(2);
        $rebalanced = $prioritized->withFairness('Tenant-A', 4);

        $this->assertFalse($original->hasSchedulingOverrides());
        $this->assertTrue($prioritized->hasSchedulingOverrides());
        $this->assertTrue($rebalanced->hasSchedulingOverrides());
        $this->assertNull($original->priority);
        $this->assertSame(2, $prioritized->priority);
        $this->assertSame('tenant-a', $rebalanced->fairnessKey);
        $this->assertSame(4, $rebalanced->fairnessWeight);
        $this->assertNotSame($original, $prioritized);
        $this->assertNotSame($prioritized, $rebalanced);
        $this->assertSame([
            'connection' => 'sqs',
            'queue' => 'images',
            'max_attempts' => 3,
            'backoff' => [1, 5],
            'start_to_close_timeout' => 300,
            'schedule_to_start_timeout' => 30,
            'schedule_to_close_timeout' => 600,
            'heartbeat_timeout' => 20,
            'non_retryable_error_types' => ['InvalidImage'],
            'worker_session' => $session->toSnapshot(),
            'priority' => 2,
            'fairness_key' => 'tenant-a',
            'fairness_weight' => 4,
        ], $rebalanced->toSnapshot());
    }

    public function testSchedulingBuildersCanClearAllOverrides(): void
    {
        $options = (new ActivityOptions(priority: 2, fairnessKey: 'tenant-a', fairnessWeight: 4))
            ->withPriority(null)
            ->withFairness(null);

        $this->assertNull($options->priority);
        $this->assertNull($options->fairnessKey);
        $this->assertNull($options->fairnessWeight);
        $this->assertFalse($options->hasSchedulingOverrides());
    }
}
