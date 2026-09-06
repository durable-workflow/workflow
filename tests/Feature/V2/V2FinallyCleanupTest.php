<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Symfony\Component\Process\Process;
use Tests\Fixtures\V2\TestFinallyCleanupWorkflow;
use Tests\TestCase;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\WorkflowStub;

final class V2FinallyCleanupTest extends TestCase
{
    public function testQueuedFinallyCleanupSurvivesTaskDisposalAndColdReplay(): void
    {
        foreach ([false, true] as $fail) {
            $workflow = WorkflowStub::make(TestFinallyCleanupWorkflow::class);
            $workflow->start($fail);
            $this->waitForWorkflow(
                $workflow,
                static fn (WorkflowStub $workflow): bool => $workflow->refresh()
                    ->completed(),
                'completed'
            );

            $this->assertSame($fail ? 'handled' : 'success', $workflow->output());
            $this->assertSame([
                'cleanup' => 'Hello, cleanup!',
            ], $workflow->memo());
            $events = WorkflowHistoryEvent::query()->where('workflow_run_id', $workflow->runId())
                ->orderBy('sequence')
                ->get();
            $types = $events->map(static fn (WorkflowHistoryEvent $event): string => $event->event_type->value)
                ->all();
            $this->assertSame([
                'StartAccepted',
                'WorkflowStarted',
                'ActivityScheduled',
                'ActivityStarted',
                $fail ? 'ActivityFailed' : 'ActivityCompleted',
                ...($fail ? ['FailureHandled'] : []),
                'ActivityScheduled',
                'ActivityStarted',
                'ActivityCompleted',
                'TimerScheduled',
                'TimerFired',
                'MemoUpserted',
                'WorkflowCompleted',
            ], $types);

            $database = config('database.connections.' . config('database.default'));
            $this->assertIsArray($database);
            $replay = new Process([PHP_BINARY, __DIR__ . '/../../Fixtures/V2/finally_cold_replay.php'], env: [
                'FINALLY_DB_CONFIG' => json_encode($database, JSON_THROW_ON_ERROR),
                'FINALLY_RUN_ID' => $workflow->runId(),
                'FINALLY_FAIL' => $fail ? '1' : '0',
            ]);
            $replay->mustRun();

            $this->assertSame([
                'completed' => true,
                'result' => $workflow->output(),
                'history_events' => count($types),
            ], json_decode($replay->getOutput(), true, flags: JSON_THROW_ON_ERROR));
            $this->assertSame(count($types), WorkflowHistoryEvent::query()
                ->where('workflow_run_id', $workflow->runId())
                ->count());
        }
    }

    public function testRunCancellationAndTerminationDoNotExecuteFinallyCleanup(): void
    {
        foreach (['cancel', 'terminate'] as $command) {
            $workflow = WorkflowStub::make(TestFinallyCleanupWorkflow::class);
            $workflow->start(false, 3600);
            $this->waitForWorkflow(
                $workflow,
                static fn (WorkflowStub $workflow): bool => $workflow->refresh()
                    ->summary()?->wait_kind === 'timer',
                'waiting on timer'
            );

            $this->assertTrue($workflow->{$command}()->accepted());
            $this->assertSame($command === 'cancel' ? 'cancelled' : 'terminated', $workflow->refresh()->status());
            $this->assertSame([], $workflow->memo());
            $this->assertDatabaseMissing('workflow_history_events', [
                'workflow_run_id' => $workflow->runId(),
                'event_type' => 'ActivityScheduled',
            ]);
            $this->assertDatabaseMissing('workflow_history_events', [
                'workflow_run_id' => $workflow->runId(),
                'event_type' => 'MemoUpserted',
            ]);
        }
    }
}
