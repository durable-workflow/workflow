<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\V2\TestBufferedSignalHistoryWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\SignalStatus;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowSignal;
use Workflow\V2\WorkflowStub;

final class V2SignalAdmissionProfileTest extends TestCase
{
    public function testProfileBufferedSignalAdmission(): void
    {
        Queue::fake();
        $workflow = WorkflowStub::make(TestBufferedSignalHistoryWorkflow::class, 'profile-buffered-signals');
        $workflow->start(200);
        $queryCount = 0;
        DB::listen(static function () use (&$queryCount): void {
            $queryCount++;
        });
        $latencies = [];
        $queries = [];
        $cpuStart = getrusage();

        for ($index = 0; $index < 200; $index++) {
            $beforeQueries = $queryCount;
            $start = hrtime(true);
            $result = $workflow->signal('append', (string) $index);
            $latencies[] = (hrtime(true) - $start) / 1000000;
            $queries[] = $queryCount - $beforeQueries;
            self::assertTrue($result->accepted());
        }

        $cpuEnd = getrusage();

        $count = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('event_type', HistoryEventType::SignalReceived->value)
            ->count();
        self::assertSame(200, $count);
        $pending = WorkflowSignal::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('status', SignalStatus::Received->value)
            ->count();
        self::assertSame(200, $pending);

        $window = static function (array $values): array {
            sort($values);
            $count = count($values);

            return [
                'mean' => round(array_sum($values) / $count, 3),
                'p50' => round($values[(int) floor(($count - 1) * 0.50)], 3),
                'p95' => round($values[(int) floor(($count - 1) * 0.95)], 3),
                'max' => round($values[$count - 1], 3),
            ];
        };
        fwrite(STDERR, json_encode([
            'first_50_ms' => $window(array_slice($latencies, 0, 50)),
            'last_50_ms' => $window(array_slice($latencies, -50)),
            'first_50_queries' => $window(array_slice($queries, 0, 50)),
            'last_50_queries' => $window(array_slice($queries, -50)),
            'total_seconds' => round(array_sum($latencies) / 1000, 3),
            'cpu_seconds' => round(
                ($cpuEnd['ru_utime.tv_sec'] - $cpuStart['ru_utime.tv_sec'])
                + ($cpuEnd['ru_utime.tv_usec'] - $cpuStart['ru_utime.tv_usec']) / 1000000
                + ($cpuEnd['ru_stime.tv_sec'] - $cpuStart['ru_stime.tv_sec'])
                + ($cpuEnd['ru_stime.tv_usec'] - $cpuStart['ru_stime.tv_usec']) / 1000000,
                3,
            ),
            'peak_memory_mib' => round(memory_get_peak_usage(true) / 1048576, 2),
            'received_events' => $count,
            'pending_signals' => $pending,
        ], JSON_THROW_ON_ERROR) . PHP_EOL);
    }
}
