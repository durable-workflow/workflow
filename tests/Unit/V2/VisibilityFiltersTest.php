<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Tests\TestCase;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Support\VisibilityFilters;

final class VisibilityFiltersTest extends TestCase
{
    public function testNormalizeKeepsOnlyVersionedVisibilityFields(): void
    {
        $filters = VisibilityFilters::normalize([
            'instance_id' => ' order-visibility ',
            'run_id' => ' run-visibility ',
            'is_current_run' => 'yes',
            'workflow_type' => ' billing.invoice-sync ',
            'business_key' => '',
            'compatibility' => 'build-a',
            'declared_entry_mode' => ' compatibility ',
            'declared_contract_source' => ' durable_history ',
            'queue' => str_repeat('x', 192),
            'connection' => 'redis',
            'wait_kind' => ' signal ',
            'liveness_state' => ' waiting_for_signal ',
            'repair_blocked_reason' => ' unsupported_history ',
            'repair_attention' => '1',
            'task_problem' => 'yes',
            'declared_contract_backfill_needed' => 'yes',
            'declared_contract_backfill_available' => '0',
            'archived' => 'true',
            'is_terminal' => '0',
            'labels' => [
                'tenant' => ' acme ',
                'bad key' => 'ignored',
                'empty' => '',
                'too_long' => str_repeat('x', 192),
                'region' => 'us-east',
            ],
            'unknown' => 'ignored',
        ]);

        $this->assertSame([
            'instance_id' => 'order-visibility',
            'run_id' => 'run-visibility',
            'workflow_type' => 'billing.invoice-sync',
            'compatibility' => 'build-a',
            'declared_entry_mode' => 'compatibility',
            'declared_contract_source' => 'durable_history',
            'connection' => 'redis',
            'wait_kind' => 'signal',
            'liveness_state' => 'waiting_for_signal',
            'repair_blocked_reason' => 'unsupported_history',
            'is_current_run' => true,
            'repair_attention' => true,
            'task_problem' => true,
            'declared_contract_backfill_needed' => true,
            'declared_contract_backfill_available' => false,
            'archived' => true,
            'is_terminal' => false,
            'labels' => [
                'region' => 'us-east',
                'tenant' => 'acme',
            ],
        ], $filters);
    }

    public function testMergeLetsLaterFiltersRefineSavedViewLabels(): void
    {
        $filters = VisibilityFilters::merge(
            [
                'instance_id' => 'workflow-order',
                'workflow_type' => 'billing.invoice-sync',
                'archived' => false,
                'repair_attention' => false,
                'continue_as_new_recommended' => false,
                'labels' => [
                    'region' => 'us-east',
                    'tenant' => 'acme',
                ],
            ],
            [
                'business_key' => 'order-123',
                'archived' => true,
                'repair_attention' => true,
                'continue_as_new_recommended' => true,
                'labels' => [
                    'region' => 'eu-west',
                ],
            ],
        );

        $this->assertSame([
            'instance_id' => 'workflow-order',
            'workflow_type' => 'billing.invoice-sync',
            'business_key' => 'order-123',
            'repair_attention' => true,
            'continue_as_new_recommended' => true,
            'archived' => true,
            'labels' => [
                'region' => 'eu-west',
                'tenant' => 'acme',
            ],
        ], $filters);
    }

    public function testApplyFiltersRunSummariesByExpandedExactFieldsAndLabels(): void
    {
        WorkflowRunSummary::create([
            'id' => '01JVISFILTERMATCH000000001',
            'workflow_instance_id' => 'visibility-filter-match',
            'run_number' => 1,
            'is_current_run' => true,
            'engine_source' => 'v2',
            'class' => 'BillingWorkflow',
            'workflow_type' => 'billing.invoice-sync',
            'business_key' => 'order-123',
            'visibility_labels' => [
                'tenant' => 'acme',
                'region' => 'us-east',
            ],
            'compatibility' => 'build-a',
            'declared_entry_mode' => 'compatibility',
            'declared_contract_source' => 'live_definition',
            'declared_contract_backfill_needed' => true,
            'declared_contract_backfill_available' => true,
            'queue' => 'workflow',
            'connection' => 'redis',
            'status' => 'waiting',
            'status_bucket' => 'running',
            'wait_kind' => 'signal',
            'liveness_state' => 'waiting_for_signal',
            'repair_blocked_reason' => 'unsupported_history',
            'repair_attention' => true,
            'task_problem' => true,
            'continue_as_new_recommended' => true,
        ]);
        WorkflowRunSummary::create([
            'id' => '01JVISFILTERMISS0000000001',
            'workflow_instance_id' => 'visibility-filter-miss',
            'run_number' => 1,
            'is_current_run' => true,
            'engine_source' => 'v2',
            'class' => 'BillingWorkflow',
            'workflow_type' => 'billing.invoice-sync',
            'business_key' => 'order-456',
            'visibility_labels' => [
                'tenant' => 'beta',
                'region' => 'us-east',
            ],
            'compatibility' => 'build-a',
            'declared_entry_mode' => 'canonical',
            'declared_contract_source' => 'durable_history',
            'declared_contract_backfill_needed' => false,
            'declared_contract_backfill_available' => false,
            'queue' => 'workflow',
            'connection' => 'redis',
            'status' => 'waiting',
            'status_bucket' => 'running',
            'wait_kind' => 'timer',
            'liveness_state' => 'timer_scheduled',
            'repair_blocked_reason' => 'repair_not_needed',
            'repair_attention' => false,
            'task_problem' => false,
            'archived_at' => now(),
            'continue_as_new_recommended' => false,
        ]);

        $ids = VisibilityFilters::apply(WorkflowRunSummary::query(), [
            'instance_id' => 'visibility-filter-match',
            'run_id' => '01JVISFILTERMATCH000000001',
            'is_current_run' => true,
            'workflow_type' => 'billing.invoice-sync',
            'business_key' => 'order-123',
            'declared_entry_mode' => 'compatibility',
            'declared_contract_source' => 'live_definition',
            'wait_kind' => 'signal',
            'liveness_state' => 'waiting_for_signal',
            'repair_blocked_reason' => 'unsupported_history',
            'repair_attention' => true,
            'task_problem' => true,
            'declared_contract_backfill_needed' => true,
            'declared_contract_backfill_available' => true,
            'continue_as_new_recommended' => true,
            'archived' => false,
            'is_terminal' => false,
            'labels' => [
                'tenant' => 'acme',
            ],
        ])->pluck('id')
            ->all();

        $this->assertSame(['01JVISFILTERMATCH000000001'], $ids);
    }

