<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\Fixtures\V2\RecordingExternalPayloadStorage;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Contracts\ExternalPayloadStoragePolicy;
use Workflow\V2\Contracts\WorkflowTaskBridge;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Enums\TimerStatus;
use Workflow\V2\Events\ActivityFailed;
use Workflow\V2\Events\FailureRecorded;
use Workflow\V2\Events\WorkflowCompleted;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimer;
use Workflow\V2\Support\ExternalPayloads;
use Workflow\V2\Support\HistoryTransactionMeter;
use Workflow\V2\Support\WorkflowCommandNormalizer;

final class DefaultWorkflowTaskBridgeLocalActivityTest extends TestCase
{
    public function testRecordLocalActivityReconstructsAttemptTimelineAndLifecycleSnapshots(): void
    {
        Carbon::setTestNow('2026-08-28T12:00:00.000000Z');

        try {
            $run = $this->createWaitingRun();
            $task = $this->createLeasedTask($run);
            $bridge = $this->app->make(WorkflowTaskBridge::class);

            $result = $bridge->complete($task->id, [[
                'type' => 'record_local_activity',
                'activity_type' => 'mixed-worker-local-activity',
                'result' => Serializer::serialize('completed'),
                'outcome' => 'completed',
                'attempts' => [
                    [
                        'attempt_id' => 'worker-a-attempt-1',
                        'attempt_number' => 1,
                        'outcome' => 'failed',
                        'duration_ms' => 4000,
                        'message' => 'retry on another worker',
                        'retry_reason' => 'failure',
                        'backoff_seconds' => 1,
                        'heartbeats' => [[
                            'elapsed_ms' => 1500,
                            'details' => [
                                'worker' => 'worker-a',
                            ],
                        ]],
                    ],
                    [
                        'attempt_id' => 'worker-b-attempt-1',
                        'attempt_number' => 2,
                        'outcome' => 'completed',
                        'duration_ms' => 2000,
                        'heartbeats' => [[
                            'elapsed_ms' => 500,
                            'details' => [
                                'worker' => 'worker-b',
                            ],
                        ]],
                    ],
                ],
                'retry_policy' => [
                    'max_attempts' => 2,
                    'backoff_seconds' => [1],
                ],
                'execution_mode' => 'local',
            ]]);

            $this->assertTrue($result['completed'], json_encode($result, JSON_THROW_ON_ERROR));

            $execution = ActivityExecution::query()
                ->where('workflow_run_id', $run->id)
                ->sole();
            $attempts = ActivityAttempt::query()
                ->where('activity_execution_id', $execution->id)
                ->orderBy('attempt_number')
                ->get();

            $expectedStartedAt = ['2026-08-28T11:59:53.000000Z', '2026-08-28T11:59:58.000000Z'];
            $expectedHeartbeatAt = ['2026-08-28T11:59:54.500000Z', '2026-08-28T11:59:58.500000Z'];
            $expectedClosedAt = ['2026-08-28T11:59:57.000000Z', '2026-08-28T12:00:00.000000Z'];

            $this->assertSame($expectedStartedAt, $attempts->pluck('started_at')
                ->map(static fn (Carbon $timestamp): string => $timestamp->toJSON())
                ->all());
            $this->assertSame($expectedHeartbeatAt, $attempts->pluck('last_heartbeat_at')
                ->map(static fn (Carbon $timestamp): string => $timestamp->toJSON())
                ->all());
            $this->assertSame($expectedClosedAt, $attempts->pluck('closed_at')
                ->map(static fn (Carbon $timestamp): string => $timestamp->toJSON())
                ->all());
            $this->assertSame($expectedStartedAt[0], $execution->started_at?->toJSON());
            $this->assertSame($expectedHeartbeatAt[1], $execution->last_heartbeat_at?->toJSON());
            $this->assertTrue($attempts[0]->closed_at?->copy()->addSecond()->equalTo($attempts[1]->started_at));

            $events = WorkflowHistoryEvent::query()
                ->where('workflow_run_id', $run->id)
                ->orderBy('sequence')
                ->get();

            $this->assertSame([
                HistoryEventType::ActivityScheduled,
                HistoryEventType::ActivityStarted,
                HistoryEventType::ActivityHeartbeatRecorded,
                HistoryEventType::ActivityRetryScheduled,
                HistoryEventType::ActivityStarted,
                HistoryEventType::ActivityHeartbeatRecorded,
                HistoryEventType::ActivityCompleted,
            ], $events->pluck('event_type')
                ->all());
            $this->assertSame([
                'pending',
                'running',
                'running',
                'pending',
                'running',
                'running',
                'completed',
            ], $events->pluck('payload')
                ->map(static fn (array $payload): mixed => $payload['activity']['status'] ?? null)
                ->all());
            $this->assertSame([
                null,
                'running',
                'running',
                'failed',
                'running',
                'running',
                'completed',
            ], $events->pluck('payload')
                ->map(static fn (array $payload): mixed => $payload['activity_attempt']['status'] ?? null)
                ->all());
            $this->assertSame([
                null,
                'worker-a-attempt-1',
                'worker-a-attempt-1',
                'worker-a-attempt-1',
                'worker-b-attempt-1',
                'worker-b-attempt-1',
                'worker-b-attempt-1',
            ], $events->pluck('payload')
                ->map(static fn (array $payload): mixed => $payload['worker_attempt_id'] ?? null)
                ->all());
            $this->assertSame($expectedHeartbeatAt, $events
                ->where('event_type', HistoryEventType::ActivityHeartbeatRecorded)
                ->pluck('payload')
                ->map(static fn (array $payload): mixed => $payload['heartbeat_at'] ?? null)
                ->values()
                ->all());

            $retry = $events->firstWhere('event_type', HistoryEventType::ActivityRetryScheduled);
            $terminal = $events->firstWhere('event_type', HistoryEventType::ActivityCompleted);

            $this->assertInstanceOf(WorkflowHistoryEvent::class, $retry);
            $this->assertInstanceOf(WorkflowHistoryEvent::class, $terminal);
            $this->assertSame($expectedHeartbeatAt[0], $retry->payload['activity']['last_heartbeat_at']);
            $this->assertSame(1, $retry->payload['retry_backoff_seconds']);
            $this->assertSame($expectedHeartbeatAt[1], $terminal->payload['activity']['last_heartbeat_at']);
            $this->assertSame($expectedClosedAt[1], $terminal->payload['activity_attempt']['closed_at']);
            $this->assertSame('worker-b-attempt-1', $terminal->payload['worker_attempt_id']);
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * Workflow #930: a completion whose history outgrows one transaction is
     * refused before anything is written, and the worker keeps its lease.
     */
    public function testAnOverBudgetCompletionWritesNothingAndKeepsTheLease(): void
    {
        config([
            'workflows.v2.structural_limits.history_transaction_size' => 6,
        ]);
        $run = $this->createWaitingRun();
        $task = $this->createLeasedTask($run);
        $bridge = $this->app->make(WorkflowTaskBridge::class);
        $command = static fn (int $heartbeats): array => [
            'type' => 'record_local_activity',
            'activity_type' => 'bounded-local-activity',
            'result' => Serializer::serialize('done'),
            'outcome' => 'completed',
            'attempts' => [[
                'attempt_number' => 1,
                'outcome' => 'completed',
                'heartbeats' => array_map(
                    static fn (int $elapsed): array => [
                        'elapsed_ms' => $elapsed,
                    ],
                    $heartbeats === 0 ? [] : range(1, $heartbeats)
                ),
            ]],
            'execution_mode' => 'local',
        ];
        $before = [$task->fresh()->status, $task->fresh()->lease_owner, $task->fresh()->lease_expires_at?->toJSON(),
            $run->fresh()
->status, $run->fresh()
->last_history_sequence];

        // The local activity records six events (scheduled, started, three
        // heartbeats, terminal) and the closing command at least one: seven.
        $refused = $bridge->complete($task->id, [
            $command(3), [
                'type' => 'complete_workflow',
                'result' => Serializer::serialize('x'),
            ]]);

        $this->assertFalse($refused['completed']);
        $this->assertSame('completion_budget_exceeded', $refused['reason']);
        $this->assertSame(0, ActivityExecution::query()->where('workflow_run_id', $run->id)->count());
        $this->assertSame(0, ActivityAttempt::query()->where('workflow_run_id', $run->id)->count());
        $this->assertSame(0, WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)->count());
        $this->assertSame($before, [$task->fresh()->status, $task->fresh()->lease_owner,
            $task->fresh()
                ->lease_expires_at?->toJSON(), $run->fresh()
->status, $run->fresh()
->last_history_sequence]);

        // The same lease completes a completion that fits.
        $accepted = $bridge->complete($task->id, [$command(3)]);
        $this->assertTrue($accepted['completed'], json_encode($accepted, JSON_THROW_ON_ERROR));
        $this->assertSame(6, WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)->count());
    }

