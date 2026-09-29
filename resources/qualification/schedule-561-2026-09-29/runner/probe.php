<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Workflows\ProbeWorkflow;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Workflow\V2\Enums\ScheduleOverlapPolicy;
use Workflow\V2\Models\WorkflowSchedule;
use Workflow\V2\Support\ScheduleManager;

[$script, $case, $phase] = $argv;
$cases = [
    'ny_gap' => ['America/New_York', '30 2 * * *', '2026-03-08T06:59:00Z', ['2026-03-08T07:30:00Z']],
    'ny_fold' => ['America/New_York', '30 1 * * *', '2026-11-01T05:29:00Z', ['2026-11-01T05:30:00Z', '2026-11-01T06:30:00Z']],
    'kyiv_gap' => ['Europe/Kyiv', '30 3 * * *', '2026-03-29T00:59:00Z', ['2026-03-29T01:30:00Z']],
    'kyiv_fold' => ['Europe/Kyiv', '11 3 * * *', '2026-10-25T00:10:00Z', ['2026-10-25T00:11:00Z', '2026-10-25T01:11:00Z']],
    'manual_race' => ['UTC', '* * * * *', '2026-04-14T00:59:00Z', ['2026-04-14T01:00:00Z']],
    'manual_concurrent' => ['UTC', '* * * * *', '2026-04-14T00:59:00Z', ['2026-04-14T01:00:00Z']],
    'concurrent' => ['UTC', '* * * * *', '2026-04-14T00:59:00Z', ['2026-04-14T01:00:00Z']],
    'backfill_collision' => ['UTC', '* * * * *', '2026-04-14T00:59:00Z', ['2026-04-14T01:00:00Z']],
];
if (! isset($cases[$case])) {
    throw new InvalidArgumentException($case);
}
[$zone, $cron, $initAt, $due] = $cases[$case];
$id = 'schedule-561-' . $case;
if ($phase === 'init') {
    Carbon::setTestNowAndTimezone(Carbon::parse($initAt)->setTimezone('UTC'), 'UTC');
    $schedule = ScheduleManager::create(
        scheduleId: $id,
        workflowClass: ProbeWorkflow::class,
        cronExpression: $cron,
        timezone: $zone,
        overlapPolicy: ScheduleOverlapPolicy::AllowAll,
        maxRuns: in_array($case, ['manual_race', 'manual_concurrent', 'backfill_collision'], true) ? 3 : count($due),
    );
    $results = [];
} elseif ($phase === 'status') {
    $results = [];
    $schedule = WorkflowSchedule::query()->where('schedule_id', $id)->firstOrFail();
} elseif ($phase === 'manual' || $phase === 'stale' || $phase === 'backfill') {
    Carbon::setTestNowAndTimezone(Carbon::parse($due[0])->setTimezone('UTC'), 'UTC');
    $schedule = WorkflowSchedule::query()->where('schedule_id', $id)->firstOrFail();
    if ($phase === 'manual') {
        $detail = ScheduleManager::triggerDetailed($schedule);
        $results = ['manual' => ['outcome' => $detail->outcome, 'instance_id' => $detail->instanceId, 'reason' => $detail->reason]];
    } elseif ($phase === 'stale') {
        $detail = ScheduleManager::triggerDetailed($schedule, occurrenceTime: Carbon::parse($due[0])->setTimezone('UTC'));
        $results = ['stale' => ['outcome' => $detail->outcome, 'instance_id' => $detail->instanceId, 'reason' => $detail->reason], 'tick' => ScheduleManager::tick()];
    } else {
        $results = ScheduleManager::backfill($schedule, Carbon::parse($due[0]), Carbon::parse($due[0])->addMinute());
    }
    $schedule->refresh();
} else {
    $index = (int) substr($phase, -1) - 1;
    if (! isset($due[$index])) {
        throw new InvalidArgumentException($phase);
    }
    Carbon::setTestNowAndTimezone(Carbon::parse($due[$index])->setTimezone('UTC'), 'UTC');
    $results = ScheduleManager::tick();
    $schedule = WorkflowSchedule::query()->where('schedule_id', $id)->firstOrFail();
}

$instanceIds = DB::table('workflow_schedule_history_events')->where('schedule_id', $id)
    ->whereNotNull('workflow_instance_id')->pluck('workflow_instance_id')->all();
$instances = DB::table('workflow_instances')->whereIn('id', $instanceIds)
    ->get(['id', 'current_run_id'])->toArray();
$runs = DB::table('workflow_runs')->whereIn('workflow_instance_id', $instanceIds)
    ->get(['id', 'workflow_instance_id', 'status'])->toArray();
$history = DB::table('workflow_schedule_history_events')->where('schedule_id', $id)
    ->orderBy('sequence')->get(['sequence', 'event_type', 'workflow_instance_id', 'workflow_run_id'])->toArray();

echo json_encode([
    'case' => $case,
    'phase' => $phase,
    'time_utc' => now()->utc()->toIso8601String(),
    'expected_due_utc' => $due,
    'next_fire_utc' => $schedule->next_fire_at?->utc()->toIso8601String(),
    'schedule_status' => $schedule->status?->value,
    'fires_count' => $schedule->fires_count,
    'failures_count' => $schedule->failures_count,
    'skipped_trigger_count' => $schedule->skipped_trigger_count,
    'last_skip_reason' => $schedule->last_skip_reason,
    'results' => $results,
    'instances' => $instances,
    'runs' => $runs,
    'history' => $history,
    'pending_queue_jobs' => DB::table('jobs')->count(),
    'failed_queue_jobs' => DB::table('failed_jobs')->count(),
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
