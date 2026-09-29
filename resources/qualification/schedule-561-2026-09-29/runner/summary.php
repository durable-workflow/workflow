<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$schedules = DB::table('workflow_schedules')->orderBy('schedule_id')
    ->get(['id', 'schedule_id', 'status', 'next_fire_at', 'fires_count', 'failures_count', 'skipped_trigger_count', 'last_skip_reason', 'buffered_actions']);
$history = DB::table('workflow_schedule_history_events')->orderBy('schedule_id')->orderBy('sequence')
    ->get(['schedule_id', 'sequence', 'event_type', 'occurrence_at_utc', 'workflow_instance_id', 'workflow_run_id', 'payload']);
$runs = DB::table('workflow_runs')->orderBy('workflow_instance_id')
    ->get(['id', 'workflow_instance_id', 'status']);

echo json_encode([
    'driver' => DB::connection()->getDriverName(),
    'schedules' => $schedules,
    'schedule_history' => $history,
    'runs' => $runs,
    'pending_queue_jobs' => DB::table('jobs')->count(),
    'failed_queue_jobs' => DB::table('failed_jobs')->count(),
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
