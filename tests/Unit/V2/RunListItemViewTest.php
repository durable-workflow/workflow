<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Carbon;
use Tests\TestCase;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Support\RunListItemView;

final class RunListItemViewTest extends TestCase
{
    public function testFromSummaryReturnsExactlyTheDeclaredFields(): void
    {
        $summary = $this->makeSummary();

        $item = RunListItemView::fromSummary($summary);

        $this->assertSame(RunListItemView::fields(), array_keys($item));
    }

    public function testFromSummaryProjectsIdentityFields(): void
    {
        $summary = $this->makeSummary([
            'id' => 'run-001',
            'workflow_instance_id' => 'inst-001',
            'run_number' => 1,
            'is_current_run' => true,
            'engine_source' => 'v2',
            'class' => 'App\\Workflows\\OrderWorkflow',
            'workflow_type' => 'order.process',
            'namespace' => 'production',
            'business_key' => 'order-42',
            'compatibility' => 'build-a',
        ]);

        $item = RunListItemView::fromSummary($summary);

        $this->assertSame('run-001', $item['id']);
        $this->assertSame('inst-001', $item['workflow_instance_id']);
        $this->assertSame('inst-001', $item['instance_id']);
        $this->assertSame('run-001', $item['selected_run_id']);
        $this->assertSame('run-001', $item['run_id']);
        $this->assertSame(1, $item['run_number']);
        $this->assertTrue($item['is_current_run']);
        $this->assertSame('v2', $item['engine_source']);
        $this->assertSame('App\\Workflows\\OrderWorkflow', $item['class']);
        $this->assertSame('order.process', $item['workflow_type']);
        $this->assertSame('production', $item['namespace']);
        $this->assertSame('order-42', $item['business_key']);
        $this->assertSame('build-a', $item['compatibility']);
    }

    public function testFromSummaryProjectsStatusFields(): void
    {
        $summary = $this->makeSummary([
            'status' => 'completed',
            'status_bucket' => 'completed',
            'closed_reason' => 'completed',
        ]);

        $item = RunListItemView::fromSummary($summary);

        $this->assertSame('completed', $item['status']);
        $this->assertSame('completed', $item['status_bucket']);
        $this->assertTrue($item['is_terminal']);
        $this->assertSame('completed', $item['closed_reason']);
    }

    public function testFromSummaryProjectsRunningStatusAsNonTerminal(): void
    {
        $summary = $this->makeSummary([
            'status' => 'running',
            'status_bucket' => 'running',
            'closed_reason' => null,
        ]);

        $item = RunListItemView::fromSummary($summary);

        $this->assertFalse($item['is_terminal']);
        $this->assertNull($item['closed_reason']);
    }

    public function testFromSummaryProjectsTimestampsAsIso8601(): void
    {
        $now = Carbon::parse('2026-04-13 12:00:00');

        $summary = $this->makeSummary([
            'started_at' => $now,
            'closed_at' => $now->copy()
                ->addHour(),
            'sort_timestamp' => $now,
            'duration_ms' => 3600000,
        ]);

        $item = RunListItemView::fromSummary($summary);

        $this->assertIsString($item['started_at']);
        $this->assertStringContainsString('2026-04-13', $item['started_at']);
        $this->assertIsString($item['closed_at']);
        $this->assertIsString($item['sort_timestamp']);
        $this->assertSame(3600000, $item['duration_ms']);
    }

    public function testFromSummaryProjectsNullTimestampsAsNull(): void
    {
        $summary = $this->makeSummary([
            'started_at' => null,
            'closed_at' => null,
            'sort_timestamp' => null,
            'archived_at' => null,
            'duration_ms' => null,
        ]);

        $item = RunListItemView::fromSummary($summary);

        $this->assertNull($item['started_at']);
        $this->assertNull($item['closed_at']);
        $this->assertNull($item['sort_timestamp']);
        $this->assertNull($item['archived_at']);
        $this->assertNull($item['duration_ms']);
    }

    public function testFromSummaryProjectsWaitState(): void
    {
        $summary = $this->makeSummary([
            'wait_kind' => 'signal',
            'wait_reason' => 'Waiting for approval',
            'liveness_state' => 'waiting_for_signal',
        ]);

        $item = RunListItemView::fromSummary($summary);

        $this->assertSame('signal', $item['wait_kind']);
        $this->assertSame('Waiting for approval', $item['wait_reason']);
        $this->assertSame('waiting_for_signal', $item['liveness_state']);
    }

    public function testFromSummaryProjectsOperatorMetadataAsArrays(): void
    {
        $summary = $this->makeSummary([
            'visibility_labels' => [
                'tenant' => 'acme',
                'region' => 'us-east',
            ],
            'search_attributes' => [
                'orderId' => '42',
            ],
        ]);

        $item = RunListItemView::fromSummary($summary);

        $this->assertSame([
            'tenant' => 'acme',
            'region' => 'us-east',
        ], $item['visibility_labels']);
        $this->assertSame([
            'orderId' => '42',
        ], $item['search_attributes']);
    }

    public function testFromSummaryDefaultsNullMetadataToEmptyArrays(): void
    {
        $summary = $this->makeSummary([
            'visibility_labels' => null,
            'search_attributes' => null,
        ]);

        $item = RunListItemView::fromSummary($summary);

        $this->assertSame([], $item['visibility_labels']);
        $this->assertSame([], $item['search_attributes']);
    }

