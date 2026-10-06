<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\DefaultOperatorObservabilityRepository;
use Workflow\V2\Support\RunObservation;

final class RunObservationTest extends TestCase
{
    public function testInitialObservationDoesNotReadOtherRunsOrHistoriesOrWriteProjections(): void
    {
        $run = $this->createRun('selected');
        WorkflowInstance::query()->whereKey($run->workflow_instance_id)->update([
            'current_run_id' => $run->id,
        ]);
        $started = WorkflowHistoryEvent::query()->create([
            'id' => 'started',
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'event_type' => 'WorkflowStarted',
            'recorded_at' => now(),
            'payload' => [
                'workflow_definition_fingerprint' => 'recorded-definition',
            ],
        ]);
        $history = [];
        $runs = [];
        for ($i = 2; $i <= 1002; ++$i) {
            $history[] = [
                'id' => 'history-' . $i,
                'workflow_run_id' => $run->id,
                'sequence' => $i,
                'event_type' => 'SignalReceived',
                'payload' => '{}',
                'recorded_at' => now(),
            ];
            $runs[] = [
                'id' => 'old-' . $i,
                'workflow_instance_id' => $run->workflow_instance_id,
                'namespace' => 'default',
                'run_number' => $i,
                'workflow_class' => 'observation.proof',
                'workflow_type' => 'observation.proof',
                'status' => 'completed',
                'connection' => 'database',
                'queue' => 'default',
            ];
        }
        foreach (array_chunk($history, 100) as $chunk) {
            DB::table('workflow_history_events')->insert($chunk);
        }
        foreach (array_chunk($runs, 100) as $chunk) {
            DB::table('workflow_runs')->insert($chunk);
        }
        $retrieved = [
            'history' => 0,
            'runs' => 0,
        ];
        WorkflowHistoryEvent::retrieved(static function () use (&$retrieved): void {
            ++$retrieved['history'];
        });
        WorkflowRun::retrieved(static function () use (&$retrieved): void {
            ++$retrieved['runs'];
        });
        DB::enableQueryLog();
        DB::flushQueryLog();
        $observation = (new DefaultOperatorObservabilityRepository())->runObservation($run);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame([
            'history' => 1,
            'runs' => 1,
        ], $retrieved);
        $this->assertCount(17, $queries);
        foreach ($queries as $query) {
            $this->assertStringStartsWith('select ', strtolower($query['query']));
        }
        $this->assertSame('selected', $observation['current_run_id']);
        $this->assertSame('observed', $observation['current_run_state']);
        $this->assertSame('not_evaluated', $observation['current_run_audit']);
        $this->assertSame('not_evaluated', $observation['history_audit']);
        $this->assertSame('unavailable', $observation['summary_state']);
        $this->assertNull($observation['exception_count']);
        $this->assertNull($observation['history_event_count']);
        $this->assertSame('recorded-definition', $observation['workflow_definition_fingerprint']);
        $this->assertSame('unavailable', $observation['command_contract']['source']);
        $this->assertArrayNotHasKey('arguments', $observation);
        $this->assertArrayNotHasKey('output', $observation);
        $this->assertArrayNotHasKey('memo', $observation);
        $this->assertSame([], $run->getRelations());
        $this->assertSame($started->payload, $started->fresh()->payload);
        $this->assertSame(0, DB::table('workflow_run_summaries')->count());
    }

    public function testWrongNamespaceAndWrongInstancePointersStayUnknownWithoutGuessingLatestRun(): void
    {
        $run = $this->createRun('selected');
        $other = $this->createRun('other', 'other-namespace');
        foreach ([$other->id, 'missing'] as $pointer) {
            WorkflowInstance::query()->whereKey($run->workflow_instance_id)->update([
                'current_run_id' => $pointer,
            ]);
            $observation = RunObservation::forRun($run);
            $this->assertSame('unavailable', $observation['current_run_state']);
            $this->assertNull($observation['current_run_id']);
            $this->assertNull($observation['is_current_run']);
            $this->assertSame(
                $pointer,
                DB::table('workflow_instances')->where('id', $run->workflow_instance_id)->value('current_run_id')
            );
        }
        $other->forceFill([
            'namespace' => 'default',
        ])->save();
        WorkflowInstance::query()->whereKey($run->workflow_instance_id)->update([
            'current_run_id' => $other->id,
        ]);
        $this->assertNull(RunObservation::forRun($run)['current_run_id']);
    }

    public function testRetainedCommandContractUsesOnlyTheFirstStartupEventAndLeavesExistingRelationsUntouched(): void
    {
        $run = $this->createRun('contract');
        $payload = [
            'declared_queries' => ['progress'],
            'declared_query_contracts' => [[
                'name' => 'progress',
                'parameters' => [],
            ]],
            'declared_signals' => ['approve'],
            'declared_signal_contracts' => [[
                'name' => 'approve',
                'parameters' => [],
            ]],
            'declared_updates' => [],
            'declared_update_contracts' => [],
            'declared_entry_method' => 'handle',
            'declared_entry_mode' => 'canonical',
            'declared_entry_declaring_class' => 'RetainedWorkflowDefinition',
        ];
        WorkflowHistoryEvent::query()->create([
            'id' => 'contract-started',
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'event_type' => 'WorkflowStarted',
            'payload' => $payload,
            'recorded_at' => now(),
        ]);
        $run->setRelation('historyEvents', $run->newCollection());
        $original = $run->getRelations();

        $observation = RunObservation::forRun($run);

        $this->assertSame('durable_history', $observation['command_contract']['source']);
        $this->assertSame(['progress'], $observation['command_contract']['queries']);
        $this->assertSame(['approve'], $observation['command_contract']['signals']);
        $this->assertFalse($observation['command_contract']['backfill_needed']);
        $this->assertSame($original, $run->getRelations());
    }

    public function testConfiguredInstanceScopeAndEagerLoadsDoNotExpandTheObservation(): void
    {
        $run = $this->createRun('selected');
        WorkflowInstance::query()->whereKey($run->workflow_instance_id)->update([
            'current_run_id' => $run->id,
        ]);
        config()
            ->set('workflows.v2.instance_model', ScopedObservationInstance::class);
        $observation = RunObservation::forRun($run);
        $this->assertNull($observation['current_run_id']);
        $this->assertSame('unavailable', $observation['current_run_state']);
        $this->assertSame([], $run->getRelations());
    }

    private function createRun(string $id, string $namespace = 'default'): WorkflowRun
    {
        WorkflowInstance::query()->create([
            'id' => 'instance-' . $id,
            'namespace' => $namespace,
            'workflow_class' => 'observation.proof',
            'workflow_type' => 'observation.proof',
            'run_count' => 1,
        ]);

        return WorkflowRun::query()->create([
            'id' => $id,
            'workflow_instance_id' => 'instance-' . $id,
            'namespace' => $namespace,
            'run_number' => 1,
            'workflow_class' => 'observation.proof',
            'workflow_type' => 'observation.proof',
            'status' => 'running',
            'connection' => 'database',
            'queue' => 'default',
        ]);
    }
}

final class ScopedObservationInstance extends WorkflowInstance
{
    protected $with = ['runs.historyEvents'];

    protected static function booted(): void
    {
        static::addGlobalScope('observation-fixture', static function ($query): void {
            $query->where('id', '!=', 'instance-selected');
        });
    }
}