    public function testApplyFiltersUseBooleanExactFields(): void
    {
        WorkflowRunSummary::create([
            'id' => '01JVISBOOLMATCH00000000001',
            'workflow_instance_id' => 'visibility-bool-match',
            'run_number' => 1,
            'is_current_run' => false,
            'engine_source' => 'v2',
            'class' => 'BillingWorkflow',
            'workflow_type' => 'billing.invoice-sync',
            'status' => 'waiting',
            'status_bucket' => 'running',
            'repair_attention' => true,
            'task_problem' => true,
            'continue_as_new_recommended' => true,
        ]);
        WorkflowRunSummary::create([
            'id' => '01JVISBOOLMISS000000000001',
            'workflow_instance_id' => 'visibility-bool-miss',
            'run_number' => 1,
            'is_current_run' => true,
            'engine_source' => 'v2',
            'class' => 'BillingWorkflow',
            'workflow_type' => 'billing.invoice-sync',
            'status' => 'waiting',
            'status_bucket' => 'running',
            'repair_attention' => false,
            'task_problem' => false,
            'continue_as_new_recommended' => false,
        ]);

        $ids = VisibilityFilters::apply(WorkflowRunSummary::query(), [
            'workflow_type' => 'billing.invoice-sync',
            'is_current_run' => false,
            'repair_attention' => true,
            'task_problem' => true,
            'continue_as_new_recommended' => true,
        ])->pluck('id')->all();

        $this->assertSame(['01JVISBOOLMATCH00000000001'], $ids);
    }

