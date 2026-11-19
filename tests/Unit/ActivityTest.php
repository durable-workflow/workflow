<?php

declare(strict_types=1);

namespace Tests\Unit;

use BadMethodCallException;
use Exception;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\Fixtures\TestExceptionActivity;
use Tests\Fixtures\TestInvalidActivity;
use Tests\Fixtures\TestNonRetryableExceptionActivity;
use Tests\Fixtures\TestNullableArgumentsActivity;
use Tests\Fixtures\TestOtherActivity;
use Tests\Fixtures\TestWorkflow;
use Tests\TestCase;
use Workflow\Activity;
use Workflow\Exceptions\NonRetryableException;
use Workflow\Models\StoredWorkflow;
use Workflow\Serializers\Serializer;
use Workflow\States\WorkflowCreatedStatus;
use Workflow\States\WorkflowFailedStatus;
use Workflow\Webhooks;
use Workflow\WorkflowStub;

final class ActivityTest extends TestCase
{
    public function testActivity(): void
    {
        $workflow = WorkflowStub::load(WorkflowStub::make(TestWorkflow::class)->id());
        $activity = new TestOtherActivity(0, now()->toDateTimeString(), StoredWorkflow::findOrFail($workflow->id()), [
            'other',
        ]);
        $activity->timeout = 1;
        $activity->heartbeat();

        $result = $activity->handle();

        $this->assertSame(['other'], $result);
        $this->assertSame([1, 2, 5, 10, 15, 30, 60, 120], $activity->backoff());
        $this->assertSame($workflow->id(), $activity->workflowId());
        $this->assertSame($activity->timeout, pcntl_alarm(0));
    }

    public function testActivityUsesWorkflowOptionConnection(): void
    {
        $workflow = WorkflowStub::load(WorkflowStub::make(TestWorkflow::class)->id());
        $storedWorkflow = StoredWorkflow::findOrFail($workflow->id());
        $storedWorkflow->arguments = Serializer::serialize([
            'arguments' => [],
            'options' => [
                'connection' => 'sync',
                'queue' => null,
            ],
        ]);
        $storedWorkflow->save();

        $activity = new TestOtherActivity(0, now()->toDateTimeString(), $storedWorkflow, ['other']);

        $this->assertSame('sync', $activity->connection);
    }

    public function testInvalidActivity(): void
    {
        $this->expectException(BadMethodCallException::class);

        $workflow = WorkflowStub::load(WorkflowStub::make(TestWorkflow::class)->id());
        $activity = new TestInvalidActivity(0, now()->toDateTimeString(), StoredWorkflow::findOrFail($workflow->id()));

        $activity->handle();
    }

    public function testExceptionActivity(): void
    {
        $this->expectException(Exception::class);

        $workflow = WorkflowStub::load(WorkflowStub::make(TestWorkflow::class)->id());
        $activity = new TestExceptionActivity(0, now()->toDateTimeString(), StoredWorkflow::findOrFail(
            $workflow->id()
        ));

        $activity->handle();

        $workflow->fresh();

        $this->assertSame(1, $workflow->exceptions()->count());
        $this->assertSame(0, $workflow->logs()->count());
        $this->assertSame(WorkflowFailedStatus::class, $workflow->status());
    }

    public function testNonRetryableExceptionActivity(): void
    {
        $this->expectException(NonRetryableException::class);

        $workflow = WorkflowStub::load(WorkflowStub::make(TestWorkflow::class)->id());
        $activity = new TestNonRetryableExceptionActivity(0, now()->toDateTimeString(), StoredWorkflow::findOrFail(
            $workflow->id()
        ));

        $activity->handle();

        $workflow->fresh();

        $this->assertSame(1, $workflow->exceptions()->count());
        $this->assertSame(0, $workflow->logs()->count());
        $this->assertSame(WorkflowFailedStatus::class, $workflow->status());
    }

    public function testFailedActivity(): void
    {
        $workflow = WorkflowStub::load(WorkflowStub::make(TestWorkflow::class)->id());
        $activity = new TestExceptionActivity(0, now()->toDateTimeString(), StoredWorkflow::findOrFail(
            $workflow->id()
        ));

        $activity->failed(new Exception('failed'));

        $workflow->fresh();

        $this->assertSame(0, $workflow->exceptions()->count());
        $this->assertSame(0, $workflow->logs()->count());
        $this->assertSame(WorkflowCreatedStatus::class, $workflow->status());
    }

