<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Fiber;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Workflow\V2\Support\WorkflowExecution;
use Workflow\V2\Support\WorkflowExecutionProfile;

final class WorkflowExecutionProfileTest extends TestCase
{
    public function testSuspendedExecutionUsesTheCurrentCollectorAndRestoresTheDisabledState(): void
    {
        $first = new WorkflowExecutionProfile();
        $resumed = new WorkflowExecutionProfile();
        $closed = false;
        $execution = $first->collect(static function () use (&$closed): WorkflowExecution {
            return WorkflowExecution::startCallback(
                static function () use (&$closed): string {
                    $value = Fiber::suspend('first');
                    try {
                        Fiber::suspend($value);
                    } catch (RuntimeException $exception) {
                        return $exception->getMessage();
                    } finally {
                        $closed = true;
                    }
                    return 'unthrown';
                },
            );
        });
        $snapshot = $first->snapshot();
        $this->assertTrue($snapshot['balanced']);
        $this->assertSame(1, $snapshot['phases']['authoring_start']['calls']);
        $this->assertFalse($closed);
        $outside = hrtime(true);
        usleep(20_000); // Waiting outside the Fiber must not become authoring wall time.
        $outside = hrtime(true) - $outside;
        $this->assertSame('second', $resumed->collect(static fn () => $execution->send('second')));
        $this->assertNull($resumed->collect(static fn () => $execution->throw(new RuntimeException('caught'))));
        $this->assertSame('caught', $execution->getReturn());
        $this->assertTrue($closed);
        $this->assertSame($snapshot, $first->snapshot());
        $profile = $resumed->snapshot();
        $this->assertTrue($profile['balanced']);
        $this->assertSame(['authoring_resume', 'authoring_throw'], array_keys($profile['phases']));
        $this->assertGreaterThanOrEqual(20_000_000, $outside);
        WorkflowExecution::startCallback(static fn (): string => 'disabled');
        $this->assertSame($profile, $resumed->snapshot());
    }

    public function testNestedCpuAndWallBucketsReconcileWhenTheFiberThrows(): void
    {
        $profile = new WorkflowExecutionProfile();
        $error = new RuntimeException('body failure');
        try {
            $profile->collect(static function () use ($error): void {
                $sample = WorkflowExecutionProfile::begin('outer');
                try {
                    WorkflowExecution::startCallback(static function () use ($error): never {
                        throw $error;
                    });
                } finally {
                    WorkflowExecutionProfile::finish($sample);
                }
            });
            $this->fail('Expected the same body failure.');
        } catch (RuntimeException $caught) {
            $this->assertSame($error, $caught);
        }
        $snapshot = $profile->snapshot();
        $this->assertTrue($snapshot['balanced']);
        $outer = $snapshot['phases']['outer'];
        $body = $snapshot['phases']['authoring_start'];
        $this->assertSame($outer['cpu_us'], $outer['exclusive_cpu_us'] + $body['cpu_us']);
        $this->assertSame($outer['wall_ns'], $outer['exclusive_wall_ns'] + $body['wall_ns']);
        $this->assertSame($body['cpu_us'], $body['exclusive_cpu_us']);
        $this->assertSame($body['wall_ns'], $body['exclusive_wall_ns']);
    }
}
