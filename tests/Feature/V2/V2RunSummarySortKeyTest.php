<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Carbon;
use Tests\TestCase;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Support\RunSummarySortKey;

final class V2RunSummarySortKeyTest extends TestCase
{
    public function testDescendingQueryOrderUsesDurableSortTimestampThenRunId(): void
    {
        $sortTimestamp = Carbon::parse('2026-04-08 12:00:00');

        $olderRunId = '01JTESTSORTKEY00000000000001';
        $newerRunId = '01JTESTSORTKEY00000000000002';

        $older = $this->createRunningSummary(
            'sort-contract-older',
            $olderRunId,
            $sortTimestamp,
            Carbon::parse('2026-04-08 12:30:00'),
        );
        $newer = $this->createRunningSummary(
            'sort-contract-newer',
            $newerRunId,
            $sortTimestamp,
            Carbon::parse('2026-04-08 11:30:00'),
        );

        $orderedIds = RunSummarySortKey::applyDescending(WorkflowRunSummary::query())
            ->pluck('id')
            ->all();

        $this->assertSame([$newer->id, $older->id], $orderedIds);
        $this->assertSame($older->sort_timestamp?->toIso8601String(), $newer->sort_timestamp?->toIso8601String());
        $this->assertTrue($older->created_at->gt($newer->created_at));
    }

    private function createRunningSummary(
        string $instanceId,
        string $runId,
        Carbon $startedAt,
        Carbon $createdAt,
    ): WorkflowRunSummary {
        $instance = WorkflowInstance::query()->create([
            'id' => $instanceId,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'run_count' => 1,
        ]);

        $run = WorkflowRun::query()->create([
            'id' => $runId,
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'status' => 'waiting',
            'started_at' => $startedAt,
            'last_progress_at' => $startedAt,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        $instance->forceFill(['current_run_id' => $run->id])->save();

        return WorkflowRunSummary::query()->create([
            'id' => $run->id,
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'is_current_run' => true,
            'engine_source' => 'v2',
            'class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'status' => 'waiting',
            'status_bucket' => 'running',
            'started_at' => $startedAt,
            'sort_timestamp' => $startedAt,
            'sort_key' => RunSummarySortKey::key($startedAt, $createdAt, $createdAt, $run->id),
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }
}