    public function testDefinitionDescribesExactVisibilityContract(): void
    {
        $definition = VisibilityFilters::definition();

        $this->assertSame(VisibilityFilters::VERSION, $definition['version']);
        $this->assertSame([1, 2, VisibilityFilters::VERSION], $definition['supported_versions']);
        $this->assertSame('Instance ID', $definition['fields']['instance_id']['label']);
        $this->assertSame('string', $definition['fields']['instance_id']['type']);
        $this->assertSame('text', $definition['fields']['instance_id']['input']);
        $this->assertSame(0, $definition['fields']['instance_id']['order']);
        $this->assertSame('string', $definition['fields']['run_id']['type']);
        $this->assertSame('boolean', $definition['fields']['is_current_run']['type']);
        $this->assertSame('boolean_select', $definition['fields']['is_current_run']['input']);
        $this->assertSame('Entry Mode', $definition['fields']['declared_entry_mode']['label']);
        $this->assertSame('string', $definition['fields']['declared_entry_mode']['type']);
        $this->assertSame('select', $definition['fields']['declared_entry_mode']['input']);
        $this->assertSame('Canonical', $definition['fields']['declared_entry_mode']['options'][0]['label']);
        $this->assertSame('canonical', $definition['fields']['declared_entry_mode']['options'][0]['value']);
        $this->assertSame('Command Contract Source', $definition['fields']['declared_contract_source']['label']);
        $this->assertSame('string', $definition['fields']['declared_contract_source']['type']);
        $this->assertSame('select', $definition['fields']['declared_contract_source']['input']);
        $this->assertSame(
            'Durable History',
            $definition['fields']['declared_contract_source']['options'][0]['label'],
        );
        $this->assertSame(
            'durable_history',
            $definition['fields']['declared_contract_source']['options'][0]['value'],
        );
        $this->assertSame(
            'Command Contract Backfill Needed',
            $definition['fields']['declared_contract_backfill_needed']['label'],
        );
        $this->assertSame('boolean', $definition['fields']['declared_contract_backfill_needed']['type']);
        $this->assertSame(
            'boolean_select',
            $definition['fields']['declared_contract_backfill_needed']['input'],
        );
        $this->assertSame(
            'Command Contract Backfill Available',
            $definition['fields']['declared_contract_backfill_available']['label'],
        );
        $this->assertSame('boolean', $definition['fields']['declared_contract_backfill_available']['type']);
        $this->assertSame(
            'boolean_select',
            $definition['fields']['declared_contract_backfill_available']['input'],
        );
        $this->assertSame('Continue As New Recommended', $definition['fields']['continue_as_new_recommended']['label']);
        $this->assertSame('boolean', $definition['fields']['continue_as_new_recommended']['type']);
        $this->assertSame('boolean_select', $definition['fields']['continue_as_new_recommended']['input']);
        $this->assertSame('boolean', $definition['fields']['archived']['type']);
        $this->assertSame('boolean_select', $definition['fields']['archived']['input']);
        $this->assertSame('Yes', $definition['fields']['archived']['options'][0]['label']);
        $this->assertTrue($definition['fields']['archived']['options'][0]['value']);
        $this->assertSame('boolean', $definition['fields']['is_terminal']['type']);
        $this->assertSame('exact', $definition['fields']['wait_kind']['operator']);
        $this->assertSame('select', $definition['fields']['wait_kind']['input']);
        $this->assertSame('Activity', $definition['fields']['wait_kind']['options'][0]['label']);
        $this->assertSame('activity', $definition['fields']['wait_kind']['options'][0]['value']);
        $this->assertSame('wait_kind', $definition['fields']['wait_kind']['query_parameter']);
        $this->assertSame('select', $definition['fields']['status_bucket']['input']);
        $this->assertSame('running', $definition['fields']['status_bucket']['options'][0]['value']);
        $this->assertSame('Completed', $definition['fields']['closed_reason']['options'][0]['label']);
        $this->assertSame('Repair Blocked Reason', $definition['fields']['repair_blocked_reason']['label']);
        $this->assertSame('string', $definition['fields']['repair_blocked_reason']['type']);
        $this->assertSame('select', $definition['fields']['repair_blocked_reason']['input']);
        $this->assertSame('Replay Blocked', $definition['fields']['repair_blocked_reason']['options'][0]['label']);
        $this->assertSame('unsupported_history', $definition['fields']['repair_blocked_reason']['options'][0]['value']);
        $this->assertSame(
            'Repair is blocked because only unsupported diagnostic history remains.',
            $definition['fields']['repair_blocked_reason']['options'][0]['description'],
        );
        $this->assertSame('dark', $definition['fields']['repair_blocked_reason']['options'][0]['tone']);
        $this->assertTrue($definition['fields']['repair_blocked_reason']['options'][0]['badge_visible']);
        $this->assertSame('Repair Attention', $definition['fields']['repair_attention']['label']);
        $this->assertSame('boolean', $definition['fields']['repair_attention']['type']);
        $this->assertSame('boolean_select', $definition['fields']['repair_attention']['input']);
        $this->assertSame('Task Problem', $definition['fields']['task_problem']['label']);
        $this->assertSame('boolean', $definition['fields']['task_problem']['type']);
        $this->assertSame('boolean_select', $definition['fields']['task_problem']['input']);
        $this->assertSame('Labels', $definition['labels']['label']);
        $this->assertSame('map<string,string>', $definition['labels']['type']);
        $this->assertSame('key_value_textarea', $definition['labels']['input']);
        $this->assertSame('exact', $definition['labels']['operator']);
        $this->assertSame(['label[key]', 'labels[key]'], $definition['labels']['query_parameters']);
        $this->assertSame('tenant=acme' . "\n" . 'region=us-east', $definition['labels']['placeholder']);
    }

    public function testVersionMetadataMarksUnsupportedSavedViewContractsExplicitly(): void
    {
        $supported = VisibilityFilters::versionMetadata(VisibilityFilters::VERSION);
        $unsupported = VisibilityFilters::versionMetadata(99);

        $this->assertSame(VisibilityFilters::VERSION, $supported['version']);
        $this->assertSame(VisibilityFilters::VERSION, $supported['current_version']);
        $this->assertSame([1, 2, VisibilityFilters::VERSION], $supported['supported_versions']);
        $this->assertTrue($supported['supported']);
        $this->assertSame('supported', $supported['status']);
        $this->assertNull($supported['message']);

        $this->assertSame(99, $unsupported['version']);
        $this->assertSame(VisibilityFilters::VERSION, $unsupported['current_version']);
        $this->assertSame([1, 2, VisibilityFilters::VERSION], $unsupported['supported_versions']);
        $this->assertFalse($unsupported['supported']);
        $this->assertSame('unsupported', $unsupported['status']);
        $this->assertSame(
            'This saved view uses visibility filter version 99, but this Waterline build supports version 1, 2, 3.',
            $unsupported['message'],
        );
    }
}
