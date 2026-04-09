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
            'workflow_type' => ' billing.invoice-sync ',
            'business_key' => '',
            'compatibility' => 'build-a',
            'queue' => str_repeat('x', 192),
            'connection' => 'redis',
            'wait_kind' => ' signal ',
            'liveness_state' => ' waiting_for_signal ',
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
                'labels' => [
                    'region' => 'us-east',
                    'tenant' => 'acme',
                ],
            ],
            [
                'business_key' => 'order-123',
                'archived' => true,
                'labels' => [
                    'region' => 'eu-west',
                ],
            ],
        );

        $this->assertSame([
            'instance_id' => 'workflow-order',
            'workflow_type' => 'billing.invoice-sync',
            'business_key' => 'order-123',
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
            'visibility_labels' => ['tenant' => 'acme', 'region' => 'us-east'],
            'compatibility' => 'build-a',
            'queue' => 'workflow',
            'connection' => 'redis',
            'status' => 'waiting',
            'status_bucket' => 'running',
            'wait_kind' => 'signal',
            'liveness_state' => 'waiting_for_signal',
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
            'visibility_labels' => ['tenant' => 'beta', 'region' => 'us-east'],
            'compatibility' => 'build-a',
            'queue' => 'workflow',
            'connection' => 'redis',
            'status' => 'waiting',
            'status_bucket' => 'running',
            'wait_kind' => 'timer',
            'liveness_state' => 'timer_scheduled',
            'archived_at' => now(),
        ]);

        $ids = VisibilityFilters::apply(WorkflowRunSummary::query(), [
            'instance_id' => 'visibility-filter-match',
            'run_id' => '01JVISFILTERMATCH000000001',
            'workflow_type' => 'billing.invoice-sync',
            'business_key' => 'order-123',
            'wait_kind' => 'signal',
            'liveness_state' => 'waiting_for_signal',
            'archived' => false,
            'is_terminal' => false,
            'labels' => ['tenant' => 'acme'],
        ])->pluck('id')->all();

        $this->assertSame(['01JVISFILTERMATCH000000001'], $ids);
    }

    public function testDefinitionDescribesExactVisibilityContract(): void
    {
        $definition = VisibilityFilters::definition();

        $this->assertSame(VisibilityFilters::VERSION, $definition['version']);
        $this->assertSame('string', $definition['fields']['instance_id']['type']);
        $this->assertSame('string', $definition['fields']['run_id']['type']);
        $this->assertSame('boolean', $definition['fields']['archived']['type']);
        $this->assertSame('boolean', $definition['fields']['is_terminal']['type']);
        $this->assertSame('exact', $definition['fields']['wait_kind']['operator']);
        $this->assertSame('map<string,string>', $definition['labels']['type']);
        $this->assertSame('exact', $definition['labels']['operator']);
        $this->assertSame(['label[key]', 'labels[key]'], $definition['labels']['query_parameters']);
    }
}
