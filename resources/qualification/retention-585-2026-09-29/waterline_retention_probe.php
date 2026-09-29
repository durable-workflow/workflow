<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Waterline\Http\Resources\V2StoredWorkflowResource;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Support\WorkflowRunRetentionCleanup;

config()->set('app.timezone', 'Europe/Kyiv');
config()->set('waterline.engine_source', 'v2');
date_default_timezone_set('Europe/Kyiv');

$output = [];
foreach (['winter' => '2026-01-15T12:00:00Z', 'summer' => '2026-07-15T12:00:00Z'] as $season => $instant) {
    $expected = Carbon::parse($instant, 'UTC');
    Carbon::setTestNow($expected);
    $status = $season === 'winter' ? 'completed' : 'failed';

    $instance = WorkflowInstance::query()->create([
        'id' => (string) Str::ulid(),
        'workflow_class' => 'App\\Workflows\\ProbeWorkflow',
        'workflow_type' => 'workflow.retention.probe',
        'namespace' => 'default',
        'run_count' => 1,
    ]);
    $run = WorkflowRun::query()->create([
        'id' => (string) Str::ulid(),
        'workflow_instance_id' => $instance->id,
        'run_number' => 1,
        'workflow_class' => $instance->workflow_class,
        'workflow_type' => $instance->workflow_type,
        'namespace' => 'default',
        'status' => $status,
        'connection' => 'sync',
        'queue' => 'default',
        'started_at' => $expected->copy()->subHour(),
        'closed_at' => $expected->copy()->subMinutes(2),
        'archived_at' => $expected->copy()->subMinute(),
        'last_progress_at' => $expected->copy()->subMinutes(2),
    ]);
    $instance->forceFill(['current_run_id' => $run->id])->save();
    WorkflowRunSummary::query()->create([
        'id' => $run->id,
        'workflow_instance_id' => $instance->id,
        'run_number' => 1,
        'is_current_run' => true,
        'engine_source' => 'v2',
        'class' => $run->workflow_class,
        'workflow_type' => $run->workflow_type,
        'namespace' => 'default',
        'status' => $status,
        'status_bucket' => $status,
        'connection' => 'sync',
        'queue' => 'default',
        'started_at' => $run->started_at,
        'closed_at' => $run->closed_at,
        'archived_at' => $run->archived_at,
    ]);

    WorkflowRunRetentionCleanup::pruneRun($run->id);
    $hydrated = WorkflowRun::query()->findOrFail($run->id);
    $detail = (new V2StoredWorkflowResource($hydrated))->toArray(Request::create('/waterline/api/instances/' . $instance->id . '/runs/' . $run->id));
    $raw = DB::table('workflow_runs')->where('id', $run->id)->value('details_pruned_at');
    $rawArchived = DB::table('workflow_runs')->where('id', $run->id)->value('archived_at');
    $displayed = is_string($detail['details_pruned_at'] ?? null)
        ? str_replace(['T', 'Z'], [' ', ''], $detail['details_pruned_at'])
        : null;

    $output[$season] = [
        'expected_utc' => $instant,
        'raw_utc' => $raw,
        'hydrated_utc' => $hydrated->details_pruned_at?->utc()->toIso8601String(),
        'serialized' => $detail['details_pruned_at'] ?? null,
        'notice_timestamp' => $displayed,
        'status' => $detail['status'] ?? null,
        'archived_at' => $detail['archived_at'] ?? null,
        'raw_archived_at' => $rawArchived,
        'archive_timestamp_matches' => Carbon::parse((string) ($detail['archived_at'] ?? ''))->getTimestamp()
            === $expected->copy()->subMinute()->getTimestamp(),
        'retained_history_event_count' => $detail['retained_history_event_count'] ?? null,
        'retained_exception_count' => $detail['retained_exception_count'] ?? null,
        'timestamp_matches' => $hydrated->details_pruned_at?->getTimestamp() === $expected->getTimestamp()
            && Carbon::parse((string) ($detail['details_pruned_at'] ?? ''))->getTimestamp() === $expected->getTimestamp(),
    ];
}

echo json_encode($output, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
