<?php

declare(strict_types=1);

namespace Tests\Unit\Traits;

use Carbon\CarbonInterval;
use Exception;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Tests\Fixtures\TestChildWorkflow;
use Tests\Fixtures\TestWorkflow;
use Tests\TestCase;
use Workflow\Models\StoredWorkflow;
use Workflow\Serializers\Serializer;
use Workflow\States\WorkflowPendingStatus;
use Workflow\Timer;
use Workflow\WorkflowStub;

final class TimersTest extends TestCase
{
    public function testResolvesZeroSeconds(): void
    {
        $workflow = WorkflowStub::load(WorkflowStub::make(TestWorkflow::class)->id());

        WorkflowStub::timer(0)
            ->then(static function ($value) use (&$result) {
                $result = $value;
            });

        $this->assertTrue($result);
        $this->assertSame(0, $workflow->logs()->count());
    }

    public function testCreatesTimer(): void
    {
        $workflow = WorkflowStub::load(WorkflowStub::make(TestWorkflow::class)->id());
        $storedWorkflow = StoredWorkflow::findOrFail($workflow->id());
        $storedWorkflow->update([
            'arguments' => Serializer::serialize([]),
            'status' => WorkflowPendingStatus::$name,
        ]);

        WorkflowStub::timer('1 minute')
            ->then(static function ($value) use (&$result) {
                $result = $value;
            });

        $this->assertNull($result);
        $this->assertSame(0, $workflow->logs()->count());
        $this->assertDatabaseHas('workflow_timers', [
            'stored_workflow_id' => $workflow->id(),
            'index' => 0,
            'stop_at' => WorkflowStub::now()->addMinute(),
        ]);
    }

    public function testDefersIfNotElapsed(): void
    {
        $workflow = WorkflowStub::load(WorkflowStub::make(TestWorkflow::class)->id());
        $storedWorkflow = StoredWorkflow::findOrFail($workflow->id());
        $storedWorkflow->update([
            'arguments' => Serializer::serialize([]),
            'status' => WorkflowPendingStatus::$name,
        ]);
        $storedWorkflow->timers()
            ->create([
                'index' => 0,
                'stop_at' => now()
                    ->addHour(),
            ]);

        WorkflowStub::timer(60)
            ->then(static function ($value) use (&$result) {
                $result = $value;
            });

        $this->assertNull($result);
        $this->assertSame(0, $workflow->logs()->count());

        WorkflowStub::awaitWithTimeout('1 minute', static fn () => false)
            ->then(static function ($value) use (&$result) {
                $result = $value;
            });

        $this->assertNull($result);
        $this->assertSame(0, $workflow->logs()->count());
    }

    public function testStoresResult(): void
    {
        $workflow = WorkflowStub::load(WorkflowStub::make(TestWorkflow::class)->id());
        $storedWorkflow = StoredWorkflow::findOrFail($workflow->id());
        $storedWorkflow->timers()
            ->create([
                'index' => 0,
                'stop_at' => now(),
            ]);

        WorkflowStub::timer('1 minute')
            ->then(static function ($value) use (&$result) {
                $result = $value;
            });

        $this->assertSame(true, $result);
        $this->assertSame(1, $workflow->logs()->count());
        $this->assertDatabaseHas('workflow_logs', [
            'stored_workflow_id' => $workflow->id(),
            'index' => 0,
            'class' => Timer::class,
        ]);
        $this->assertSame(true, Serializer::unserialize($workflow->logs()->firstWhere('index', 0)->result));
    }

    public function testLoadsStoredResult(): void
    {
        $workflow = WorkflowStub::load(WorkflowStub::make(TestWorkflow::class)->id());
        $storedWorkflow = StoredWorkflow::findOrFail($workflow->id());
        $storedWorkflow->timers()
            ->create([
                'index' => 0,
                'stop_at' => now(),
            ]);
        $storedWorkflow->logs()
            ->create([
                'index' => 0,
                'now' => now(),
                'class' => Timer::class,
                'result' => Serializer::serialize(true),
            ]);

        WorkflowStub::awaitWithTimeout('1 minute', static fn () => true)
            ->then(static function ($value) use (&$result) {
                $result = $value;
            });

        $this->assertSame(false, $result);
        $this->assertSame(1, $workflow->logs()->count());
        $this->assertDatabaseHas('workflow_logs', [
            'stored_workflow_id' => $workflow->id(),
            'index' => 0,
            'class' => Timer::class,
        ]);
        $this->assertSame(true, Serializer::unserialize($workflow->logs()->firstWhere('index', 0)->result));
    }

