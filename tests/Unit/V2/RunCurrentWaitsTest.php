<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunWait;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\DefaultOperatorObservabilityRepository;
use Workflow\V2\Support\RunCurrentWaits;

final class RunCurrentWaitsTest extends TestCase
{
    public function testReadBudgetIsIndependentOfRunAndChildHistoryAndAttemptCounts(): void
    {
        $run = $this->createRun('selected', 'namespace-a');
        $child = $this->createRun('child', 'namespace-a');
        $other = $this->createRun('other', 'namespace-b');
        $this->wait($run, 'activity', [
            'task_id' => 'retry',
            'resume_source_id' => 'activity',
        ]);
        $this->activity($run, 'activity', [
            'current_attempt_id' => 'last-attempt',
        ]);
        $this->task($run, 'retry', [
            'payload' => [
                'activity_execution_id' => 'activity',
            ],
        ]);
        WorkflowLink::query()->create([
            'id' => 'child-link',
            'link_type' => 'child_workflow',
            'sequence' => 1,
            'parent_workflow_instance_id' => $run->workflow_instance_id,
            'parent_workflow_run_id' => $run->id,
            'child_workflow_instance_id' => $child->workflow_instance_id,
            'child_workflow_run_id' => $child->id,
            'is_primary_parent' => true,
        ]);
        for ($i = 1; $i <= 500; ++$i) {
            $this->wait($run, 'signal-' . $i, [
                'kind' => 'signal',
                'position' => $i,
            ]);
            $this->wait($other, 'other-' . $i, [
                'kind' => 'signal',
            ]);
        }
        $attemptRows = [];
        $historyRows = [];
        for ($i = 1; $i <= 1001; ++$i) {
            $attemptRows[] = [
                'id' => $i === 1001 ? 'last-attempt' : 'attempt-' . $i,
                'workflow_run_id' => $run->id,
                'activity_execution_id' => 'activity',
                'attempt_number' => $i,
                'status' => 'failed',
                'started_at' => now(),
            ];
            foreach ([$run, $child, $other] as $historyRun) {
                $historyRows[] = [
                    'id' => $historyRun->id . '-' . $i,
                    'workflow_run_id' => $historyRun->id,
                    'sequence' => $i,
                    'event_type' => 'signal_received',
                    'payload' => '{}',
                    'recorded_at' => now(),
                ];
            }
        }
        foreach (array_chunk($attemptRows, 100) as $chunk) {
            DB::table('activity_attempts')->insert($chunk);
        }
        foreach (array_chunk($historyRows, 100) as $chunk) {
            DB::table('workflow_history_events')->insert($chunk);
        }
        $retrieved = [
            'waits' => 0,
            'tasks' => 0,
            'activities' => 0,
            'attempts' => 0,
            'history' => 0,
            'runs' => 0,
        ];
        foreach ([
            'waits' => WorkflowRunWait::class,
            'tasks' => WorkflowTask::class,
            'activities' => ActivityExecution::class,
            'attempts' => ActivityAttempt::class,
            'history' => WorkflowHistoryEvent::class,
            'runs' => WorkflowRun::class,
        ] as $key => $model) {
            $model::retrieved(static function () use (&$retrieved, $key): void {
                ++$retrieved[$key];
            });
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $snapshot = (new DefaultOperatorObservabilityRepository())->runCurrentWaits($run);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame([
            'waits' => 51,
            'tasks' => 1,
            'activities' => 1,
            'attempts' => 1,
            'history' => 0,
            'runs' => 0,
        ], $retrieved);
        $this->assertCount(4, $queries);
        foreach ($queries as $query) {
            $this->assertStringStartsWith('select ', strtolower($query['query']));
            $this->assertStringNotContainsString('workflow_history_events', $query['query']);
        }
        $this->assertCount(50, $snapshot['waits']);
        $this->assertTrue($snapshot['has_more']);
        $this->assertNull($snapshot['total_count']);
        $this->assertSame('not_evaluated', $snapshot['history_audit']);
        $this->assertSame('partial', $snapshot['state']);
        $this->assertSame(1001, $snapshot['waits'][0]['attempt_number']);
        $this->assertSame(501, WorkflowRunWait::query()->where('workflow_run_id', $run->id)->count());
        $this->assertSame([], $run->getRelations());
    }

    public function testRetryReportsItsOwnPlannedAttemptBudgetAndDeadline(): void
    {
        $run = $this->createRun('retry');
        $now = Carbon::parse('2026-10-06T20:00:00Z');
        $this->wait($run, 'activity', [
            'task_id' => 'retry-task',
            'resume_source_id' => 'activity',
            'deadline_at' => $now->copy()
                ->subMinute(),
        ]);
        $this->activity($run, 'activity', [
            'attempt_count' => 2,
            'retry_policy' => [
                'max_attempts' => 5,
            ],
            'schedule_deadline_at' => $now->copy()
                ->addHour(),
            'schedule_to_close_deadline_at' => $now->copy()
                ->addMinutes(20),
            'close_deadline_at' => $now->copy()
                ->subMinute(),
        ]);
        $this->task($run, 'retry-task', [
            'available_at' => $now->copy()
                ->addMinutes(10),
            'payload' => [
                'activity_execution_id' => 'activity',
                'retry_after_attempt' => 2,
                'max_attempts' => 5,
            ],
        ]);
        $this->activity($run, 'unrelated', [
            'sequence' => 2,
            'schedule_deadline_at' => $now->copy()
                ->subMinute(),
            'arguments' => 'invalid-external-payload',
            'result' => 'do-not-decode',
        ]);

        $wait = RunCurrentWaits::forRun($run, now: $now)['waits'][0];

        $this->assertSame('activity_retry', $wait['kind']);
        $this->assertSame('planned', $wait['state']);
        $this->assertSame('2026-10-06T20:10:00+00:00', $wait['next_scheduled_resume_at']);
        $this->assertSame('2026-10-06T20:20:00+00:00', $wait['deadline_at']);
        $this->assertSame(3, $wait['attempt_number']);
        $this->assertSame(5, $wait['attempt_limit']);
        $this->assertSame('activity', $wait['dependency_id']);
        $this->assertSame('retry-task', $wait['task_id']);
    }

    public function testRunningActivityUsesItsCurrentAttemptAndEarliestLiveDeadline(): void
    {
        $run = $this->createRun('running');
        $now = Carbon::parse('2026-10-06T20:00:00Z');
        $this->wait($run, 'activity', [
            'task_id' => 'task',
            'resume_source_id' => 'activity',
        ]);
        $this->activity($run, 'activity', [
            'status' => 'running',
            'current_attempt_id' => 'attempt',
            'schedule_deadline_at' => $now->copy()
                ->subHour(),
            'close_deadline_at' => $now->copy()
                ->addMinute(),
            'heartbeat_deadline_at' => $now->copy()
                ->subSecond(),
            'schedule_to_close_deadline_at' => $now->copy()
                ->addMinutes(10),
        ]);
        ActivityAttempt::query()->create([
            'id' => 'attempt',
            'workflow_run_id' => $run->id,
            'activity_execution_id' => 'activity',
            'attempt_number' => 4,
            'status' => 'running',
            'started_at' => $now,
        ]);
        $this->task($run, 'task', [
            'status' => 'leased',
            'available_at' => $now->copy()
                ->subMinute(),
            'lease_expires_at' => $now->copy()
                ->addSeconds(15),
            'payload' => [
                'activity_execution_id' => 'activity',
            ],
        ]);

        $wait = RunCurrentWaits::forRun($run, now: $now)['waits'][0];

        $this->assertSame('deadline_elapsed', $wait['state']);
        $this->assertSame('2026-10-06T19:59:59+00:00', $wait['deadline_at']);
        $this->assertNull($wait['next_scheduled_resume_at']);
        $this->assertSame(4, $wait['attempt_number']);
        $this->assertSame('attempt', $wait['attempt_id']);
        $this->assertSame('2026-10-06T20:00:15+00:00', $wait['lease_expires_at']);
    }

    public function testTimerEligibilityAndUnknownSignalTimingDoNotBecomeOverdueByAge(): void
    {
        $run = $this->createRun('timer');
        $now = Carbon::parse('2026-10-06T20:00:00Z');
        $this->wait($run, 'timer', [
            'kind' => 'timer',
            'deadline_at' => $now->copy()
                ->subMinute(),
            'resume_source_id' => 'timer',
        ]);
        $this->wait($run, 'signal', [
            'kind' => 'signal',
            'position' => 1,
            'opened_at' => $now->copy()
                ->subYear(),
        ]);

        $waits = RunCurrentWaits::forRun($run, now: $now)['waits'];

        $this->assertSame('eligible', $waits[0]['state']);
        $this->assertNull($waits[0]['deadline_at']);
        $this->assertSame('2026-10-06T19:59:00+00:00', $waits[0]['next_scheduled_resume_at']);
        $this->assertSame('resume_time_unknown', $waits[1]['state']);
        $this->assertNull($waits[1]['next_scheduled_resume_at']);
        $this->assertNull($waits[1]['deadline_at']);
    }

    public function testWrongDependencyAndCrossRunPointersCannotDonateTimingOrAttemptData(): void
    {
        $run = $this->createRun('own');
        $other = $this->createRun('other');
        $this->activity($run, 'activity', [
            'current_attempt_id' => 'foreign-attempt',
        ]);
        $this->activity($other, 'foreign-activity');
        $this->wait($run, 'activity', [
            'task_id' => 'wrong-task',
            'resume_source_id' => 'activity',
        ]);
        $this->wait($run, 'foreign', [
            'task_id' => 'foreign-task',
            'resume_source_id' => 'foreign-activity',
            'position' => 1,
        ]);
        $this->task($run, 'wrong-task', [
            'available_at' => now()
                ->addHour(),
            'payload' => [
                'activity_execution_id' => 'different',
                'retry_after_attempt' => 9,
                'max_attempts' => 10,
            ],
        ]);
        $this->task($other, 'foreign-task', [
            'available_at' => now()
                ->addHour(),
        ]);
        ActivityAttempt::query()->create([
            'id' => 'foreign-attempt',
            'workflow_run_id' => $other->id,
            'activity_execution_id' => 'foreign-activity',
            'attempt_number' => 90,
            'status' => 'running',
            'started_at' => now(),
        ]);

        $waits = RunCurrentWaits::forRun($run)['waits'];

        $this->assertSame('activity', $waits[0]['kind']);
        $this->assertSame('resume_time_unknown', $waits[0]['state']);
        $this->assertNull($waits[0]['task_id']);
        $this->assertNull($waits[0]['next_scheduled_resume_at']);
        $this->assertNull($waits[0]['attempt_id']);
        $this->assertNull($waits[0]['attempt_number']);
        $this->assertNull($waits[0]['attempt_limit']);
        $this->assertSame('unavailable', $waits[1]['state']);
        $this->assertSame('activity_metadata_unavailable', $waits[1]['unavailable_reason']);
        $this->assertNull($waits[1]['task_id']);
        $this->assertNull($waits[1]['deadline_at']);
    }

    public function testUnsupportedAndStaleWaitsAreExplicitRatherThanTrustedCurrentState(): void
    {
        $run = $this->createRun('unsupported');
        $this->wait($run, 'old', [
            'kind' => 'timer',
            'history_authority' => 'mutable_open_fallback',
            'deadline_at' => now()
                ->subYear(),
        ]);
        $this->wait($run, 'closed', [
            'position' => 1,
            'resume_source_id' => 'closed',
        ]);
        $this->activity($run, 'closed', [
            'status' => 'completed',
        ]);

        $waits = RunCurrentWaits::forRun($run)['waits'];

        $this->assertSame('unavailable', $waits[0]['state']);
        $this->assertSame('wait_without_typed_history', $waits[0]['unavailable_reason']);
        $this->assertNull($waits[0]['next_scheduled_resume_at']);
        $this->assertSame('unavailable', $waits[1]['state']);
        $this->assertSame('activity_no_longer_open', $waits[1]['unavailable_reason']);
        $this->assertNull($waits[1]['deadline_at']);
    }

    public function testMissingPrunedAndTerminalWaitInformationRemainsDistinct(): void
    {
        $run = $this->createRun('missing');
        $missing = RunCurrentWaits::forRun($run);
        $this->assertSame('unavailable', $missing['state']);
        $this->assertNull($missing['total_count']);
        $this->assertSame('current_wait_projection_unavailable', $missing['unavailable_reason']);
        $this->wait($run, 'stale', [
            'kind' => 'timer',
        ]);
        $run->forceFill([
            'status' => 'completed',
        ])->save();
        $terminal = RunCurrentWaits::forRun($run);
        $this->assertSame('available', $terminal['state']);
        $this->assertSame(0, $terminal['total_count']);
        $this->assertSame([], $terminal['waits']);
        $run->forceFill([
            'details_pruned_at' => now(),
        ])->save();
        $pruned = RunCurrentWaits::forRun($run);
        $this->assertSame('pruned', $pruned['state']);
        $this->assertNull($pruned['total_count']);
        $this->assertSame([], $pruned['waits']);
    }

    public function testChildAndSignalTaskTimingRequiresAnExplicitDependencyMatch(): void
    {
        $run = $this->createRun('relationships');
        $now = Carbon::parse('2026-10-06T20:00:00Z');
        $this->wait($run, 'child:call', [
            'kind' => 'child',
            'resume_source_id' => 'child',
            'task_id' => 'child-task',
        ]);
        $this->wait($run, 'signal', [
            'kind' => 'signal',
            'position' => 1,
            'resume_source_kind' => 'timer',
            'resume_source_id' => 'signal-timeout',
            'task_id' => 'timeout-task',
            'deadline_at' => $now->copy()
                ->addMinutes(10),
        ]);
        $this->wait($run, 'wrong-child', [
            'kind' => 'child',
            'position' => 2,
            'resume_source_id' => 'different-child',
            'task_id' => 'child-task',
        ]);
        $this->task($run, 'child-task', [
            'task_type' => 'workflow',
            'available_at' => $now->copy()
                ->addMinutes(5),
            'payload' => [
                'child_call_id' => 'call',
                'child_workflow_run_id' => 'child',
            ],
        ]);
        $this->task($run, 'timeout-task', [
            'task_type' => 'timer',
            'available_at' => $now->copy()
                ->addMinutes(10),
            'payload' => [
                'timer_id' => 'signal-timeout',
            ],
        ]);

        $waits = RunCurrentWaits::forRun($run, now: $now)['waits'];

        $this->assertSame('planned', $waits[0]['state']);
        $this->assertSame('2026-10-06T20:05:00+00:00', $waits[0]['next_scheduled_resume_at']);
        $this->assertNull($waits[0]['attempt_limit']);
        $this->assertSame('planned', $waits[1]['state']);
        $this->assertSame('2026-10-06T20:10:00+00:00', $waits[1]['deadline_at']);
        $this->assertSame('resume_time_unknown', $waits[2]['state']);
        $this->assertNull($waits[2]['task_id']);
        $this->assertNull($waits[2]['next_scheduled_resume_at']);
    }

    public function testConfiguredWaitModelScopeIsPreserved(): void
    {
        $run = $this->createRun('configured');
        $this->wait($run, 'excluded', [
            'kind' => 'signal',
        ]);
        $this->wait($run, 'included', [
            'kind' => 'signal',
            'position' => 1,
        ]);
        config()
            ->set('workflows.v2.run_wait_model', ScopedCurrentWait::class);

        $snapshot = RunCurrentWaits::forRun($run);

        $this->assertSame(['included'], array_column($snapshot['waits'], 'id'));
        $this->assertFalse($snapshot['has_more']);
    }

    #[DataProvider('invalidLimits')]
    public function testInvalidLimitsFailBeforeAnyRead(int $limit): void
    {
        $run = $this->createRun('invalid');
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            RunCurrentWaits::forRun($run, $limit);
            $this->fail('An invalid limit was accepted.');
        } catch (InvalidArgumentException) {
            $this->assertSame([], DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
    }

    /**
     * @return list<array{int}>
     */
    public static function invalidLimits(): array
    {
        return [[0], [101]];
    }

    private function createRun(string $id, string $namespace = 'default'): WorkflowRun
    {
        WorkflowInstance::query()->create([
            'id' => 'instance-' . $id,
            'namespace' => $namespace,
            'workflow_class' => 'wait.proof',
            'workflow_type' => 'wait.proof',
            'run_count' => 1,
        ]);

        return WorkflowRun::query()->create([
            'id' => $id,
            'workflow_instance_id' => 'instance-' . $id,
            'namespace' => $namespace,
            'run_number' => 1,
            'workflow_class' => 'wait.proof',
            'workflow_type' => 'wait.proof',
            'status' => 'running',
            'connection' => 'database',
            'queue' => 'wait-proof',
        ]);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function wait(WorkflowRun $run, string $id, array $values = []): void
    {
        WorkflowRunWait::query()->create(array_replace([
            'id' => $run->id . '-' . $id,
            'workflow_run_id' => $run->id,
            'workflow_instance_id' => $run->workflow_instance_id,
            'wait_id' => $id,
            'position' => 0,
            'kind' => 'activity',
            'status' => 'open',
            'summary' => 'Waiting for ' . $id,
            'history_authority' => 'typed_history',
        ], $values));
    }

    /**
     * @param array<string, mixed> $values
     */
    private function activity(WorkflowRun $run, string $id, array $values = []): void
    {
        ActivityExecution::query()->create(array_replace([
            'id' => $id,
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'activity_class' => 'wait.activity',
            'activity_type' => 'wait.activity',
            'status' => 'pending',
        ], $values));
    }

    /**
     * @param array<string, mixed> $values
     */
    private function task(WorkflowRun $run, string $id, array $values = []): void
    {
        WorkflowTask::query()->create(array_replace([
            'id' => $id,
            'workflow_run_id' => $run->id,
            'namespace' => $run->namespace,
            'task_type' => 'activity',
            'status' => 'ready',
            'payload' => [],
        ], $values));
    }
}

final class ScopedCurrentWait extends WorkflowRunWait
{
    protected static function booted(): void
    {
        static::addGlobalScope('wait-fixture', static function ($query): void {
            $query->where('wait_id', '!=', 'excluded');
        });
    }
}