    public function testFromSummaryProjectsRepairBadgeMetadata(): void
    {
        $summary = $this->makeSummary([
            'repair_attention' => true,
            'repair_blocked_reason' => 'unsupported_history',
        ]);

        $item = RunListItemView::fromSummary($summary);

        $this->assertTrue($item['repair_attention']);
        $this->assertSame('unsupported_history', $item['repair_blocked_reason']);
        $this->assertIsArray($item['repair_blocked']);
        $this->assertSame('unsupported_history', $item['repair_blocked']['code']);
        $this->assertArrayHasKey('label', $item['repair_blocked']);
        $this->assertArrayHasKey('tone', $item['repair_blocked']);
        $this->assertArrayHasKey('badge_visible', $item['repair_blocked']);
    }

    public function testFromSummaryProjectsNullRepairAsNullBadge(): void
    {
        $summary = $this->makeSummary([
            'repair_attention' => false,
            'repair_blocked_reason' => null,
        ]);

        $item = RunListItemView::fromSummary($summary);

        $this->assertFalse($item['repair_attention']);
        $this->assertNull($item['repair_blocked_reason']);
        $this->assertNull($item['repair_blocked']);
    }

    public function testFromSummaryProjectsTaskProblemBadge(): void
    {
        $summary = $this->makeSummary([
            'task_problem' => true,
            'liveness_state' => 'replay_blocked',
            'wait_kind' => null,
        ]);

        $item = RunListItemView::fromSummary($summary);

        $this->assertTrue($item['task_problem']);
        $this->assertIsArray($item['task_problem_badge']);
        $this->assertArrayHasKey('label', $item['task_problem_badge']);
        $this->assertArrayHasKey('tone', $item['task_problem_badge']);
    }

    public function testFromSummaryProjectsCommandContractFields(): void
    {
        $summary = $this->makeSummary([
            'declared_entry_mode' => 'compatibility',
            'declared_contract_source' => 'durable_history',
            'declared_contract_backfill_needed' => true,
            'declared_contract_backfill_available' => true,
        ]);

        $item = RunListItemView::fromSummary($summary);

        $this->assertSame('compatibility', $item['declared_entry_mode']);
        $this->assertSame('durable_history', $item['declared_contract_source']);
        $this->assertTrue($item['declared_contract_backfill_needed']);
        $this->assertTrue($item['declared_contract_backfill_available']);
    }

    public function testFromSummaryProjectsHistoryBudgetFields(): void
    {
        $summary = $this->makeSummary([
            'exception_count' => 3,
            'history_event_count' => 1500,
            'history_size_bytes' => 524288,
            'continue_as_new_recommended' => false,
        ]);

        $item = RunListItemView::fromSummary($summary);

        $this->assertSame(3, $item['exception_count']);
        $this->assertSame(1500, $item['history_event_count']);
        $this->assertSame(524288, $item['history_size_bytes']);
        $this->assertFalse($item['continue_as_new_recommended']);
    }

    public function testFromSummaryExcludesInternalProjectionFields(): void
    {
        $summary = $this->makeSummary([
            'sort_key' => '2026-04-13T12:00:00Z#run-001',
            'projection_schema_version' => 1,
            'resume_source_kind' => 'workflow_task',
            'resume_source_id' => 'task-001',
            'open_wait_id' => 'workflow-task:task-001',
            'next_task_id' => 'task-001',
            'next_task_type' => 'workflow',
            'next_task_status' => 'ready',
            'next_task_lease_expires_at' => Carbon::now(),
        ]);

        $item = RunListItemView::fromSummary($summary);

        $this->assertArrayNotHasKey('projection_schema_version', $item);
        $this->assertArrayNotHasKey('resume_source_kind', $item);
        $this->assertArrayNotHasKey('resume_source_id', $item);
        $this->assertArrayNotHasKey('open_wait_id', $item);
        $this->assertArrayNotHasKey('next_task_id', $item);
        $this->assertArrayNotHasKey('next_task_type', $item);
        $this->assertArrayNotHasKey('next_task_status', $item);
        $this->assertArrayNotHasKey('next_task_lease_expires_at', $item);
    }

    public function testFieldsListMatchesActualOutputKeys(): void
    {
        $summary = $this->makeSummary();
        $item = RunListItemView::fromSummary($summary);

        $this->assertSame(
            RunListItemView::fields(),
            array_keys($item),
            'RunListItemView::fields() must match the actual array keys from fromSummary()',
        );
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function makeSummary(array $overrides = []): WorkflowRunSummary
    {
        $defaults = [
            'id' => 'run-test-001',
            'workflow_instance_id' => 'inst-test-001',
            'run_number' => 1,
            'is_current_run' => true,
            'engine_source' => 'v2',
            'class' => 'App\\Workflows\\TestWorkflow',
            'workflow_type' => 'test.workflow',
            'namespace' => null,
            'business_key' => null,
            'compatibility' => 'test-build',
            'status' => 'running',
            'status_bucket' => 'running',
            'closed_reason' => null,
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => Carbon::now(),
            'closed_at' => null,
            'sort_timestamp' => Carbon::now(),
            'archived_at' => null,
            'archive_reason' => null,
            'duration_ms' => null,
            'wait_kind' => null,
            'wait_reason' => null,
            'liveness_state' => 'executing',
            'visibility_labels' => [],
            'search_attributes' => [],
            'repair_attention' => false,
            'repair_blocked_reason' => null,
            'task_problem' => false,
            'declared_entry_mode' => 'canonical',
            'declared_contract_source' => 'live_definition',
            'declared_contract_backfill_needed' => false,
            'declared_contract_backfill_available' => false,
            'exception_count' => 0,
            'history_event_count' => 0,
            'history_size_bytes' => 0,
            'continue_as_new_recommended' => false,
            'projection_schema_version' => 1,
        ];

        $summary = new WorkflowRunSummary();
        $summary->forceFill(array_merge($defaults, $overrides));

        return $summary;
    }
}
