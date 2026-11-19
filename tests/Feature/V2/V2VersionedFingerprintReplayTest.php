<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\V2\TestCommentOnlyFingerprintWorkflow;
use Tests\Fixtures\V2\TestParentWaitingOnChildWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Exceptions\ConditionWaitDefinitionMismatchException;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowLink;
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
 * Mixed fleets during the move to versioned fingerprints (#1227): new runs
 * record version 1 unless version 2 is configured, every run keeps the
 * version it recorded, and runs that recorded version 1 keep their exact
 * version 1 comparisons on the new code.
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

    public function testNewRunsRecordVersionOneByDefault(): void
    {
        $workflow = $this->waitingRun('versioned-fingerprint-default');

        // What a worker before versioned fingerprints records and compares.
        $this->assertSame(
            WorkflowDefinition::fingerprint(TestCommentOnlyFingerprintWorkflow::class, SourceFingerprint::V1),
            $this->started($workflow)['workflow_definition_fingerprint'] ?? null,
        );
        $this->assertSame(
            [ConditionWaitDefinition::fingerprint($this->condition($workflow), SourceFingerprint::V1)],
            $this->conditionFingerprints($workflow),
        );

        $this->assertTrue($workflow->attemptUpdate('approve', true)->completed());
        $this->assertTrue($workflow->refresh()->completed());
        $this->assertTrue($workflow->output()['patched']);
    }

    public function testAnInFlightParentStartsItsChildUnderAnyConfiguredValue(): void
    {
        foreach ([
            'unset' => [null, SourceFingerprint::V1_PREFIX],
            'v1' => ['v1', SourceFingerprint::V1_PREFIX],
            'v2' => ['v2', SourceFingerprint::V2_PREFIX],
            // Boot refuses these; a value that changes afterwards records v1
            // instead of failing the parent.
            'typo' => ['V2.0', SourceFingerprint::V1_PREFIX],
            'unknown' => ['v3', SourceFingerprint::V1_PREFIX],
        ] as $case => [$configured, $prefix]) {
            $this->recordVersion('v1');
            $parent = WorkflowStub::make(
                TestParentWaitingOnChildWorkflow::class,
                'versioned-fingerprint-parent-' . $case
            );
            $parent->start(60);
            $this->recordVersion($configured);

            $this->runReadyTask($parent);

            $failures = WorkflowFailure::query()
                ->where('workflow_run_id', $parent->runId())
                ->pluck('message')
                ->all();
            $this->assertSame('waiting', $parent->refresh()->status(), $case . ': ' . implode('; ', $failures));
            $link = WorkflowLink::query()
                ->where('parent_workflow_run_id', $parent->runId())
                ->sole();
            $childStarted = WorkflowHistoryEvent::query()
                ->where('workflow_run_id', $link->child_workflow_run_id)
                ->where('event_type', HistoryEventType::WorkflowStarted->value)
                ->sole();
            $this->assertStringStartsWith(
                $prefix,
                (string) ($childStarted->payload['workflow_definition_fingerprint'] ?? null),
                $case,
            );
        }
    }

    public function testOptedInRunsRecordVersionTwoAndKeepItAfterSwitchingBack(): void
    {
        $this->recordVersion('v2');
        $workflow = $this->waitingRun('versioned-fingerprint-new');

        $this->assertSame(
            WorkflowDefinition::fingerprint(TestCommentOnlyFingerprintWorkflow::class, SourceFingerprint::V2),
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

        // Switching back to v1 only changes what new runs record.
        $this->recordVersion('v1');
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
        $this->assertStringStartsWith(
            SourceFingerprint::V2_PREFIX,
            (string) $this->conditionFingerprints($workflow)[1]
        );

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
        $this->recordVersion('v2');
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

    private function recordVersion(?string $version): void
    {
        config()
            ->set(SourceFingerprint::CONFIG_KEY, $version);
    }

    private function waitingRun(string $instanceId): WorkflowStub
    {
        $workflow = WorkflowStub::make(TestCommentOnlyFingerprintWorkflow::class, $instanceId);
        $workflow->start();
        $this->runReadyTask($workflow);
        $this->assertSame('waiting', $workflow->refresh()->status());

        return $workflow;
    }

    private function runReadyTask(WorkflowStub $workflow): void
    {
        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->sole();
        $this->app->call([new RunWorkflowTask($task->id), 'handle']);
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