    public function testFailedJobMarksItsActivityBeforeReleasingIt(): void
    {
        $workflow = WorkflowStub::load(WorkflowStub::make(TestWorkflow::class)->id());
        $activity = new TestExceptionActivity(0, now()->toDateTimeString(), StoredWorkflow::findOrFail(
            $workflow->id()
        ));
        $activity->setJob($this->queueJob('failed-job'));

        try {
            $activity->handle();
            $this->fail('The activity should fail.');
        } catch (Exception $exception) {
            $this->assertSame('failed', $exception->getMessage());
        }

        $this->assertSame('failed-job', Cache::get(Activity::failureKey($workflow->id(), 0)));
    }

    public function testCopyDoesNotRunWhileAnotherJobsFailureIsUnrecorded(): void
    {
        $workflow = WorkflowStub::load(WorkflowStub::make(TestWorkflow::class)->id());
        Cache::forever(Activity::failureKey($workflow->id(), 0), 'failed-job');
        $activity = new TestExceptionActivity(0, now()->toDateTimeString(), StoredWorkflow::findOrFail(
            $workflow->id()
        ));
        $activity->setJob($this->queueJob('copy-job'));

        $this->assertNull($activity->handle());
        $this->assertTrue($activity->awaitingRecordedFailure);
        $this->assertSame(0, $workflow->exceptions()->count());
        $this->assertSame('failed-job', Cache::get(Activity::failureKey($workflow->id(), 0)));
    }

    public function testRetriedJobRunsAndClearsItsOwnMark(): void
    {
        $workflow = WorkflowStub::load(WorkflowStub::make(TestWorkflow::class)->id());
        Cache::forever(Activity::failureKey($workflow->id(), 0), 'retried-job');
        $activity = new TestOtherActivity(0, now()->toDateTimeString(), StoredWorkflow::findOrFail($workflow->id()), [
            'other',
        ]);
        $activity->setJob($this->queueJob('retried-job'));

        $this->assertSame(['other'], $activity->handle());
        $this->assertNull(Cache::get(Activity::failureKey($workflow->id(), 0)));
    }

    public function testActivityAlreadyComplete(): void
    {
        $workflow = WorkflowStub::load(WorkflowStub::make(TestWorkflow::class)->id());
        StoredWorkflow::findOrFail($workflow->id())->logs()->create([
            'index' => 0,
            'now' => now(),
            'class' => TestOtherActivity::class,
            'result' => Serializer::serialize('other'),
        ]);
        $activity = new TestOtherActivity(0, now()->toDateTimeString(), StoredWorkflow::findOrFail($workflow->id()), [
            'other',
        ]);
        $activity->timeout = 1;
        $activity->heartbeat();

        $result = $activity->handle();

        $this->assertNull($result);
        $this->assertSame([1, 2, 5, 10, 15, 30, 60, 120], $activity->backoff());
        $this->assertSame($workflow->id(), $activity->workflowId());
        $this->assertSame($activity->timeout, pcntl_alarm(0));
    }

    public function testWebhookUrl(): void
    {
        Webhooks::routes('Tests\\Fixtures', __DIR__ . '/../Fixtures');

        $workflow = WorkflowStub::load(WorkflowStub::make(TestWorkflow::class)->id());
        $activity = new TestOtherActivity(0, now()->toDateTimeString(), StoredWorkflow::findOrFail($workflow->id()), [
            'other',
        ]);

        $this->assertSame('http://localhost/webhooks/start/test-workflow', $activity->webhookUrl());
        $this->assertSame('http://localhost/webhooks/signal/test-workflow/1/cancel', $activity->webhookUrl('cancel'));
    }

    public function testNullArgumentsDoNotShift(): void
    {
        $workflow = WorkflowStub::load(WorkflowStub::make(TestWorkflow::class)->id());
        $activity = new TestNullableArgumentsActivity(
            0,
            now()
                ->toDateTimeString(),
            StoredWorkflow::findOrFail($workflow->id()),
            ['report1'],
            null,
            4,
            'hours'
        );

        $result = $activity->handle();

        $this->assertSame([
            'reports' => ['report1'],
            'min' => null,
            'max' => 4,
            'comparing' => 'hours',
        ], $result);
    }

    private function queueJob(string $uuid): JobContract
    {
        $job = Mockery::mock(JobContract::class);
        $job->shouldReceive('uuid')
            ->andReturn($uuid);
        $job->shouldReceive('attempts')
            ->andReturn(1);

        return $job;
    }
}