    public function testHandlesDuplicateLogInsertionProperly(): void
    {
        $workflow = WorkflowStub::load(WorkflowStub::make(TestWorkflow::class)->id());
        $storedWorkflow = StoredWorkflow::findOrFail($workflow->id());
        $storedWorkflow->timers()
            ->create([
                'index' => 0,
                'stop_at' => now(),
            ]);
        $storedWorkflow->logs()
            ->create([
                'index' => 0,
                'now' => now(),
                'class' => Timer::class,
                'result' => Serializer::serialize(true),
            ]);

        $mockStoredWorkflow = Mockery::spy($storedWorkflow);
        $mockStoredWorkflow->shouldReceive('findLogByIndex')
            ->once()
            ->with(0)
            ->andReturn(null);
        $mockStoredWorkflow->shouldReceive('createLog')
            ->once()
            ->andThrow(new UniqueConstraintViolationException('', '', [], new Exception()));

        WorkflowStub::setContext([
            'storedWorkflow' => $mockStoredWorkflow,
            'index' => 0,
            'now' => now(),
            'replaying' => false,
        ]);

        WorkflowStub::timer('1 minute')
            ->then(static function ($value) use (&$result) {
                $result = $value;
            });

        Mockery::close();

        $this->assertSame(true, $result);
    }

    public function testTimerWithCarbonInterval(): void
    {
        $workflow = WorkflowStub::load(WorkflowStub::make(TestWorkflow::class)->id());
        $storedWorkflow = StoredWorkflow::findOrFail($workflow->id());
        $storedWorkflow->update([
            'arguments' => Serializer::serialize([]),
            'status' => WorkflowPendingStatus::$name,
        ]);

        $interval = CarbonInterval::minutes(3);

        WorkflowStub::timer($interval)
            ->then(static function ($value) use (&$result) {
                $result = $value;
            });

        $this->assertNull($result);
        $this->assertSame(0, $workflow->logs()->count());
        $this->assertDatabaseHas('workflow_timers', [
            'stored_workflow_id' => $workflow->id(),
            'index' => 0,
            'stop_at' => WorkflowStub::now()->addSeconds($interval->totalSeconds),
        ]);
    }

    public function testTimerReturnsUnresolvedPromiseWhenReplayingAndNoTimer(): void
    {
        $workflow = WorkflowStub::load(WorkflowStub::make(TestWorkflow::class)->id());
        $storedWorkflow = StoredWorkflow::findOrFail($workflow->id());
        $storedWorkflow->update([
            'arguments' => Serializer::serialize([]),
            'status' => WorkflowPendingStatus::$name,
        ]);

        WorkflowStub::setContext([
            'storedWorkflow' => $storedWorkflow,
            'index' => 0,
            'now' => now(),
            'replaying' => true,
        ]);

        WorkflowStub::timer('1 minute')
            ->then(static function ($value) use (&$result) {
                $result = $value;
            });

        $this->assertNull($result);
        $this->assertSame(0, $workflow->logs()->count());
        $this->assertDatabaseMissing('workflow_timers', [
            'stored_workflow_id' => $workflow->id(),
            'index' => 0,
        ]);
    }

    public function testTimerReturnsUnresolvedPromiseWhenProbingAndNoTimer(): void
    {
        $workflow = WorkflowStub::load(WorkflowStub::make(TestWorkflow::class)->id());
        $storedWorkflow = StoredWorkflow::findOrFail($workflow->id());
        $result = null;
        $storedWorkflow->update([
            'arguments' => Serializer::serialize([]),
            'status' => WorkflowPendingStatus::$name,
        ]);

        WorkflowStub::setContext([
            'storedWorkflow' => $storedWorkflow,
            'index' => 0,
            'now' => now(),
            'replaying' => true,
            'probing' => true,
        ]);

        WorkflowStub::timer('1 minute')
            ->then(static function ($value) use (&$result) {
                $result = $value;
            });

        $this->assertNull($result);
        $this->assertSame(1, WorkflowStub::getContext()->index);
        $this->assertSame(0, $workflow->logs()->count());
        $this->assertDatabaseMissing('workflow_timers', [
            'stored_workflow_id' => $workflow->id(),
            'index' => 0,
        ]);
    }

    public function testTimerCapsDelayForSqsDriver(): void
    {
        Bus::fake();

        config([
            'queue.default' => 'sqs',
        ]);
        config([
            'queue.connections.sqs' => [
                'driver' => 'sqs',
                'queue' => 'default',
            ],
        ]);

        $workflow = WorkflowStub::load(WorkflowStub::make(TestWorkflow::class)->id());
        $storedWorkflow = StoredWorkflow::findOrFail($workflow->id());
        $storedWorkflow->class = TestChildWorkflow::class;
        $storedWorkflow->update([
            'arguments' => Serializer::serialize([]),
            'status' => WorkflowPendingStatus::$name,
        ]);

        $now = now();
        WorkflowStub::setContext([
            'storedWorkflow' => $storedWorkflow,
            'index' => 0,
            'now' => $now,
            'replaying' => false,
        ]);

        $storedWorkflow->timers()
            ->create([
                'index' => 0,
                'stop_at' => $now->copy()
                    ->addHour(),
            ]);

        WorkflowStub::timer(3600)
            ->then(static function ($value) use (&$result) {
                $result = $value;
            });

        $this->assertNull($result);

        Bus::assertDispatched(Timer::class, function ($job) use ($now) {
            $delaySeconds = $job->delay->diffInSeconds($now);
            $this->assertLessThanOrEqual(900, $delaySeconds);
            $this->assertGreaterThanOrEqual(899, $delaySeconds);
            return true;
        });
    }
}
