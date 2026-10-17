<?php

declare(strict_types=1);

namespace Tests\Performance;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\ActivityCall;
use Workflow\V2\Support\ActivityReplayIdentity;
use Workflow\V2\WorkflowStub;

require_once __DIR__ . '/ProjectionRoleProbeTest.php';

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ActivityIdentityProbeTest extends TestCase
{
    public function testActivityIdentityCost(): void
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
        $workflow = WorkflowStub::make(ReplayGrowthWorkflow::class, 'activity-identity-cost');
        $workflow->start(9);
        for ($round = 0; $round <= 9; ++$round) {
            WorkflowStub::runReadyTasks();
            $this->travel(2)->seconds();
        }
        $workflow = WorkflowStub::load('activity-identity-cost');
        $this->assertTrue($workflow->completed());
        $this->assertSame(27, $workflow->output());
        $this->assertSame(27, $effects);
        $run = WorkflowRun::query()->findOrFail($workflow->runId());
        $originalDigest = self::historyDigest($run);
        $events = $run->historyEvents()->get();
        $this->assertCount(102, $events);
        $sequences = [];
        $types = [];
        foreach ($events as $event) {
            $types[(string) $event->id] = $event->event_type->value;
            if ($event->event_type === HistoryEventType::ActivityScheduled) {
                $sequence = $event->payload['sequence'] ?? null;
                $this->assertIsInt($sequence);
                $sequences[] = $sequence;
            }
        }
        $this->assertCount(27, $sequences);
        $this->assertCount(27, array_unique($sequences));

        $queries = 0;
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            ++$queries;
        });
        $originalModel = config('workflows.v2.history_event_model');
        $originalTiming = ProfiledHistoryEvent::$timePayloadCasts;
        $phases = [];
        try {
            foreach (['ordinary' => null, 'counts' => false, 'cast_timed' => true] as $mode => $timing) {
                $instrumented = $timing !== null;
                $modelClass = $instrumented ? ProfiledHistoryEvent::class : WorkflowHistoryEvent::class;
                config(['workflows.v2.history_event_model' => $modelClass]);
                $run->unsetRelation('historyEvents');
                $run->load('historyEvents');
                if ($instrumented) {
                    $this->assertInstanceOf(ProfiledHistoryEvent::class, $run->historyEvents->first());
                }
                ProfiledHistoryEvent::$timePayloadCasts = $timing === true;
                for ($sample = 0; $sample < 3; ++$sample) {
                    ProfiledHistoryEvent::$payloadReads = [];
                    ProfiledHistoryEvent::$payloadBytes = 0;
                    ProfiledHistoryEvent::$payloadCasts = 0;
                    ProfiledHistoryEvent::$payloadCastCpuMicroseconds = 0;
                    $beforeQueries = $queries;
                    $call = new ActivityCall(ReplayGrowthActivity::class, [1]);
                    $started = ProfiledHistoryRole::cpuMicroseconds();
                    foreach ($sequences as $sequence) {
                        ActivityReplayIdentity::assertCall($run, $call, $sequence);
                    }
                    $cpu = ProfiledHistoryRole::cpuMicroseconds() - $started;
                    $matchingQueries = $queries - $beforeQueries;
                    $this->assertSame(0, $matchingQueries);
                    $readsByType = [];
                    if ($instrumented) {
                        $this->assertNotEmpty(ProfiledHistoryEvent::$payloadReads);
                        $this->assertGreaterThan(0, ProfiledHistoryEvent::$payloadCasts);
                        foreach (ProfiledHistoryEvent::$payloadReads as $id => $count) {
                            $type = $types[$id];
                            $readsByType[$type] = ($readsByType[$type] ?? 0) + $count;
                        }
                        ksort($readsByType);
                    }
                    $castCpu = $timing === true ? ProfiledHistoryEvent::$payloadCastCpuMicroseconds : null;
                    $phases[$mode][] = [
                        'checks' => count($sequences),
                        'cpu_us' => $cpu,
                        'queries' => $matchingQueries,
                        'payload_reads' => $instrumented ? array_sum(ProfiledHistoryEvent::$payloadReads) : null,
                        'payload_casts' => $instrumented ? ProfiledHistoryEvent::$payloadCasts : null,
                        'payload_json_bytes_presented_to_cast' => $instrumented ? ProfiledHistoryEvent::$payloadBytes : null,
                        'payload_reads_by_type' => $instrumented ? $readsByType : null,
                        'payload_cast_cpu_us' => $castCpu,
                    ];
                }
            }
        } finally {
            config(['workflows.v2.history_event_model' => $originalModel]);
            ProfiledHistoryEvent::$timePayloadCasts = $originalTiming;
        }

        $this->assertSame($originalDigest, self::historyDigest($run));
        $this->assertSame(27, $effects);
        $tasks = WorkflowTask::query()->where('workflow_run_id', $run->id)->get();
        $this->assertCount(55, $tasks);
        foreach ($tasks as $task) {
            $this->assertSame('completed', $task->status->value);
            $this->assertSame(1, $task->attempt_count);
            $this->assertSame(0, $task->repair_count);
        }
        echo json_encode([
            'profile' => 'activity-identity-cost',
            'history_events' => 102,
            'activity_effects' => $effects,
            'tasks' => $tasks->count(),
            'phases' => $phases,
        ], JSON_THROW_ON_ERROR) . PHP_EOL;
    }

    private static function historyDigest(WorkflowRun $run): string
    {
        return hash('sha256', json_encode(
            $run->historyEvents()->toBase()->get()->all(),
            JSON_THROW_ON_ERROR
        ));
    }
}
