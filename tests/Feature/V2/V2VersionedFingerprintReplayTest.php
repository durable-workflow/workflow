<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\V2\TestCommentOnlyFingerprintWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Exceptions\ConditionWaitDefinitionMismatchException;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\AwaitCall;
use Workflow\V2\Support\ConditionWaitDefinition;
use Workflow\V2\Support\QueryStateReplayer;
use Workflow\V2\Support\RunDetailView;
use Workflow\V2\Support\SourceFingerprint;
use Workflow\V2\Support\WorkflowDefinition;
use Workflow\V2\WorkflowStub;

/**
 * Mixed fleets during the move to versioned fingerprints (#1227): runs that
 * recorded version 1 keep their exact version 1 comparisons on the new code,
 * and new runs record version 2.
 */
final class V2VersionedFingerprintReplayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()
            ->set('queue.default', 'redis');
        Queue::fake();
    }

    public function testNewRunsRecordVersionTwoAndKeepIt(): void
    {
        $workflow = $this->waitingRun('versioned-fingerprint-new');

        $this->assertSame(
            WorkflowDefinition::fingerprint(TestCommentOnlyFingerprintWorkflow::class),
            $this->started($workflow)['workflow_definition_fingerprint'] ?? null,
        );
        $this->assertStringStartsWith(
            SourceFingerprint::V2_PREFIX,
            (string) $this->started($workflow)['workflow_definition_fingerprint'],
        );
        $this->assertSame(
            ConditionWaitDefinition::fingerprint($this->condition($workflow), SourceFingerprint::V2),
            $this->conditionFingerprints($workflow)[0],
        );

        $this->assertTrue($workflow->attemptUpdate('approve', true)->completed());

        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame([
            'approved' => true,
            'patched' => true,
            'stage' => 'completed',
        ], $workflow->output());
        $this->assertSame([
            $this->conditionFingerprints($workflow)[0],
            $this->conditionFingerprints($workflow)[0],
        ], $this->conditionFingerprints($workflow));

        $detail = RunDetailView::forRun(WorkflowRun::query()->findOrFail($workflow->runId()));
        $this->assertTrue($detail['workflow_definition_matches_current']);
        $this->assertSame(
            $detail['workflow_definition_fingerprint'],
            $detail['workflow_definition_current_fingerprint']
        );
        $this->assertNotSame('definition_drift', $detail['workflow_determinism_status']);
    }

    public function testVersionOneRunStartedByAnOlderWorkerReplaysOnTheNewCode(): void
    {
        $workflow = $this->waitingRun('versioned-fingerprint-mixed-fleet');
        $definitionV1 = (string) WorkflowDefinition::fingerprint(
            TestCommentOnlyFingerprintWorkflow::class,
            SourceFingerprint::V1,
        );
        $conditionV1 = (string) ConditionWaitDefinition::fingerprint(
            $this->condition($workflow),
            SourceFingerprint::V1
        );
        // What a worker before versioned fingerprints recorded.
        $this->recordAsOlderWorker($workflow, $definitionV1, $conditionV1);

        $detail = RunDetailView::forRun(WorkflowRun::query()->findOrFail($workflow->runId()));
        $this->assertSame($definitionV1, $detail['workflow_definition_fingerprint']);
        $this->assertSame($definitionV1, $detail['workflow_definition_current_fingerprint']);
        $this->assertTrue($detail['workflow_definition_matches_current']);
        $this->assertNotSame('definition_drift', $detail['workflow_determinism_status']);

        $this->assertTrue($workflow->attemptUpdate('approve', true)->completed());

        $this->assertTrue($workflow->refresh()->completed());
        // The version 1 fingerprint matches by version 1, so the missing
        // marker takes the current branch, as it did before versions.
        $this->assertSame([
            'approved' => true,
            'patched' => true,
            'stage' => 'completed',
        ], $workflow->output());
        // Everything the run records afterwards keeps its version.
        $this->assertSame([$conditionV1, $conditionV1], $this->conditionFingerprints($workflow));
        $this->assertSame($definitionV1, $this->started($workflow)['workflow_definition_fingerprint']);
    }

    public function testVersionOneRunKeepsVersionOneStrictnessForSourceChanges(): void
    {
        // A run recorded with version 1 by a build whose comments differed.
        $workflow = $this->waitingRun('versioned-fingerprint-v1-definition-drift');
        $this->recordAsOlderWorker(
            $workflow,
            'sha256:' . hash('sha256', 'the same class with other comments'),
            (string) ConditionWaitDefinition::fingerprint($this->condition($workflow), SourceFingerprint::V1),
        );

        $detail = RunDetailView::forRun(WorkflowRun::query()->findOrFail($workflow->runId()));
        $this->assertFalse($detail['workflow_definition_matches_current']);
        $this->assertStringStartsWith(SourceFingerprint::V1_PREFIX, $detail['workflow_definition_current_fingerprint']);

        $this->assertTrue($workflow->attemptUpdate('approve', true)->completed());
        $this->assertTrue($workflow->refresh()->completed());
        // Unchanged version 1 behavior: the definition counts as changed and
        // the missing marker takes the legacy default.
        $this->assertFalse($workflow->output()['patched']);

        $conditionDrift = $this->waitingRun('versioned-fingerprint-v1-condition-drift');
        $this->recordAsOlderWorker(
            $conditionDrift,
            (string) WorkflowDefinition::fingerprint(TestCommentOnlyFingerprintWorkflow::class, SourceFingerprint::V1),
            'sha256:' . hash('sha256', 'fn (): bool => $this->approved, // another comment'),
        );

        $this->expectException(ConditionWaitDefinitionMismatchException::class);
        $this->expectExceptionMessage('predicate fingerprint');

        $conditionDrift->refresh()
            ->currentState();
    }

    public function testVersionTwoRunRejectsARealConditionChange(): void
    {
        $workflow = $this->waitingRun('versioned-fingerprint-v2-condition-drift');
        $opened = $this->conditionEvents($workflow)[0];
        $payload = $opened->payload;
        $payload['condition_definition_fingerprint'] = SourceFingerprint::V2_PREFIX . hash(
            'sha256',
            'another predicate'
        );
        $opened->forceFill([
            'payload' => $payload,
        ])->save();

        $this->expectException(ConditionWaitDefinitionMismatchException::class);
        $this->expectExceptionMessage('predicate fingerprint');

        $workflow->refresh()
            ->currentState();
    }

    private function waitingRun(string $instanceId): WorkflowStub
    {
        $workflow = WorkflowStub::make(TestCommentOnlyFingerprintWorkflow::class, $instanceId);
        $workflow->start();
        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->sole();
        $this->app->call([new RunWorkflowTask($task->id), 'handle']);
        $this->assertSame('waiting', $workflow->refresh()->status());

        return $workflow;
    }

    private function recordAsOlderWorker(WorkflowStub $workflow, string $definition, string $condition): void
    {
        $started = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('event_type', HistoryEventType::WorkflowStarted->value)
            ->sole();
        $payload = $started->payload;
        $payload['workflow_definition_fingerprint'] = $definition;
        $started->forceFill([
            'payload' => $payload,
        ])->save();

        foreach ($this->conditionEvents($workflow) as $event) {
            $payload = $event->payload;
            $payload['condition_definition_fingerprint'] = $condition;
            $event->forceFill([
                'payload' => $payload,
            ])->save();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function started(WorkflowStub $workflow): array
    {
        return WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('event_type', HistoryEventType::WorkflowStarted->value)
            ->sole()
            ->payload;
    }

    private function condition(WorkflowStub $workflow): \Closure
    {
        $state = (new QueryStateReplayer())->replayState(
            WorkflowRun::query()->with('historyEvents')->findOrFail($workflow->runId()),
        );
        $this->assertInstanceOf(AwaitCall::class, $state->current);

        return $state->current->condition;
    }

    /**
     * @return list<WorkflowHistoryEvent>
     */
    private function conditionEvents(WorkflowStub $workflow): array
    {
        return WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->whereIn('event_type', [
                HistoryEventType::ConditionWaitOpened->value,
                HistoryEventType::ConditionWaitSatisfied->value,
            ])
            ->orderBy('sequence')
            ->get()
            ->all();
    }

    /**
     * @return list<string|null>
     */
    private function conditionFingerprints(WorkflowStub $workflow): array
    {
        return array_map(
            static fn (WorkflowHistoryEvent $event): ?string => $event->payload['condition_definition_fingerprint'] ?? null,
            $this->conditionEvents($workflow),
        );
    }
}
