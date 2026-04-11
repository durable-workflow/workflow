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
            'queue' => str_repeat('x', 192),
            'connection' => 'redis',
            'wait_kind' => ' signal ',
            'liveness_state' => ' waiting_for_signal ',
            'repair_blocked_reason' => ' unsupported_history ',
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
            'connection' => 'redis',
            'wait_kind' => 'signal',
            'liveness_state' => 'waiting_for_signal',
            'repair_blocked_reason' => 'unsupported_history',
            'is_current_run' => true,
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
                'continue_as_new_recommended' => false,
                'labels' => [
                    'region' => 'us-east',
                    'tenant' => 'acme',
                ],
            ],
            [
                'business_key' => 'order-123',
                'archived' => true,
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
            'queue' => 'workflow',
            'connection' => 'redis',
            'status' => 'waiting',
            'status_bucket' => 'running',
            'wait_kind' => 'signal',
            'liveness_state' => 'waiting_for_signal',
            'repair_blocked_reason' => 'unsupported_history',
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
            'queue' => 'workflow',
            'connection' => 'redis',
            'status' => 'waiting',
            'status_bucket' => 'running',
            'wait_kind' => 'timer',
            'liveness_state' => 'timer_scheduled',
            'repair_blocked_reason' => 'repair_not_needed',
            'archived_at' => now(),
            'continue_as_new_recommended' => false,
        ]);

        $ids = VisibilityFilters::apply(WorkflowRunSummary::query(), [
            'instance_id' => 'visibility-filter-match',
            'run_id' => '01JVISFILTERMATCH000000001',
            'is_current_run' => true,
            'workflow_type' => 'billing.invoice-sync',
            'business_key' => 'order-123',
            'wait_kind' => 'signal',
            'liveness_state' => 'waiting_for_signal',
            'repair_blocked_reason' => 'unsupported_history',
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

    public function testDefinitionDescribesExactVisibilityContract(): void
    {
        $definition = VisibilityFilters::definition();

        $this->assertSame(VisibilityFilters::VERSION, $definition['version']);
        $this->assertSame('Instance ID', $definition['fields']['instance_id']['label']);
        $this->assertSame('string', $definition['fields']['instance_id']['type']);
        $this->assertSame('text', $definition['fields']['instance_id']['input']);
        $this->assertSame(0, $definition['fields']['instance_id']['order']);
        $this->assertSame('string', $definition['fields']['run_id']['type']);
        $this->assertSame('boolean', $definition['fields']['is_current_run']['type']);
        $this->assertSame('boolean_select', $definition['fields']['is_current_run']['input']);
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
        $this->assertSame('Labels', $definition['labels']['label']);
        $this->assertSame('map<string,string>', $definition['labels']['type']);
        $this->assertSame('key_value_textarea', $definition['labels']['input']);
        $this->assertSame('exact', $definition['labels']['operator']);
        $this->assertSame(['label[key]', 'labels[key]'], $definition['labels']['query_parameters']);
        $this->assertSame('tenant=acme' . "\n" . 'region=us-east', $definition['labels']['placeholder']);
    }
}