    /**
     * Workflow #930: the longest declared durations and backoffs, across the
     * most attempts, still produce representable timestamps.
     */
    public function testTheLongestDeclaredTimingsRecordRepresentableTimestamps(): void
    {
        Carbon::setTestNow('2026-08-28T12:00:00.000000Z');

        try {
            $run = $this->createWaitingRun();
            $task = $this->createLeasedTask($run);
            $bridge = $this->app->make(WorkflowTaskBridge::class);
            $maximum = WorkflowCommandNormalizer::MAX_LOCAL_ACTIVITY_DURATION_MS;
            $backoff = WorkflowCommandNormalizer::MAX_LOCAL_ACTIVITY_BACKOFF_SECONDS;
            $count = WorkflowCommandNormalizer::MAX_LOCAL_ACTIVITY_ATTEMPTS;
            $attempts = [];
            for ($number = 1; $number <= $count; $number++) {
                $attempts[] = array_filter([
                    'attempt_number' => $number,
                    'outcome' => $number === $count ? 'completed' : 'failed',
                    'duration_ms' => $maximum,
                    'message' => $number === $count ? null : 'retry',
                    'retry_reason' => $number === $count ? null : 'failure',
                    'backoff_seconds' => $number === $count ? null : $backoff,
                    'heartbeats' => [[
                        'elapsed_ms' => $maximum,
                    ]],
                ], static fn (mixed $value): bool => $value !== null);
            }
            $result = $bridge->complete($task->id, [[
                'type' => 'record_local_activity',
                'activity_type' => 'longest-local-activity',
                'result' => Serializer::serialize('done'),
                'outcome' => 'completed',
                'attempts' => $attempts,
                'retry_policy' => [
                    'max_attempts' => $count,
                ],
                'execution_mode' => 'local',
            ]]);

            $this->assertTrue($result['completed'], json_encode($result, JSON_THROW_ON_ERROR));
            $first = ActivityAttempt::query()->where('workflow_run_id', $run->id)->where('attempt_number', 1)->sole();
            // 100 seven-day attempts and 99 one-day backoffs before completion.
            $this->assertSame(
                Carbon::parse('2026-08-28T12:00:00.000000Z')->subMilliseconds($maximum * $count)->subSeconds(
                    $backoff * ($count - 1)
                )->toJSON(),
                $first->started_at?->toJSON(),
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * Workflow #930: past the history byte limit the meter refuses the whole
     * completion; nothing is written and the same lease completes later.
     */
    public function testTheMeterRefusesPastTheHistoryByteLimitAndKeepsTheLease(): void
    {
        config([
            'workflows.v2.structural_limits.history_transaction_bytes' => 4000,
        ]);
        $run = $this->createWaitingRun();
        $task = $this->createLeasedTask($run);
        $bridge = $this->app->make(WorkflowTaskBridge::class);
        $before = $this->leaseOf($task);

        $refused = $bridge->complete($task->id, [$this->localActivity(5)]);

        $this->assertFalse($refused['completed']);
        $this->assertSame('history_transaction_limit_exceeded', $refused['reason']);
        $this->assertSame('history_transaction_bytes', $refused['limit']);
        $this->assertNothingRecorded($run);
        $this->assertSame($before, $this->leaseOf($task));

        config([
            'workflows.v2.structural_limits.history_transaction_bytes' => 0,
        ]);
        $accepted = $bridge->complete($task->id, [$this->localActivity(5)]);
        $this->assertTrue($accepted['completed'], json_encode($accepted, JSON_THROW_ON_ERROR));
        $this->assertSame(8, WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)->count());
    }

    /**
     * Workflow #930: a local activity's externalized payloads are staged and
     * stored only once the completion is admitted; a refused completion
     * stores nothing.
     */
    public function testExternalizedLocalActivityPayloadsAreStoredOnlyAfterAdmission(): void
    {
        $storage = new RecordingExternalPayloadStorage();
        $this->app->instance(ExternalPayloadStoragePolicy::class, $storage);
        $run = $this->createWaitingRun();
        $task = $this->createLeasedTask($run);
        $bridge = $this->app->make(WorkflowTaskBridge::class);
        $command = [
            ...$this->localActivity(0),
            'arguments' => Serializer::serialize([str_repeat('argument ', 20)]),
            'result' => Serializer::serialize(str_repeat('result ', 20)),
        ];

        config([
            // The meter refuses at the first event, after both payloads were staged.
            'workflows.v2.structural_limits.history_transaction_bytes' => 1000,
        ]);
        $refused = $bridge->complete($task->id, [$command]);
        $this->assertSame('history_transaction_limit_exceeded', $refused['reason']);
        $this->assertSame('history_transaction_bytes', $refused['limit']);
        $this->assertSame([], $storage->puts);
        $this->assertNothingRecorded($run);

        config([
            'workflows.v2.structural_limits.history_transaction_bytes' => null,
        ]);
        $accepted = $bridge->complete($task->id, [$command]);
        $this->assertTrue($accepted['completed'], json_encode($accepted, JSON_THROW_ON_ERROR));
        $this->assertSame(
            [hash('sha256', $command['arguments']), hash('sha256', $command['result'])],
            array_column($storage->puts, 'sha256')
        );
        $execution = ActivityExecution::query()->where('workflow_run_id', $run->id)->sole();
        $envelope = ExternalPayloads::storedEnvelope((string) $execution->getRawOriginal('arguments'));
        $this->assertSame(
            'memory://avro/' . hash('sha256', $command['arguments']),
            $envelope['external_storage']['uri'] ?? null
        );
    }

    /**
     * A driver that implements only the original interface keeps working: it
     * stores each payload when it is externalized, after the payload's raw
     * bytes are charged, so a refused completion stores at most that limit.
     */
    public function testADriverWithoutUriPredictionKeepsStoringWithinTheByteLimit(): void
    {
        $storage = new RecordingExternalPayloadStorage(predictable: false);
        $this->app->instance(ExternalPayloadStoragePolicy::class, $storage);
        $bridge = $this->app->make(WorkflowTaskBridge::class);
        $command = [
            ...$this->localActivity(0),
            'arguments' => Serializer::serialize([str_repeat('argument ', 20)]),
            'result' => Serializer::serialize(str_repeat('result ', 20)),
        ];

        $run = $this->createWaitingRun();
        $task = $this->createLeasedTask($run);
        $accepted = $bridge->complete($task->id, [$command]);
        $this->assertTrue($accepted['completed'], json_encode($accepted, JSON_THROW_ON_ERROR));
        $this->assertCount(2, $storage->puts);
        $execution = ActivityExecution::query()->where('workflow_run_id', $run->id)->sole();
        $uri = ExternalPayloads::storedEnvelope(
            (string) $execution->getRawOriginal('arguments')
        )['external_storage']['uri'] ?? null;
        $this->assertSame($command['arguments'], $storage->objects[$uri] ?? null);

        // Room for the arguments only: the result is refused before its put.
        config([
            'workflows.v2.structural_limits.external_payload_transaction_bytes' => strlen($command['arguments']),
        ]);
        $storage->puts = [];
        $otherRun = $this->createWaitingRun();
        $otherTask = $this->createLeasedTask($otherRun);
        $refused = $bridge->complete($otherTask->id, [$command]);
        $this->assertSame('history_transaction_limit_exceeded', $refused['reason']);
        $this->assertSame('external_payload_transaction_bytes', $refused['limit']);
        $this->assertSame([hash('sha256', $command['arguments'])], array_column($storage->puts, 'sha256'));
        $this->assertNothingRecorded($otherRun);
    }

    /**
     * Lifecycle events report committed state. A completion refused after a
     * local activity failed dispatches none; once accepted, each event is
     * dispatched exactly once.
     */
    public function testLifecycleEventsAreDispatchedOnlyAfterTheCompletionCommits(): void
    {
        Event::fake([ActivityFailed::class, FailureRecorded::class, WorkflowCompleted::class]);
        $run = $this->createWaitingRun();
        $task = $this->createLeasedTask($run);
        $bridge = $this->app->make(WorkflowTaskBridge::class);
        $before = $this->leaseOf($task);
        $commands = [$this->failedLocalActivity(), [
            'type' => 'complete_workflow',
            'result' => Serializer::serialize(str_repeat('large result ', 20_000)),
        ]];

        // The local activity's events fit; the completion's large result does not.
        config([
            'workflows.v2.structural_limits.history_transaction_bytes' => 100_000,
        ]);
        $refused = $bridge->complete($task->id, $commands);

        $this->assertSame('history_transaction_limit_exceeded', $refused['reason']);
        $this->assertSame('history_transaction_bytes', $refused['limit']);
        Event::assertNotDispatched(ActivityFailed::class);
        Event::assertNotDispatched(FailureRecorded::class);
        Event::assertNotDispatched(WorkflowCompleted::class);
        $this->assertNothingRecorded($run);
        $this->assertSame($before, $this->leaseOf($task));

        config([
            'workflows.v2.structural_limits.history_transaction_bytes' => null,
        ]);
        $accepted = $bridge->complete($task->id, $commands);

        $this->assertTrue($accepted['completed'], json_encode($accepted, JSON_THROW_ON_ERROR));
        Event::assertDispatchedTimes(ActivityFailed::class, 1);
        Event::assertDispatchedTimes(FailureRecorded::class, 1);
        Event::assertDispatchedTimes(WorkflowCompleted::class, 1);
    }

    public function testLifecycleListenersRunAfterTheCompletionTransactionCloses(): void
    {
        $levels = [];
        Event::listen(WorkflowCompleted::class, static function () use (&$levels): void {
            $levels[] = DB::transactionLevel();
        });
        $run = $this->createWaitingRun();
        $task = $this->createLeasedTask($run);

        $accepted = $this->app->make(WorkflowTaskBridge::class)->complete($task->id, [[
            'type' => 'complete_workflow',
            'result' => Serializer::serialize('done'),
        ]]);

        $this->assertTrue($accepted['completed'], json_encode($accepted, JSON_THROW_ON_ERROR));
        $this->assertSame([0], $levels);
    }

    /**
     * A staged payload whose put fails rolls the completion back after its
     * body dispatched lifecycle events; none of them is dispatched.
     */
    public function testAFailedStagedPutDispatchesNoLifecycleEvent(): void
    {
        Event::fake([WorkflowCompleted::class]);
        $storage = new RecordingExternalPayloadStorage();
        $storage->failPuts = true;
        $this->app->instance(ExternalPayloadStoragePolicy::class, $storage);
        $run = $this->createWaitingRun();
        $task = $this->createLeasedTask($run);
        $bridge = $this->app->make(WorkflowTaskBridge::class);
        $before = $this->leaseOf($task);
        $commands = [[
            'type' => 'complete_workflow',
            'result' => Serializer::serialize(str_repeat('externalized result ', 4)),
        ]];

        try {
            $bridge->complete($task->id, $commands);
            $this->fail('A failed staged put was accepted.');
        } catch (RuntimeException $error) {
            $this->assertSame('put failed', $error->getMessage());
        }

        Event::assertNotDispatched(WorkflowCompleted::class);
        $this->assertSame([], $storage->puts);
        $this->assertNothingRecorded($run);
        $this->assertSame($before, $this->leaseOf($task));
        $this->assertSame(RunStatus::Waiting, $run->fresh()->status);

        $storage->failPuts = false;
        $accepted = $bridge->complete($task->id, $commands);

        $this->assertTrue($accepted['completed'], json_encode($accepted, JSON_THROW_ON_ERROR));
        $this->assertCount(1, $storage->puts);
        Event::assertDispatchedTimes(WorkflowCompleted::class, 1);
    }

    /**
     * Workflow #930: the walk back from completion is checked against the
     * earliest storable timestamp before anything is written.
     */
    public function testAReportReachingBeforeTheEarliestStorableTimestampIsRefused(): void
    {
        Carbon::setTestNow('1970-01-02T00:00:00.000000Z');

        try {
            $run = $this->createWaitingRun();
            $task = $this->createLeasedTask($run);
            $command = $this->localActivity(0);
            $command['attempts'][0]['duration_ms'] = 2 * 86_400_000;

            $refused = $this->app->make(WorkflowTaskBridge::class)->complete($task->id, [$command]);

            $this->assertSame('local_activity_timestamp_out_of_range', $refused['reason']);
            $this->assertNothingRecorded($run);
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * Workflow #930: the meter charges exactly the rows a local activity
     * writes: each event and the run position it advances, the execution,
     * each attempt and the failure; the preflight counts the same rows.
     */
    public function testTheMeterChargesTheRowsALocalActivityWrites(): void
    {
        $run = $this->createWaitingRun();
        $task = $this->createLeasedTask($run);
        $command = [
            ...$this->localActivity(2),
            'outcome' => 'failed',
            'message' => 'gave up',
            'retry_policy' => [
                'max_attempts' => 2,
            ],
            'attempts' => [
                [
                    'attempt_number' => 1,
                    'outcome' => 'failed',
                    'message' => 'first',
                    'retry_reason' => 'failure',
                    'backoff_seconds' => 0,
                    'heartbeats' => [[
                        'elapsed_ms' => 1,
                    ], [
                        'elapsed_ms' => 2,
                    ]],
                ],
                [
                    'attempt_number' => 2,
                    'outcome' => 'failed',
                    'message' => 'gave up',
                    'heartbeats' => [],
                ],
            ],
        ];
        unset($command['result']);

        $result = $this->app->make(WorkflowTaskBridge::class)->complete($task->id, [$command]);

        $this->assertTrue($result['completed'], json_encode($result, JSON_THROW_ON_ERROR));
        $events = WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)->whereIn('event_type', [
            'ActivityScheduled', 'ActivityStarted', 'ActivityHeartbeatRecorded', 'ActivityRetryScheduled', 'ActivityFailed',
        ])->count();
        $normalized = WorkflowCommandNormalizer::normalize([$command], '1.19')[0];
        $this->assertSame(WorkflowCommandNormalizer::localActivityHistoryEvents($normalized), $events);
        $inserted = 1 + ActivityAttempt::query()->where('workflow_run_id', $run->id)->count()
            + \Workflow\V2\Models\WorkflowFailure::query()->where('workflow_run_id', $run->id)->count();
        $this->assertSame(WorkflowCommandNormalizer::localActivityRows($normalized), $inserted);
        $used = HistoryTransactionMeter::lastUsed();
        // The local activity's rows are part of what the meter charged.
        $this->assertGreaterThanOrEqual(2 * $events + $inserted, $used[HistoryTransactionMeter::ROWS]);
        $this->assertSame($used[HistoryTransactionMeter::EVENTS] * 2 + $inserted, $used[HistoryTransactionMeter::ROWS]);
    }

    /**
     * Workflow #930: a run's deadline close is not the worker's work and is
     * not metered, so a run whose close exceeds one completion's budget still
     * times out instead of refusing every completion.
     */
    public function testADeadlineCloseIsNotBlockedByTheCompletionBudget(): void
    {
        config([
            'workflows.v2.structural_limits.history_transaction_size' => 1,
        ]);
        $run = $this->createWaitingRun();
        $run->forceFill([
            'execution_deadline_at' => now()
                ->subMinute(),
        ])->save();
        foreach (range(1, 3) as $sequence) {
            WorkflowTimer::query()->create([
                'workflow_run_id' => $run->id,
                'sequence' => $sequence,
                'status' => TimerStatus::Pending->value,
                'delay_seconds' => 60,
                'fire_at' => now()
                    ->addMinute(),
            ]);
        }
        $task = $this->createLeasedTask($run);

        $result = $this->app->make(WorkflowTaskBridge::class)->complete($task->id, [$this->localActivity(0)]);

        $this->assertSame('run_timed_out', $result['reason'], json_encode($result, JSON_THROW_ON_ERROR));
        $this->assertSame(RunStatus::Failed, $run->fresh()->status);
        // Three timer cancellations and the timeout: more than the budget of one.
        $this->assertGreaterThan(1, WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)->count());
    }

    /**
     * @return array<string, mixed>
     */
    private function localActivity(int $heartbeats): array
    {
        return [
            'type' => 'record_local_activity',
            'activity_type' => 'metered-local-activity',
            'result' => Serializer::serialize('done'),
            'outcome' => 'completed',
            'attempts' => [[
                'attempt_number' => 1,
                'outcome' => 'completed',
                'heartbeats' => array_map(
                    static fn (int $elapsed): array => [
                        'elapsed_ms' => $elapsed,
                    ],
                    $heartbeats === 0 ? [] : range(1, $heartbeats)
                ),
            ]],
            'execution_mode' => 'local',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function failedLocalActivity(): array
    {
        $command = [
            ...$this->localActivity(0),
            'outcome' => 'failed',
            'message' => 'gave up',
        ];
        $command['attempts'][0] = [
            'attempt_number' => 1,
            'outcome' => 'failed',
            'message' => 'gave up',
            'heartbeats' => [],
        ];
        unset($command['result']);

        return $command;
    }

    /**
     * @return list<mixed>
     */
    private function leaseOf(WorkflowTask $task): array
    {
        $task = $task->fresh();

        return [$task->status, $task->lease_owner, $task->lease_expires_at?->toJSON(), $task->attempt_count];
    }

    private function assertNothingRecorded(WorkflowRun $run): void
    {
        $this->assertSame(0, ActivityExecution::query()->where('workflow_run_id', $run->id)->count());
        $this->assertSame(0, ActivityAttempt::query()->where('workflow_run_id', $run->id)->count());
        $this->assertSame(0, WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)->count());
        $this->assertSame(0, $run->fresh()->last_history_sequence ?? 0);
    }

    private function createWaitingRun(): WorkflowRun
    {
        $instance = WorkflowInstance::query()->create([
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'run_count' => 1,
            'reserved_at' => now()
                ->subMinute(),
            'started_at' => now()
                ->subMinute(),
        ]);
        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'status' => RunStatus::Waiting->value,
            'arguments' => Serializer::serialize(['Taylor']),
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
            'started_at' => now()
                ->subMinute(),
            'last_progress_at' => now()
                ->subSeconds(30),
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        return $run;
    }

    private function createLeasedTask(WorkflowRun $run): WorkflowTask
    {
        return WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Leased->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
            'lease_owner' => 'external-worker-1',
            'lease_expires_at' => now()
                ->addMinutes(5),
        ]);
    }
}
