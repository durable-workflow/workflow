<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\V2\TestRuntimeProfileProbeWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Exceptions\RuntimeProfileRefusal;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\DefaultWorkflowControlPlane;
use Workflow\V2\Support\DefaultWorkflowTaskBridge;
use Workflow\V2\Support\HistoryExport;
use Workflow\V2\Support\MessageService;
use Workflow\V2\Support\QueryStateReplayer;
use Workflow\V2\Support\RunDetailView;
use Workflow\V2\Support\RunSummaryProjector;
use Workflow\V2\Support\RunWaitProjector;
use Workflow\V2\Support\TaskDispatcher;
use Workflow\V2\Support\TaskRepair;
use Workflow\V2\Support\WorkflowContinuationCache;
use Workflow\V2\Support\WorkflowContinuationSession;
use Workflow\V2\Support\WorkflowExecutor;
use Workflow\V2\TaskWatchdog;

final class RuntimeProfileEntryGuardTest extends TestCase
{
    #[DataProvider('entryPaths')]
    public function testRefusalHasNoDurableQueueOrThrottleEffects(string $path): void
    {
        TestRuntimeProfileProbeWorkflow::$invocations = [];
        Queue::fake();
        [$run, $task] = $this->protectedRun();
        $bridge = new DefaultWorkflowTaskBridge(new WorkflowExecutor());
        $before = $this->rows();
        $throttle = Cache::get(TaskWatchdog::LOOP_THROTTLE_KEY);
        $operation = match ($path) {
            'workflow job' => static fn () => (new RunWorkflowTask($task->id))->handle(new WorkflowExecutor()),
            'activity job' => static fn () => (new RunActivityTask($task->id))->handle(),
            'timer job' => static fn () => (new RunTimerTask($task->id))->handle(),
            'claim' => static fn () => $bridge->claimStatus($task->id),
            'history' => static fn () => $bridge->historyPayload($task->id),
            'paged history' => static fn () => $bridge->historyPayloadPaginated($task->id),
            'execute' => static fn () => $bridge->execute($task->id),
            'failure' => static fn () => $bridge->fail($task->id, 'synthetic failure'),
            'heartbeat' => static fn () => $bridge->heartbeat($task->id),
            'completion' => static fn () => $bridge->complete($task->id, []),
            'poll' => static fn () => $bridge->poll(null, null),
            'watchdog' => static fn () => TaskWatchdog::runPass(),
            'dispatch' => static fn () => TaskDispatcher::dispatch($task),
            'query replay' => static fn () => (new QueryStateReplayer())->replayState($run),
            'executor' => static fn () => (new WorkflowExecutor())->run($run, $task),
            'cached summary' => static fn () => RunSummaryProjector::project($run),
            'cached waits' => static fn () => RunWaitProjector::project($run),
            'cached detail' => static fn () => RunDetailView::forRun($run),
            'cached export' => static fn () => HistoryExport::forRun($run),
            'cached stream' => static fn () => (new MessageService())->stream($run),
            'cached repair' => static fn () => TaskRepair::repairRun($run, new WorkflowRunSummary()),
            'history write' => static fn () => WorkflowHistoryEvent::record($run, HistoryEventType::WorkflowStarted, []),
            'continuation' => static fn () => (new WorkflowContinuationSession(
                new WorkflowContinuationCache(),
                DB::connection(),
                1
            ))
                ->begin($run, $task, TestRuntimeProfileProbeWorkflow::class),
            'control start' => static fn () => (new DefaultWorkflowControlPlane())->start(
                TestRuntimeProfileProbeWorkflow::class,
                $run->workflow_instance_id
            ),
            'control query' => static fn () => (new DefaultWorkflowControlPlane())->query(
                $run->workflow_instance_id,
                'inspect'
            ),
        };
        try {
            $operation();
            $this->fail('The unsupported profile must refuse before any effect.');
        } catch (RuntimeProfileRefusal $error) {
            $this->assertSame('runtime_profile_unsupported', $error->reason);
        }
        $this->assertSame($before, $this->rows());
        $this->assertSame($throttle, Cache::get(TaskWatchdog::LOOP_THROTTLE_KEY));
        Queue::assertNothingPushed();
        $this->assertSame([], TestRuntimeProfileProbeWorkflow::$invocations);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function entryPaths(): iterable
    {
        foreach (['workflow job', 'activity job', 'timer job', 'claim', 'history', 'paged history', 'execute',
            'failure', 'heartbeat', 'completion', 'poll', 'watchdog', 'dispatch', 'query replay', 'executor',
            'cached summary', 'cached waits', 'cached detail', 'cached export', 'cached stream', 'cached repair',
            'history write', 'continuation', 'control start', 'control query'] as $path) {
            yield $path => [$path];
        }
    }

    public function testMissingTaskRepairRefusesBeforeItsThrottleAndFailurePaths(): void
    {
        TestRuntimeProfileProbeWorkflow::$invocations = [];
        Queue::fake();
        [$run, $task] = $this->protectedRun();
        DB::table('workflow_tasks')->where('id', $task->id)->delete();
        $before = $this->rows();
        $throttle = Cache::get(TaskWatchdog::LOOP_THROTTLE_KEY);
        try {
            TaskWatchdog::runPass(runIds: [$run->id]);
            $this->fail('A deleted task cannot hide its run profile.');
        } catch (RuntimeProfileRefusal $error) {
            $this->assertSame('runtime_profile_unsupported', $error->reason);
        }
        $this->assertSame($before, $this->rows());
        $this->assertSame($throttle, Cache::get(TaskWatchdog::LOOP_THROTTLE_KEY));
        Queue::assertNothingPushed();
        $this->assertSame([], TestRuntimeProfileProbeWorkflow::$invocations);
    }

    public function testOmittedOrForgedNullProjectionCannotHideTheAuthoritativeProfile(): void
    {
        [$run] = $this->protectedRun();
        foreach ([['id'], ['id', DB::raw('NULL AS runtime_profile')]] as $columns) {
            try {
                WorkflowRun::query()->select($columns)->findOrFail($run->id);
                $this->fail('The writer row must establish support before observers.');
            } catch (RuntimeProfileRefusal $error) {
                $this->assertSame('runtime_profile_unsupported', $error->reason);
            }
        }
    }

    #[DataProvider('lateCandidateFamilies')]
    public function testLateProtectedCandidatesAreValidatedBeforeAnyMaintenanceEffect(
        string $family,
        bool $protectedCaller
    ): void {
        Queue::fake();
        $inserted = false;
        $before = null;
        $cache = $this->cacheRows();
        DB::listen(function ($event) use ($family, $protectedCaller, &$inserted, &$before): void {
            if ($inserted || ! str_contains($event->sql, 'runtime_profile') || ! str_contains(
                $event->sql,
                'is not null'
            )) {
                return;
            }
            // The first scope query has completed without a protected row. This
            // barrier injects an officially shaped future admission before scan.
            $inserted = true;
            [$run, $task] = $this->protectedRun();
            $past = now()
                ->subHour()
                ->format('Y-m-d H:i:s.u');
            DB::table('workflow_tasks')->where('id', $task->id)->update([
                'created_at' => $past,
                'available_at' => $past,
            ]);
            if ($family !== 'existing') {
                DB::table('workflow_tasks')->where('id', $task->id)->delete();
            }
            if ($family === 'missing') {
                DB::table('workflow_run_summaries')->insert([
                    'id' => $run->id,
                    'workflow_instance_id' => $run->workflow_instance_id,
                    'run_number' => 2,
                    'class' => TestRuntimeProfileProbeWorkflow::class,
                    'workflow_type' => TestRuntimeProfileProbeWorkflow::class,
                    'namespace' => 'profile-tests',
                    'status' => 'waiting',
                    'status_bucket' => 'open',
                    'liveness_state' => 'repair_needed',
                ]);
            } elseif ($family === 'deadline') {
                DB::table('workflow_runs')->where('id', $run->id)->update([
                    'run_deadline_at' => $past,
                ]);
            } elseif ($family === 'activity') {
                DB::table('activity_executions')->insert([
                    'id' => (string) Str::ulid(),
                    'workflow_run_id' => $run->id,
                    'sequence' => 1,
                    'activity_class' => 'not-invoked',
                    'activity_type' => 'not-invoked',
                    'status' => 'pending',
                    'schedule_deadline_at' => $past,
                ]);
            } elseif (str_starts_with($family, 'service')) {
                $legacyId = DB::table('workflow_runs')->whereNull('runtime_profile')->value('id');
                $otherInstance = 'guard-other-' . strtolower((string) Str::ulid());
                DB::table('workflow_instances')->insert([
                    'id' => $otherInstance,
                    'namespace' => 'profile-tests',
                    'workflow_class' => TestRuntimeProfileProbeWorkflow::class,
                    'workflow_type' => TestRuntimeProfileProbeWorkflow::class,
                    'current_run_id' => $legacyId,
                    'run_count' => 1,
                ]);
                DB::table('workflow_runs')->where('id', $legacyId)->update([
                    'workflow_instance_id' => $otherInstance,
                ]);
                $callerId = $protectedCaller ? $run->id : $legacyId;
                $targetId = $protectedCaller ? $legacyId : $run->id;
                $callId = (string) Str::ulid();
                DB::table('workflow_service_calls')->insert([
                    'id' => $callId,
                    'namespace' => 'profile-tests',
                    'endpoint_name' => 'guard',
                    'service_name' => 'guard',
                    'operation_name' => 'guard',
                    'status' => 'accepted',
                    'operation_mode' => 'async',
                    'caller_namespace' => 'profile-tests',
                    'target_namespace' => 'profile-tests',
                    'caller_workflow_instance_id' => $protectedCaller ? $run->workflow_instance_id : $otherInstance,
                    'caller_workflow_run_id' => $callerId,
                    'linked_workflow_instance_id' => $protectedCaller ? $otherInstance : $run->workflow_instance_id,
                    'linked_workflow_run_id' => $targetId,
                    'resolved_binding_kind' => 'workflow_run',
                    'accepted_at' => $past,
                    'call_deadline_at' => $family === 'service expiry' ? $past : null,
                ]);
                if ($family === 'service dispatch') {
                    DB::table('workflow_service_call_dispatches')->insert([
                        'service_call_id' => $callId,
                        'state' => 'scheduled',
                        'dispatch_options' => '{}',
                        'available_at' => $past,
                    ]);
                } elseif ($family === 'service link') {
                    DB::table('workflow_history_events')->insert([
                        'id' => (string) Str::ulid(),
                        'workflow_run_id' => $targetId,
                        'sequence' => 1,
                        'event_type' => HistoryEventType::WorkflowCompleted->value,
                        'recorded_at' => $past,
                        'payload' => '{}',
                    ]);
                }
            }
            $before = $this->rows();
        });
        try {
            TaskWatchdog::runPass();
            $this->fail('Post-preflight admission must not reach maintenance effects.');
        } catch (RuntimeProfileRefusal $error) {
            $this->assertSame('runtime_profile_unsupported', $error->reason);
        }
        $this->assertTrue($inserted);
        $this->assertNotNull($before);
        $this->assertSame($before, $this->rows());
        $this->assertSame($cache, $this->cacheRows());
        Queue::assertNothingPushed();
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function lateCandidateFamilies(): iterable
    {
        foreach (['existing', 'missing', 'deadline', 'activity'] as $family) {
            yield $family => [$family, false];
        }
        foreach (['service expiry', 'service dispatch', 'service link'] as $family) {
            yield $family . ' protected caller' => [$family, true];
            yield $family . ' protected target' => [$family, false];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function cacheRows(): array
    {
        $connection = app('redis')
            ->connection('cache');
        $keys = $connection->keys('*');
        sort($keys);
        $values = [];
        foreach ($keys as $key) {
            $values[$key] = $connection->get($key);
        }
        return $values;
    }

    /**
     * @return array{WorkflowRun, WorkflowTask}
     */
    private function protectedRun(): array
    {
        $instance = WorkflowInstance::query()->create([
            'id' => 'guard-' . strtolower((string) Str::ulid()),
            'namespace' => 'profile-tests',
            'workflow_class' => TestRuntimeProfileProbeWorkflow::class,
            'workflow_type' => TestRuntimeProfileProbeWorkflow::class,
            'run_count' => 1,
        ]);
        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestRuntimeProfileProbeWorkflow::class,
            'workflow_type' => TestRuntimeProfileProbeWorkflow::class,
            'namespace' => 'profile-tests',
            'status' => RunStatus::Waiting,
            'payload_codec' => 'avro',
            'arguments' => Serializer::serializeWithCodec('avro', []),
            'started_at' => now(),
        ])->refresh();
        $attributes = $run->getRawOriginal();
        $attributes['id'] = (string) Str::ulid();
        $attributes['run_number'] = 2;
        $attributes['runtime_profile'] = 'future';
        DB::table('workflow_runs')->insert($attributes);
        DB::table('workflow_instances')->where('id', $instance->id)->update([
            'current_run_id' => $attributes['id'],
        ]);
        // Simulate an already cached legacy-looking object. Fresh storage wins.
        $attributes['runtime_profile'] = null;
        $run->setRawAttributes($attributes, true);
        $taskAttributes = [
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $run->id,
            'namespace' => 'profile-tests',
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->format('Y-m-d H:i:s.u'),
            'payload' => '{}',
        ];
        DB::table('workflow_tasks')->insert($taskAttributes);
        $task = (new WorkflowTask())->newFromBuilder($taskAttributes);

        return [$run, $task];
    }

    /**
     * @return array<string, list<string>>
     */
    private function rows(): array
    {
        $rows = [];
        foreach (DB::getSchemaBuilder()->getTableListing() as $table) {
            $values = DB::table($table)->get()->map(
                static fn ($row): string => json_encode($row, JSON_THROW_ON_ERROR)
            )->all();
            sort($values);
            $rows[$table] = $values;
        }
        ksort($rows);

        return $rows;
    }
}
