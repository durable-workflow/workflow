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
            'workflow_type' => ' billing.invoice-sync ',
            'business_key' => '',
            'compatibility' => 'build-a',
            'queue' => str_repeat('x', 192),
            'connection' => 'redis',
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
            'workflow_type' => 'billing.invoice-sync',
            'compatibility' => 'build-a',
            'connection' => 'redis',
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
                'workflow_type' => 'billing.invoice-sync',
                'labels' => [
                    'region' => 'us-east',
                    'tenant' => 'acme',
                ],
            ],
            [
                'business_key' => 'order-123',
                'labels' => [
                    'region' => 'eu-west',
                ],
            ],
        );

        $this->assertSame([
            'workflow_type' => 'billing.invoice-sync',
            'business_key' => 'order-123',
            'labels' => [
                'region' => 'eu-west',
                'tenant' => 'acme',
            ],
        ], $filters);
    }

    public function testApplyFiltersRunSummariesByExactFieldsAndLabels(): void
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
        ]);

        $ids = VisibilityFilters::apply(WorkflowRunSummary::query(), [
            'workflow_type' => 'billing.invoice-sync',
            'business_key' => 'order-123',
            'labels' => ['tenant' => 'acme'],
        ])->pluck('id')->all();

        $this->assertSame(['01JVISFILTERMATCH000000001'], $ids);
    }
}
