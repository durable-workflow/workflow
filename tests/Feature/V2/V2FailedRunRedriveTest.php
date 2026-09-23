<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\Fixtures\V2\TestRedriveWorkflow;
use Tests\Fixtures\V2\TestThrowAfterGreetingWorkflow;
use Tests\TestCase;
use Workflow\V2\Contracts\WorkflowControlPlane;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\HistoryExport;
use Workflow\V2\Support\RunActivityView;
use Workflow\V2\Support\WorkflowReplayer;
use Workflow\V2\WorkflowStub;

final class V2FailedRunRedriveTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()
            ->set('queue.default', 'sync');
        config()
            ->set('queue.connections.sync.driver', 'sync');

        Cache::forget('test:redrive:first-calls');
        Cache::forget('test:redrive:second-calls');
    }

    public function testFailedRunReusesCompletedActivityAndRetriesOnlyFailedActivity(): void
    {
        WorkflowStub::fake();

        $workflow = WorkflowStub::make(TestRedriveWorkflow::class, 'redrive-activity-1');
        $workflow->start('Taylor');

        $this->assertTrue($workflow->refresh()->failed());
        $failedRunId = $workflow->runId();
        $originalHistoryCount = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $failedRunId)
            ->count();
        $this->assertSame(1, (int) Cache::get('test:redrive:first-calls'));
        $this->assertSame(1, (int) Cache::get('test:redrive:second-calls'));

        $controlPlane = app(WorkflowControlPlane::class);
        $foreignNamespace = $controlPlane->redrive('redrive-activity-1', $failedRunId, [
            'namespace' => 'another-namespace',
        ]);
        $this->assertFalse($foreignNamespace['accepted']);
        $this->assertSame('instance_not_found', $foreignNamespace['reason']);

        $result = $controlPlane->redrive('redrive-activity-1', $failedRunId, [
            'request_id' => 'retry-payment-once',
        ]);

        $this->assertTrue($result['accepted'], (string) $result['reason']);
        $this->assertNotSame($failedRunId, $result['workflow_run_id']);
        $this->assertSame($failedRunId, $result['continued_from_run_id']);
        $this->assertSame(2, $result['resume_step_sequence']);

        $repeated = $controlPlane->redrive('redrive-activity-1', $failedRunId, [
            'request_id' => 'retry-payment-once',
        ]);
        $this->assertSame($result['workflow_run_id'], $repeated['workflow_run_id']);
        $this->assertSame(2, WorkflowRun::query()->where('workflow_instance_id', 'redrive-activity-1')->count());
        $this->assertSame(1, WorkflowCommand::query()
            ->where('workflow_instance_id', 'redrive-activity-1')
            ->where('command_type', 'redrive')
            ->count());

        $this->assertSame(1, (int) Cache::get('test:redrive:first-calls'));
        $this->assertSame(2, (int) Cache::get('test:redrive:second-calls'));
        $this->assertSame('failed', WorkflowRun::query()->findOrFail($failedRunId)->status->value);
        $this->assertSame(
            $originalHistoryCount,
            WorkflowHistoryEvent::query()->where('workflow_run_id', $failedRunId)->count(),
        );

        $describe = $controlPlane->describe('redrive-activity-1', [
            'run_id' => $result['workflow_run_id'],
        ]);
        $this->assertSame('completed', $describe['run']['status']);
        $this->assertSame($failedRunId, $describe['run']['continued_from_run_id']);
        $this->assertSame(2, $describe['run']['resume_step_sequence']);

        $reused = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $result['workflow_run_id'])
            ->where('event_type', HistoryEventType::ActivityCompleted)
            ->firstOrFail();
        $this->assertSame($failedRunId, $reused->payload['reused_from_run_id']);

        $sourceRun = WorkflowRun::query()->findOrFail($failedRunId);
        $successorRun = WorkflowRun::query()->findOrFail($result['workflow_run_id']);
        $sourceCompletion = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $failedRunId)
            ->where('event_type', HistoryEventType::ActivityCompleted)
            ->firstOrFail();
        $this->assertSame($sourceRun->started_at?->toIso8601String(), $successorRun->workflowOutput()['time_at_start']);
        $this->assertSame(
            $sourceCompletion->recorded_at?->toIso8601String(),
            $successorRun->workflowOutput()['time_after_first_activity'],
        );

        $activities = RunActivityView::activitiesForRun(WorkflowRun::query()->findOrFail($result['workflow_run_id']));
        $this->assertSame($failedRunId, $activities[0]['reused_from_run_id']);
        $this->assertNull($activities[1]['reused_from_run_id']);

        $export = HistoryExport::forRun($successorRun->fresh());
        $this->assertSame($failedRunId, $export['activities'][0]['reused_from_run_id']);
        $this->assertNull($export['activities'][1]['reused_from_run_id']);

        $portableExport = json_decode(json_encode($export, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        $replayed = (new WorkflowReplayer())->replayExport($portableExport);
        $this->assertNull($replayed->current);
        $this->assertSame(3, $replayed->sequence);
        $this->assertSame([
            'time_at_start' => $successorRun->workflowOutput()['time_at_start'],
            'time_after_first_activity' => $successorRun->workflowOutput()['time_after_first_activity'],
        ], $replayed->workflow->replayTimes());

        DB::disconnect();
        DB::reconnect();
        $reloadedRun = WorkflowRun::query()->findOrFail($result['workflow_run_id']);
        $this->assertSame(
            $replayed->workflow->replayTimes(),
            (new WorkflowReplayer())->replay($reloadedRun)
                ->workflow->replayTimes()
        );
    }

    public function testCompletedRunIsNotEligibleForRedrive(): void
    {
        WorkflowStub::fake();

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'redrive-completed-1');
        $workflow->start('Taylor');

        $this->assertTrue($workflow->refresh()->completed());

        $result = app(WorkflowControlPlane::class)->redrive(
            'redrive-completed-1',
            $workflow->runId(),
            [
                'request_id' => 'invalid-redrive',
            ],
        );

        $this->assertFalse($result['accepted']);
        $this->assertSame('run_not_failed', $result['reason']);
        $this->assertSame(1, WorkflowRun::query()->where('workflow_instance_id', 'redrive-completed-1')->count());
    }

    public function testWorkflowFailureAfterCompletedActivityHasNoSafeRedriveBoundary(): void
    {
        WorkflowStub::fake();

        $workflow = WorkflowStub::make(TestThrowAfterGreetingWorkflow::class, 'redrive-ambiguous-1');
        $workflow->start('Taylor');

        $this->assertTrue($workflow->refresh()->failed());

        $result = app(WorkflowControlPlane::class)->redrive('redrive-ambiguous-1', $workflow->runId());

        $this->assertFalse($result['accepted']);
        $this->assertSame('unrecorded_failure_boundary', $result['reason']);
        $this->assertSame(1, WorkflowRun::query()->where('workflow_instance_id', 'redrive-ambiguous-1')->count());
    }

    public function testFailedSuccessorCanBeRedrivenAgainWithoutRepeatingCompletedActivity(): void
    {
        config()->set('workflows.v2.testing.redrive_failures_before_success', 2);
        WorkflowStub::fake();

        $workflow = WorkflowStub::make(TestRedriveWorkflow::class, 'redrive-chain-1');
        $workflow->start('Taylor');

        $controlPlane = app(WorkflowControlPlane::class);
        $first = $controlPlane->redrive('redrive-chain-1', $workflow->runId());
        $this->assertTrue($first['accepted'], (string) $first['reason']);
        $this->assertSame('failed', WorkflowRun::query()->findOrFail($first['workflow_run_id'])->status->value);

        $second = $controlPlane->redrive('redrive-chain-1', $first['workflow_run_id']);
        $this->assertTrue($second['accepted'], (string) $second['reason']);
        $this->assertSame('completed', WorkflowRun::query()->findOrFail($second['workflow_run_id'])->status->value);
        $this->assertSame(1, (int) Cache::get('test:redrive:first-calls'));
        $this->assertSame(3, (int) Cache::get('test:redrive:second-calls'));

        $firstStart = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $first['workflow_run_id'])
            ->where('event_type', HistoryEventType::WorkflowStarted)
            ->firstOrFail();
        $secondStart = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $second['workflow_run_id'])
            ->where('event_type', HistoryEventType::WorkflowStarted)
            ->firstOrFail();
        $this->assertSame($firstStart->payload['replayed_started_at'], $secondStart->payload['replayed_started_at']);

        $firstCompletion = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $first['workflow_run_id'])
            ->where('event_type', HistoryEventType::ActivityCompleted)
            ->firstOrFail();
        $secondCompletion = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $second['workflow_run_id'])
            ->where('event_type', HistoryEventType::ActivityCompleted)
            ->firstOrFail();
        $this->assertSame(
            $firstCompletion->payload['reused_recorded_at'],
            $secondCompletion->payload['reused_recorded_at']
        );
    }
}
