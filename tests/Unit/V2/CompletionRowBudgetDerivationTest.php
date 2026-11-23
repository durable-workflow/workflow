<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Contracts\WorkflowTaskBridge;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowMemo;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowSearchAttribute;
use Workflow\V2\Models\WorkflowSignal;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowUpdate;
use Workflow\V2\Support\CompletionRowEstimate;
use Workflow\V2\Support\HistoryTransactionMeter;
use Workflow\V2\Support\MemoPayload;
use Workflow\V2\Support\StructuralLimits;

/**
 * Workflow #1116: the default row limit never refuses a completion the event
 * limit admits. Each first-party family's rows per history event are
 * measured here; none exceeds CompletionRowEstimate::MAX_ROWS_PER_HISTORY_EVENT
 * (103: an upsert of a run's full 100 memos, or search attributes, records
 * one event), so 103 x 5,000 events + 5,000 = 520,000 rows by default.
 */
final class CompletionRowBudgetDerivationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function testTheDefaultCompletionRowLimitFollowsTheEventLimit(): void
    {
        config([
            'workflows.v2.structural_limits.completion_rows' => null,
        ]);
        $this->assertSame(520_000, StructuralLimits::DEFAULT_COMPLETION_ROWS);
        $this->assertSame(520_000, StructuralLimits::completionRowsLimit());
        // history_transaction_rows keeps its 2.29 key, meaning and default.
        $this->assertSame(20_000, StructuralLimits::DEFAULT_HISTORY_TRANSACTION_ROWS);

        config([
            'workflows.v2.structural_limits.history_transaction_size' => 2_000,
        ]);
        $this->assertSame(2_000 * 103 + 5_000, StructuralLimits::completionRowsLimit());

        config([
            'workflows.v2.structural_limits.history_transaction_size' => 0,
        ]);
        $this->assertSame(0, StructuralLimits::completionRowsLimit());
    }

    /**
     * The package config ships completion_rows unset, so publishing it does
     * not pin a number.
     */
    public function testThePublishedConfigLeavesTheCompletionRowLimitUnset(): void
    {
        $config = require dirname(__DIR__, 3) . '/src/config/workflows.php';

        $this->assertArrayHasKey('completion_rows', $config['v2']['structural_limits']);
        $this->assertNull($config['v2']['structural_limits']['completion_rows']);
        $this->assertSame(20_000, $config['v2']['structural_limits']['history_transaction_rows']);
    }

    /**
     * A config file published from 2.29.1 pins history_transaction_rows at
     * 20,000 and has no completion_rows. It behaves as it did on 2.29.1:
     * history_transaction_rows still counts two rows per event plus local
     * activity records, and the completion row limit stays derived. A
     * fan-out 2.29.1 admitted (1,000 children and 1,000 activities: at least
     * 2,000 events, 4,000 history rows, 25,003 completion rows) is admitted.
     */
    public function testAPublished2291ConfigAdmitsWhat2291Admitted(): void
    {
        config([
            'workflows.v2.structural_limits' => [
                ...config('workflows.v2.structural_limits'),
                'history_transaction_rows' => 20000,
            ],
        ]);
        config()
            ->offsetUnset('workflows.v2.structural_limits.completion_rows');
        $this->assertSame(20_000, StructuralLimits::historyTransactionRowsLimit());
        $this->assertSame(520_000, StructuralLimits::completionRowsLimit());

        $run = $this->createWaitingRun();
        $full = $this->fanOut(1_000);
        // Both early checks admit it, as 2.29.1's did.
        $this->assertSame([], \Workflow\V2\Support\WorkflowCommandNormalizer::completionBudgetErrors($full));
        $this->assertLessThanOrEqual(StructuralLimits::completionRowsLimit(), CompletionRowEstimate::rows($run, $full));

        $task = $this->createLeasedTask($run);
        $result = $this->app->make(WorkflowTaskBridge::class)->complete($task->id, $this->fanOut(100));

        $this->assertTrue($result['completed'], json_encode($result, JSON_THROW_ON_ERROR));
        $used = HistoryTransactionMeter::lastUsed();
        // history_transaction_rows: exactly the 2.29.1 charge, two per event.
        $this->assertSame(2 * $used[HistoryTransactionMeter::EVENTS], $used[HistoryTransactionMeter::ROWS]);
        $this->assertSame(800, $used[HistoryTransactionMeter::ROWS]);
        $this->assertSame(2_503, $used[HistoryTransactionMeter::COMPLETION_ROWS]);
    }

    /**
     * An explicit completion_rows is honored as is: below a completion's
     * rows it refuses before any write, at them it admits.
     */
    public function testAnExplicitCompletionRowLimitIsHonored(): void
    {
        config([
            'workflows.v2.structural_limits.completion_rows' => 7_000,
        ]);
        $this->assertSame(7_000, StructuralLimits::completionRowsLimit());
        config([
            'workflows.v2.structural_limits.completion_rows' => '0',
        ]);
        $this->assertSame(0, StructuralLimits::completionRowsLimit());

        config([
            'workflows.v2.structural_limits.completion_rows' => 2_502,
        ]);
        $run = $this->createWaitingRun();
        $task = $this->createLeasedTask($run);
        $refused = $this->app->make(WorkflowTaskBridge::class)->complete($task->id, $this->fanOut(100));
        $this->assertSame('completion_budget_exceeded', $refused['reason']);
        $this->assertSame(TaskStatus::Leased, $task->fresh()->status);

        config([
            'workflows.v2.structural_limits.completion_rows' => 2_503,
        ]);
        $admitted = $this->app->make(WorkflowTaskBridge::class)->complete($task->id, $this->fanOut(100));
        $this->assertTrue($admitted['completed'], json_encode($admitted, JSON_THROW_ON_ERROR));
        $this->assertSame(2_503, HistoryTransactionMeter::lastUsed()[HistoryTransactionMeter::COMPLETION_ROWS]);
    }

    /**
     * Rows per history event of one more command of each family, measured by
     * the meter, and the command estimate's figure for it.
     */
    public function testNoFirstPartyFamilyWritesMoreRowsPerEventThanTheDerivation(): void
    {
        config([
            'workflows.v2.structural_limits.completion_rows' => 0,
            'workflows.v2.structural_limits.history_transaction_size' => 0,
        ]);
        $ratios = [];
        foreach ($this->families() as $family => [$prepare, $commands]) {
            [$two, $twoEstimate] = $this->measure($prepare, $commands, 2);
            [$four, $fourEstimate] = $this->measure($prepare, $commands, 4);
            $rows = $four[HistoryTransactionMeter::COMPLETION_ROWS] - $two[HistoryTransactionMeter::COMPLETION_ROWS];
            $events = $four[HistoryTransactionMeter::EVENTS] - $two[HistoryTransactionMeter::EVENTS];
            $this->assertGreaterThan(0, $events, $family);
            $ratios[$family] = $rows / $events;
            $this->assertLessThanOrEqual(
                CompletionRowEstimate::MAX_ROWS_PER_HISTORY_EVENT,
                $ratios[$family],
                "{$family} writes more rows per event than the default row limit assumes."
            );
            if (! in_array(
                $family,
                ['accepted updates closed by fail_workflow', 'signals carried by continue_as_new'],
                true
            )) {
                // The command estimate never undercounts a command family.
                $this->assertGreaterThanOrEqual(
                    $four[HistoryTransactionMeter::COMPLETION_ROWS],
                    $fourEstimate,
                    $family
                );
                $this->assertGreaterThanOrEqual($two[HistoryTransactionMeter::COMPLETION_ROWS], $twoEstimate, $family);
            }
        }

        // The derivation's bound is reached: a full memo upsert.
        $this->assertEquals(CompletionRowEstimate::MAX_ROWS_PER_HISTORY_EVENT, max($ratios));
    }

    /**
     * A fan-out Workflow 2.29.1 admitted is still admitted with the default
     * limits: 1,000 children and 1,000 activities is 4,000 events and 25,003
     * completion rows. A smaller fan-out of the same shape is executed to show
     * the meter charges exactly the estimate, which is linear in the commands.
     * The full fan-out is not executed only because starting a thousand
     * children in one completion takes minutes on SQLite (the cost grows with
     * the square of the children, on the base revision too).
     */
    public function testA2291ShapedFanOutIsAdmittedWithTheDefaultLimits(): void
    {
        $run = $this->createWaitingRun();

        $full = CompletionRowEstimate::rows($run, $this->fanOut(1_000));
        $this->assertSame(3 + 1_000 * 19 + 1_000 * 6, $full);
        $this->assertLessThanOrEqual(StructuralLimits::completionRowsLimit(), $full);
        $this->assertSame(
            [],
            \Workflow\V2\Support\WorkflowCommandNormalizer::completionBudgetErrors($this->fanOut(1_000))
        );

        $task = $this->createLeasedTask($run);
        $result = $this->app->make(WorkflowTaskBridge::class)->complete($task->id, $this->fanOut(100));

        $this->assertTrue($result['completed'], json_encode($result, JSON_THROW_ON_ERROR));
        $used = HistoryTransactionMeter::lastUsed();
        $this->assertSame(400, $used[HistoryTransactionMeter::EVENTS]);
        $this->assertSame(3 + 100 * 19 + 100 * 6, $used[HistoryTransactionMeter::COMPLETION_ROWS]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fanOut(int $count): array
    {
        return [
            ...array_fill(0, $count, $this->childCommand()),
            ...array_fill(0, $count, $this->activityCommand()),
        ];
    }

    /**
     * @return array<string, array{0: callable(WorkflowRun, int): void, 1: callable(int): list<array<string, mixed>>}>
     */
    private function families(): array
    {
        $none = static function (WorkflowRun $run, int $count): void {
        };
        $many = static fn (array $command): callable => static fn (int $count): array => array_fill(
            0,
            $count,
            $command
        );

        return [
            'activity' => [$none, $many($this->activityCommand())],
            'timer' => [$none, $many([
                'type' => 'start_timer',
                'delay_seconds' => 60,
            ])],
            'child' => [$none, $many($this->childCommand())],
            'children sharing a parent start turn' => [
                $none, $many([
                    ...$this->childCommand(),
                    'await' => 'started',
                ])],
            'child inheriting 100 memos and 100 search attributes' => [
                function (WorkflowRun $run, int $count): void {
                    $this->seedFullMetadata($run);
                },
                $many($this->childCommand()),
            ],
            'side effect' => [$none, $many([
                'type' => 'record_side_effect',
                'result' => Serializer::serialize('side'),
            ])],
            'version marker' => [
                $none,
                static fn (int $count): array => array_map(static fn (int $index): array => [
                    'type' => 'record_version_marker',
                    'change_id' => 'change-' . $index,
                    'version' => 1,
                    'min_supported' => 1,
                    'max_supported' => 1,
                ], range(1, $count)),
            ],
            'memo upsert of 100 entries' => [$none, $many([
                'type' => 'upsert_memo',
                'entries' => MemoPayload::envelope($this->hundred('memo')),
            ])],
            'search attribute upsert of 100 attributes' => [$none, $many($this->fullSearchAttributeUpsert())],
            'local activity with a retry and heartbeats' => [$none, $many([
                'type' => 'record_local_activity',
                'activity_type' => 'derivation-local',
                'result' => Serializer::serialize('done'),
                'outcome' => 'completed',
                'attempts' => [[
                    'attempt_number' => 1,
                    'outcome' => 'failed',
                    'message' => 'retry',
                    'retry_reason' => 'failure',
                    'backoff_seconds' => 0,
                    'heartbeats' => [[
                        'elapsed_ms' => 1,
                    ], [
                        'elapsed_ms' => 2,
                    ]],
                ], [
                    'attempt_number' => 2,
                    'outcome' => 'completed',
                    'heartbeats' => [],
                ]],
                'retry_policy' => [
                    'max_attempts' => 2,
                ],
                'execution_mode' => 'local',
            ])],
            'accepted updates closed by fail_workflow' => [
                function (WorkflowRun $run, int $count): void {
                    foreach (range(1, $count) as $index) {
                        $this->acceptUpdate($run, $index);
                    }
                },
                static fn (int $count): array => [[
                    'type' => 'fail_workflow',
                    'message' => 'closed',
                    'exception_class' => \RuntimeException::class,
                ]],
            ],
            'signals carried by continue_as_new' => [
                function (WorkflowRun $run, int $count): void {
                    foreach (range(1, $count) as $index) {
                        $this->receiveSignal($run, $index);
                    }
                },
                static fn (int $count): array => [[
                    'type' => 'continue_as_new',
                    'arguments' => Serializer::serialize(['next']),
                ]],
            ],
        ];
    }

    /**
     * @param callable(WorkflowRun, int): void $prepare
     * @param callable(int): list<array<string, mixed>> $commands
     * @return array{0: array<string, int>, 1: int}
     */
    private function measure(callable $prepare, callable $commands, int $count): array
    {
        $run = $this->createWaitingRun();
        $prepare($run, $count);
        $task = $this->createLeasedTask($run);
        $list = $commands($count);
        $estimate = CompletionRowEstimate::rows($run, $list);

        $result = $this->app->make(WorkflowTaskBridge::class)->complete($task->id, $list);

        $this->assertTrue($result['completed'], json_encode($result, JSON_THROW_ON_ERROR));

        return [HistoryTransactionMeter::lastUsed(), $estimate];
    }

    private function seedFullMetadata(WorkflowRun $run): void
    {
        $task = $this->createLeasedTask($run);
        $result = $this->app->make(WorkflowTaskBridge::class)->complete($task->id, [[
            'type' => 'upsert_memo',
            'entries' => MemoPayload::envelope($this->hundred('memo')),
        ], $this->fullSearchAttributeUpsert()]);
        $this->assertTrue($result['completed'], json_encode($result, JSON_THROW_ON_ERROR));
        $this->assertSame(
            WorkflowMemo::MAX_MEMOS_PER_RUN,
            WorkflowMemo::query()->where('workflow_run_id', $run->id)->count()
        );
        $this->assertSame(
            WorkflowSearchAttribute::MAX_ATTRIBUTES_PER_RUN,
            WorkflowSearchAttribute::query()->where('workflow_run_id', $run->id)->count()
        );
        WorkflowTask::query()->where('workflow_run_id', $run->id)->delete();
        $run->forceFill([
            'status' => RunStatus::Waiting->value,
        ])->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function fullSearchAttributeUpsert(): array
    {
        $attributes = $this->hundred('attr');

        return [
            'type' => 'upsert_search_attributes',
            'attributes' => array_map(static fn (int $value): string => 'v' . $value, $attributes),
            'attribute_types' => array_map(static fn (): string => WorkflowSearchAttribute::TYPE_KEYWORD, $attributes),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function hundred(string $prefix): array
    {
        $entries = [];
        foreach (range(1, 100) as $index) {
            $entries[$prefix . '_' . $index] = $index;
        }

        return $entries;
    }

    /**
     * @return array<string, mixed>
     */
    private function childCommand(): array
    {
        return [
            'type' => 'start_child_workflow',
            'workflow_type' => 'test-greeting-workflow',
            'arguments' => Serializer::serialize(['child']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function activityCommand(): array
    {
        return [
            'type' => 'schedule_activity',
            'activity_type' => 'derivation-activity',
            'arguments' => Serializer::serialize(['a']),
        ];
    }

    private function acceptUpdate(WorkflowRun $run, int $index): void
    {
        $command = WorkflowCommand::record($run->instance, $run, [
            'command_type' => 'update',
            'target_scope' => 'instance',
            'payload_codec' => 'avro',
            'payload' => Serializer::serialize([
                'name' => 'update-' . $index,
            ]),
            'source' => 'api',
            'status' => 'accepted',
            'accepted_at' => now(),
        ]);
        WorkflowUpdate::query()->create([
            'workflow_command_id' => $command->id,
            'workflow_instance_id' => $run->workflow_instance_id,
            'workflow_run_id' => $run->id,
            'command_sequence' => $command->command_sequence,
            'update_name' => 'update-' . $index,
            'status' => 'accepted',
            'payload_codec' => 'avro',
            'arguments' => Serializer::serialize([]),
            'accepted_at' => now(),
        ]);
    }

    private function receiveSignal(WorkflowRun $run, int $index): void
    {
        $command = WorkflowCommand::record($run->instance, $run, [
            'command_type' => 'signal',
            'target_scope' => 'instance',
            'payload_codec' => 'avro',
            'payload' => Serializer::serialize([
                'name' => 'signal-' . $index,
            ]),
            'source' => 'api',
            'status' => 'accepted',
            'accepted_at' => now(),
        ]);
        WorkflowSignal::query()->create([
            'workflow_command_id' => $command->id,
            'workflow_instance_id' => $run->workflow_instance_id,
            'workflow_run_id' => $run->id,
            'command_sequence' => $command->command_sequence,
            'signal_name' => 'signal-' . $index,
            'status' => 'received',
            'payload_codec' => 'avro',
            'arguments' => Serializer::serialize([]),
            'received_at' => now(),
        ]);
    }

    private function createWaitingRun(): WorkflowRun
    {
        $instance = WorkflowInstance::query()->create([
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'run_count' => 1,
            'reserved_at' => now(),
            'started_at' => now(),
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
            'started_at' => now(),
            'last_progress_at' => now(),
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
            'available_at' => now(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
            'lease_owner' => 'derivation-worker',
            'lease_expires_at' => now()
                ->addMinutes(5),
        ]);
    }
}
