<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Workflow\V2\Support\WorkerSessionOptions;

final class WorkerSessionOptionsTest extends TestCase
{
    public function testSnapshotNormalizesWorkerSessionContractFields(): void
    {
        $options = new WorkerSessionOptions(
            sessionId: ' gpu-render ',
            connection: ' redis ',
            queue: ' gpu-activities ',
            requirements: [' gpu:nvidia-l4 ', 'gpu:nvidia-l4', 'fs:/mnt/models'],
            leaseSeconds: 120,
            ttlSeconds: 600,
            maxConcurrentActivities: 1,
            createIfMissing: false,
            allowReacquireAfterFailure: false,
        );

        $this->assertSame([
            'session_id' => 'gpu-render',
            'connection' => 'redis',
            'queue' => 'gpu-activities',
            'requirements' => ['gpu:nvidia-l4', 'fs:/mnt/models'],
            'lease_seconds' => 120,
            'ttl_seconds' => 600,
            'max_concurrent_activities' => 1,
            'create_if_missing' => false,
            'allow_reacquire_after_failure' => false,
        ], $options->toSnapshot());
    }

    public function testSessionIdMustBeSetBeforeSnapshotting(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('session id must be set');

        (new WorkerSessionOptions())->toSnapshot();
    }

    public function testLeaseFieldsMustBePositive(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('leaseSeconds must be a positive integer');

        new WorkerSessionOptions(sessionId: 'gpu-render', leaseSeconds: 0);
    }

    public function testRequirementsMustBeNonEmptyStrings(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requirement at index 0 must be a non-empty string');

        new WorkerSessionOptions(sessionId: 'gpu-render', requirements: [' ']);
    }
}
