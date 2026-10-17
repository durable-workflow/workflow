<?php

declare(strict_types=1);

namespace Tests\Performance;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\TestCase;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\WorkflowFiberRunner;
use Workflow\V2\WorkflowStub;

require_once __DIR__ . '/ProjectionRoleProbeTest.php';

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class BridgePayloadProbeTest extends TestCase
{
    private ?ReplayGrowthWorkflow $resolvedWorkflow = null;

    public function testColdBridgePayloadAccess(): void
    {
        if (getenv('PROFILE_REPLAY_GROWTH') !== '1') {
            $this->markTestSkipped('Explicit profiling run only.');
        }

        Queue::fake();
        $this->freezeTime();
        $effects = 0;
        WorkflowStub::mock(ReplayGrowthActivity::class, static function ($context, int $value) use (&$effects): int {
            ++$effects;

            return $value;
        });
        $workflow = WorkflowStub::make(ReplayGrowthWorkflow::class, 'bridge-payload-cost');
        $workflow->start(9);
        for ($round = 0; $round <= 9; ++$round) {
            WorkflowStub::runReadyTasks();
            $this->travel(2)
                ->seconds();
        }
        $workflow = WorkflowStub::load('bridge-payload-cost');
        $this->assertTrue($workflow->completed());
        $this->assertSame(27, $workflow->output());
        $this->assertSame(27, $effects);
        $run = WorkflowRun::query()->findOrFail($workflow->runId());
        $storedDigest = self::storedDigest($run);
        $history = $run->historyEvents()
            ->get()
            ->map(static fn ($event): array => [
                'id' => $event->id,
                'sequence' => (int) $event->sequence,
                'event_type' => $event->event_type->value,
                'namespace' => $run->namespace,
                'payload' => $event->payload,
                'workflow_task_id' => $event->workflow_task_id,
                'workflow_command_id' => $event->workflow_command_id,
                'recorded_at' => $event->recorded_at?->toJSON(),
            ])->all();
        $this->assertCount(102, $history);
        $historyDigest = hash('sha256', json_encode($history, JSON_THROW_ON_ERROR));
        $queries = 0;
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            ++$queries;
        });
        $this->app->resolving(ReplayGrowthWorkflow::class, function (ReplayGrowthWorkflow $resolved): void {
            $this->resolvedWorkflow = $resolved;
        });
        $originalTiming = ProfiledHistoryEvent::$timePayloadCasts;
        $phases = [];
        $expectedCommands = null;
        $modes = getenv('PROFILE_REPLAY_ORDINARY_ONLY') === '1' ? [
            'ordinary' => null,
        ] : [
            'ordinary' => null,
            'counts' => false,
            'cast_timed' => true,
        ];
        try {
            foreach ($modes as $mode => $timing) {
                for ($sample = 0; $sample < 3; ++$sample) {
                    $measurement = $this->replay($run, $history, $timing, $queries);
                    $expectedCommands ??= $measurement['commands'];
                    $this->assertSame($expectedCommands, $measurement['commands']);
                    unset($measurement['commands']);
                    $phases[$mode][] = $measurement;
                }
            }
        } finally {
            ProfiledHistoryEvent::$timePayloadCasts = $originalTiming;
        }

        $this->assertSame($historyDigest, hash('sha256', json_encode($history, JSON_THROW_ON_ERROR)));
        $this->assertSame($storedDigest, self::storedDigest($run));
        $this->assertSame(27, $effects);
        $tasks = WorkflowTask::query()->where('workflow_run_id', $run->id)->get();
        $this->assertCount(55, $tasks);
        foreach ($tasks as $task) {
            $this->assertSame('completed', $task->status->value);
            $this->assertSame(1, $task->attempt_count);
            $this->assertSame(0, $task->repair_count);
        }
        echo json_encode([
            'profile' => 'bridge-payload-cost',
            'history_events' => count($history),
            'activity_effects' => $effects,
            'tasks' => $tasks->count(),
            'phases' => $phases,
        ], JSON_THROW_ON_ERROR) . PHP_EOL;
    }

    /**
     * @param list<array<string, mixed>> $history
     * @return array<string, mixed>
     */
    private function replay(WorkflowRun $sourceRun, array $history, ?bool $timing, int &$queries): array
    {
        $this->resolvedWorkflow = null;
        $beforeQueries = $queries;
        $started = ProfiledHistoryRole::cpuMicroseconds();
        $runner = WorkflowFiberRunner::forClass(
            ReplayGrowthWorkflow::class,
            $sourceRun->workflow_instance_id,
            $sourceRun->id,
            [9],
            historyEvents: $history,
            namespace: $sourceRun->namespace,
        );
        $initializationCpu = ProfiledHistoryRole::cpuMicroseconds() - $started;
        $initializationQueries = $queries - $beforeQueries;
        $this->assertSame(0, $initializationQueries);
        $this->assertInstanceOf(ReplayGrowthWorkflow::class, $this->resolvedWorkflow);
        $transientRun = $this->resolvedWorkflow->run;
        $events = $transientRun->historyEvents;
        $this->assertCount(102, $events);
        $types = [];
        foreach ($events as $event) {
            $types[(string) $event->id] = $event->event_type->value;
        }
        if ($timing !== null) {
            $transientRun->setRelation('historyEvents', $events->map(function ($event): ProfiledHistoryEvent {
                $profiled = new ProfiledHistoryEvent();
                $profiled->setConnection($event->getConnectionName());
                $profiled->setTable($event->getTable());
                $profiled->setRawAttributes($event->getAttributes());
                $profiled->setRelations($event->getRelations());
                $profiled->exists = $event->exists;
                $profiled->wasRecentlyCreated = $event->wasRecentlyCreated;
                $this->assertSame($event->getAttributes(), $profiled->getAttributes());
                $this->assertSame($event->getRawOriginal(), $profiled->getRawOriginal());
                $this->assertSame($event->getCasts(), $profiled->getCasts());

                return $profiled;
            }));
        }
        ProfiledHistoryEvent::$timePayloadCasts = $timing === true;
        ProfiledHistoryEvent::$payloadReads = [];
        ProfiledHistoryEvent::$payloadBytes = 0;
        ProfiledHistoryEvent::$payloadCasts = 0;
        ProfiledHistoryEvent::$payloadCastCpuMicroseconds = 0;
        ReplayGrowthWorkflow::$entries = 0;
        ReplayGrowthWorkflow::$roundVisits = 0;
        $beforeQueries = $queries;
        $started = ProfiledHistoryRole::cpuMicroseconds();
        $step = $runner->step();
        $stepCpu = ProfiledHistoryRole::cpuMicroseconds() - $started;
        $stepQueries = $queries - $beforeQueries;
        $this->assertSame(0, $stepQueries);
        $this->assertTrue($step->completed);
        $this->assertSame(27, $step->result);
        $this->assertCount(1, $step->commands);
        $this->assertSame('complete_workflow', $step->commands[0]['type']);
        $this->assertSame(1, ReplayGrowthWorkflow::$entries);
        $this->assertSame(9, ReplayGrowthWorkflow::$roundVisits);
        $readsByType = [];
        if ($timing !== null) {
            $this->assertNotEmpty(ProfiledHistoryEvent::$payloadReads);
            foreach (ProfiledHistoryEvent::$payloadReads as $id => $count) {
                $type = $types[$id];
                $readsByType[$type] = ($readsByType[$type] ?? 0) + $count;
            }
            ksort($readsByType);
        }

        return [
            'initialization_cpu_us' => $initializationCpu,
            'initialization_queries' => $initializationQueries,
            'step_cpu_us' => $stepCpu,
            'step_queries' => $stepQueries,
            'workflow_entries' => ReplayGrowthWorkflow::$entries,
            'round_visits' => ReplayGrowthWorkflow::$roundVisits,
            'command_count' => count($step->commands),
            'payload_reads' => $timing !== null ? array_sum(ProfiledHistoryEvent::$payloadReads) : null,
            'payload_casts' => $timing !== null ? ProfiledHistoryEvent::$payloadCasts : null,
            'payload_json_bytes_presented_to_cast' => $timing !== null ? ProfiledHistoryEvent::$payloadBytes : null,
            'payload_cast_cpu_us' => $timing === true ? ProfiledHistoryEvent::$payloadCastCpuMicroseconds : null,
            'payload_reads_by_type' => $timing !== null ? $readsByType : null,
            'commands' => $step->commands,
        ];
    }

    private static function storedDigest(WorkflowRun $run): string
    {
        $rows = $run->historyEvents()
            ->toBase()
            ->get()
            ->all();

        return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
    }
}
