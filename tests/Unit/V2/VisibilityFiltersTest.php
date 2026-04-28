<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Tests\TestCase;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowSearchAttribute;
use Workflow\V2\Support\RunSummaryProjector;
use Workflow\V2\Support\VisibilityFilters;

final class VisibilityFiltersTest extends TestCase
{
    public function testNormalizeKeepsOnlyVersionedVisibilityFields(): void
    {
        $filters = VisibilityFilters::normalize([
            'instance_id' => ' order-visibility ',
            'run_id' => ' run-visibility ',
            'is_current_run' => 'yes',
            'namespace' => ' production ',
            'workflow_type' => ' billing.invoice-sync ',
            'workflow_type_contains' => ' invoice ',
            'business_key' => '',
            'contains' => [
                'instance_id' => ' visibility ',
                'business_key' => ' order ',
            ],
            'compatibility' => 'build-a',
            'declared_entry_mode' => ' canonical ',
            'declared_contract_source' => ' durable_history ',
            'queue' => str_repeat('x', 192),
            'connection' => 'redis',
            'wait_kind' => ' signal ',
            'liveness_state' => ' waiting_for_signal ',
            'repair_blocked_reason' => ' unsupported_history ',
            'repair_attention' => '1',
            'task_problem' => 'yes',
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
            'namespace' => 'production',
            'workflow_type' => 'billing.invoice-sync',
            'compatibility' => 'build-a',
            'declared_entry_mode' => 'canonical',
            'declared_contract_source' => 'durable_history',
            'connection' => 'redis',
            'wait_kind' => 'signal',
            'liveness_state' => 'waiting_for_signal',
            'repair_blocked_reason' => 'unsupported_history',
            'is_current_run' => true,
            'repair_attention' => true,
            'task_problem' => true,
            'archived' => true,
            'is_terminal' => false,
            'instance_id_contains' => 'visibility',
            'workflow_type_contains' => 'invoice',
            'business_key_contains' => 'order',
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
                'workflow_type_contains' => 'invoice',
            ],
        );

        $this->assertSame([
            'instance_id' => 'workflow-order',
            'workflow_type' => 'billing.invoice-sync',
            'business_key' => 'order-123',
            'repair_attention' => true,
            'continue_as_new_recommended' => true,
            'archived' => true,
            'workflow_type_contains' => 'invoice',
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
            'declared_entry_mode' => 'canonical',
            'declared_contract_source' => 'durable_history',
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
            'declared_entry_mode' => 'canonical',
            'declared_contract_source' => 'durable_history',
            'wait_kind' => 'signal',
            'liveness_state' => 'waiting_for_signal',
            'repair_blocked_reason' => 'unsupported_history',
            'repair_attention' => true,
            'task_problem' => true,
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
        ])->pluck('id')
            ->all();

        $this->assertSame(['01JVISBOOLMATCH00000000001'], $ids);
    }

    public function testApplyFiltersRunSummariesByContainsFields(): void
    {
        WorkflowRunSummary::create([
            'id' => '01JVISC0NTAINMATCH0000001',
            'workflow_instance_id' => 'tenant-acme-order-12345',
            'run_number' => 1,
            'is_current_run' => true,
            'engine_source' => 'v2',
            'class' => 'BillingWorkflow',
            'workflow_type' => 'billing.invoice-sync',
            'business_key' => 'order_12345',
            'status' => 'waiting',
            'status_bucket' => 'running',
        ]);
        WorkflowRunSummary::create([
            'id' => '01JVISC0NTAINMISS00000001',
            'workflow_instance_id' => 'tenant-beta-invoice-999',
            'run_number' => 1,
            'is_current_run' => true,
            'engine_source' => 'v2',
            'class' => 'BillingWorkflow',
            'workflow_type' => 'billing.invoice-sync',
            'business_key' => 'order-12345',
            'status' => 'waiting',
            'status_bucket' => 'running',
        ]);

        $ids = VisibilityFilters::apply(WorkflowRunSummary::query(), [
            'instance_id_contains' => 'acme-order',
            'run_id_contains' => 'MATCH',
            'workflow_type_contains' => 'invoice',
            'business_key_contains' => 'order_',
        ])->pluck('id')
            ->all();

        $this->assertSame(['01JVISC0NTAINMATCH0000001'], $ids);
    }

    public function testContainsFiltersEscapeSqlWildcards(): void
    {
        WorkflowRunSummary::create([
            'id' => '01JVISC0NTAINESCAPEMATCH01',
            'workflow_instance_id' => 'tenant-order-A_01',
            'run_number' => 1,
            'is_current_run' => true,
            'engine_source' => 'v2',
            'class' => 'BillingWorkflow',
            'workflow_type' => 'billing.invoice-sync',
            'business_key' => 'order-123',
            'status' => 'waiting',
            'status_bucket' => 'running',
        ]);
        WorkflowRunSummary::create([
            'id' => '01JVISC0NTAINESCAPEMISS01',
            'workflow_instance_id' => 'tenant-order-AB01',
            'run_number' => 1,
            'is_current_run' => true,
            'engine_source' => 'v2',
            'class' => 'BillingWorkflow',
            'workflow_type' => 'billing.invoice-sync',
            'business_key' => 'order-456',
            'status' => 'waiting',
            'status_bucket' => 'running',
        ]);

        $ids = VisibilityFilters::apply(WorkflowRunSummary::query(), [
            'instance_id_contains' => 'A_01',
        ])->pluck('id')
            ->all();

        $this->assertSame(['01JVISC0NTAINESCAPEMATCH01'], $ids);
    }

    public function testDefinitionDescribesExactVisibilityContract(): void
    {
        $definition = VisibilityFilters::definition();

        $this->assertSame(VisibilityFilters::VERSION, $definition['version']);
        $this->assertSame([VisibilityFilters::VERSION], $definition['supported_versions']);
        $this->assertSame('Instance ID', $definition['fields']['instance_id']['label']);
        $this->assertSame('string', $definition['fields']['instance_id']['type']);
        $this->assertSame('text', $definition['fields']['instance_id']['input']);
        $this->assertTrue($definition['fields']['instance_id']['filterable']);
        $this->assertTrue($definition['fields']['instance_id']['saved_view_compatible']);
        $this->assertSame(0, $definition['fields']['instance_id']['order']);
        $this->assertSame('string', $definition['fields']['run_id']['type']);
        $this->assertSame('contains', $definition['fields']['instance_id_contains']['operator']);
        $this->assertSame('instance_id', $definition['fields']['instance_id_contains']['contains_field']);
        $this->assertSame('instance_id_contains', $definition['fields']['instance_id_contains']['query_parameter']);
        $this->assertTrue($definition['fields']['workflow_type_contains']['saved_view_compatible']);
        $this->assertSame(
            'Substring match against workflow type names for fragment lookup.',
            $definition['fields']['workflow_type_contains']['help'],
        );
        $this->assertSame('Namespace', $definition['fields']['namespace']['label']);
        $this->assertSame('string', $definition['fields']['namespace']['type']);
        $this->assertSame('text', $definition['fields']['namespace']['input']);
        $this->assertTrue($definition['fields']['namespace']['filterable']);
        $this->assertTrue($definition['fields']['namespace']['saved_view_compatible']);
        $this->assertSame('boolean', $definition['fields']['is_current_run']['type']);
        $this->assertSame('boolean_select', $definition['fields']['is_current_run']['input']);
        $this->assertSame('Business Key', $definition['fields']['business_key']['label']);
        $this->assertSame(
            'Exact-match indexed operator metadata copied onto the run summary and saved-view contract.',
            $definition['fields']['business_key']['help'],
        );
        $this->assertSame('Entry Contract', $definition['fields']['declared_entry_mode']['label']);
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
        $this->assertTrue($definition['labels']['filterable']);
        $this->assertTrue($definition['labels']['saved_view_compatible']);
        $this->assertSame(['label[key]', 'labels[key]'], $definition['labels']['query_parameters']);
        $this->assertSame('tenant=acme' . "\n" . 'region=us-east', $definition['labels']['placeholder']);
        $this->assertSame(
            'One exact-match label per line in key=value format. Labels are indexed operator metadata set at start and saved-view compatible.',
            $definition['labels']['help'],
        );
        $this->assertSame('Business Key', $definition['indexed_metadata']['business_key']['label']);
        $this->assertSame('business_key', $definition['indexed_metadata']['business_key']['filter_field']);
        $this->assertTrue($definition['indexed_metadata']['business_key']['indexed']);
        $this->assertTrue($definition['indexed_metadata']['business_key']['filterable']);
        $this->assertTrue($definition['indexed_metadata']['business_key']['saved_view_compatible']);
        $this->assertSame(
            ['list', 'detail', 'history_export'],
            $definition['indexed_metadata']['business_key']['returned_in']
        );
        $this->assertSame('Labels', $definition['indexed_metadata']['labels']['label']);
        $this->assertSame(
            ['label[key]', 'labels[key]'],
            $definition['indexed_metadata']['labels']['query_parameters'],
        );
        $this->assertTrue($definition['indexed_metadata']['labels']['indexed']);
        $this->assertTrue($definition['indexed_metadata']['labels']['filterable']);
        $this->assertTrue($definition['indexed_metadata']['labels']['saved_view_compatible']);
        $this->assertSame('Memo', $definition['detail_metadata']['memo']['label']);
        $this->assertFalse($definition['detail_metadata']['memo']['indexed']);
        $this->assertFalse($definition['detail_metadata']['memo']['filterable']);
        $this->assertFalse($definition['detail_metadata']['memo']['saved_view_compatible']);
        $this->assertSame(['detail', 'history_export'], $definition['detail_metadata']['memo']['returned_in']);
        $this->assertSame(
            'Returned-only per-run context copied onto the instance, run, typed start history, selected-run detail, and history export.',
            $definition['detail_metadata']['memo']['description'],
        );
    }

    public function testApplyFiltersRunSummariesByNamespace(): void
    {
        WorkflowRunSummary::create([
            'id' => '01JVISNSFILTER0MATCH000001',
            'workflow_instance_id' => 'ns-filter-match',
            'run_number' => 1,
            'is_current_run' => true,
            'engine_source' => 'v2',
            'class' => 'BillingWorkflow',
            'workflow_type' => 'billing.invoice-sync',
            'namespace' => 'production',
            'status' => 'running',
            'status_bucket' => 'running',
        ]);
        WorkflowRunSummary::create([
            'id' => '01JVISNSFILTER0MISS0000001',
            'workflow_instance_id' => 'ns-filter-miss',
            'run_number' => 1,
            'is_current_run' => true,
            'engine_source' => 'v2',
            'class' => 'BillingWorkflow',
            'workflow_type' => 'billing.invoice-sync',
            'namespace' => 'staging',
            'status' => 'running',
            'status_bucket' => 'running',
        ]);
        WorkflowRunSummary::create([
            'id' => '01JVISNSFILTER0NULL0000001',
            'workflow_instance_id' => 'ns-filter-null',
            'run_number' => 1,
            'is_current_run' => true,
            'engine_source' => 'v2',
            'class' => 'BillingWorkflow',
            'workflow_type' => 'billing.invoice-sync',
            'namespace' => null,
            'status' => 'running',
            'status_bucket' => 'running',
        ]);

        $ids = VisibilityFilters::apply(WorkflowRunSummary::query(), [
            'namespace' => 'production',
        ])->pluck('id')
            ->all();

        $this->assertSame(['01JVISNSFILTER0MATCH000001'], $ids);
    }

    public function testVersionMetadataMarksUnsupportedSavedViewContractsExplicitly(): void
    {
        $supported = VisibilityFilters::versionMetadata(VisibilityFilters::VERSION);
        $unsupported = VisibilityFilters::versionMetadata(99);

        $this->assertSame(VisibilityFilters::VERSION, $supported['version']);
        $this->assertSame(VisibilityFilters::VERSION, $supported['current_version']);
        $this->assertSame([VisibilityFilters::VERSION], $supported['supported_versions']);
        $this->assertTrue($supported['supported']);
        $this->assertFalse($supported['deprecated']);
        $this->assertSame('supported', $supported['status']);
        $this->assertNull($supported['message']);

        $this->assertSame(99, $unsupported['version']);
        $this->assertSame(VisibilityFilters::VERSION, $unsupported['current_version']);
        $this->assertSame([VisibilityFilters::VERSION], $unsupported['supported_versions']);
        $this->assertFalse($unsupported['supported']);
        $this->assertFalse($unsupported['deprecated']);
        $this->assertSame('unsupported', $unsupported['status']);
        $this->assertSame(
            'This saved view uses visibility filter version 99, but this Waterline build supports version 6.',
            $unsupported['message'],
        );
    }

    public function testVersionMetadataRejectsPreStableV2AlphaVersions(): void
    {
        $alphaVersion = VisibilityFilters::versionMetadata(1);

        $this->assertSame(1, $alphaVersion['version']);
        $this->assertSame(VisibilityFilters::VERSION, $alphaVersion['current_version']);
        $this->assertFalse($alphaVersion['supported']);
        $this->assertFalse($alphaVersion['deprecated']);
        $this->assertSame('unsupported', $alphaVersion['status']);
        $this->assertSame(
            'This saved view uses visibility filter version 1, but this Waterline build supports version 6.',
            $alphaVersion['message'],
        );
    }

    public function testIsReservedViewIdGuardsSystemPrefix(): void
    {
        $this->assertTrue(VisibilityFilters::isReservedViewId('system:running'));
        $this->assertTrue(VisibilityFilters::isReservedViewId('system:running-task-problems'));
        $this->assertTrue(VisibilityFilters::isReservedViewId('system:'));
        $this->assertFalse(VisibilityFilters::isReservedViewId('01J20000000000000000000000'));
        $this->assertFalse(VisibilityFilters::isReservedViewId('custom-view'));
        $this->assertFalse(VisibilityFilters::isReservedViewId(''));
    }

    public function testDefinitionIncludesCleanSlateVersionFields(): void
    {
        $definition = VisibilityFilters::definition();

        $this->assertSame([VisibilityFilters::VERSION], $definition['supported_versions']);
        $this->assertArrayNotHasKey('minimum_supported_version', $definition);
        $this->assertArrayNotHasKey('deprecated_versions', $definition);
        $this->assertSame('system:', $definition['reserved_view_id_prefix']);
    }

    public function testApplyFiltersRunSummariesBySearchAttributes(): void
    {
        $this->createSearchAttributeSummary(
            '01JVISSEARCHATTR0MATCH0001',
            'search-attr-match',
            [
                'priority' => 'high',
                'region' => 'us-east',
            ],
        );
        $this->createSearchAttributeSummary(
            '01JVISSEARCHATTR0MISS00001',
            'search-attr-miss',
            [
                'priority' => 'low',
                'region' => 'us-east',
            ],
        );
        $this->createSearchAttributeSummary('01JVISSEARCHATTR0NULL00001', 'search-attr-null', null);

        $ids = VisibilityFilters::apply(WorkflowRunSummary::query(), [
            'search_attributes' => [
                'priority' => 'high',
            ],
        ])->pluck('id')
            ->all();

        $this->assertSame(['01JVISSEARCHATTR0MATCH0001'], $ids);
    }

    public function testNormalizeHandlesSearchAttributes(): void
    {
        $filters = VisibilityFilters::normalize([
            'search_attributes' => [
                'priority' => ' high ',
                'bad key' => 'ignored',
                'status' => 'processing',
            ],
        ]);

        $this->assertSame([
            'search_attributes' => [
                'priority' => 'high',
                'status' => 'processing',
            ],
        ], $filters);
    }

    public function testMergeCombinesSearchAttributesFromMultipleSources(): void
    {
        $filters = VisibilityFilters::merge(
            [
                'search_attributes' => [
                    'priority' => 'high',
                ],
            ],
            [
                'search_attributes' => [
                    'region' => 'us-east',
                ],
            ],
        );

        $this->assertSame([
            'search_attributes' => [
                'priority' => 'high',
                'region' => 'us-east',
            ],
        ], $filters);
    }

    public function testDefinitionDescribesSearchAttributeContract(): void
    {
        $definition = VisibilityFilters::definition();

        $this->assertSame('Search Attributes', $definition['search_attributes']['label']);
        $this->assertSame('map<string,string>', $definition['search_attributes']['type']);
        $this->assertSame('key_value_textarea', $definition['search_attributes']['input']);
        $this->assertTrue($definition['search_attributes']['filterable']);
        $this->assertTrue($definition['search_attributes']['saved_view_compatible']);
        $this->assertSame(
            ['search_attribute[key]', 'search_attributes[key]'],
            $definition['search_attributes']['query_parameters'],
        );
        $this->assertSame('Search Attributes', $definition['indexed_metadata']['search_attributes']['label']);
        $this->assertTrue($definition['indexed_metadata']['search_attributes']['indexed']);
        $this->assertTrue($definition['indexed_metadata']['search_attributes']['filterable']);
        $this->assertTrue($definition['indexed_metadata']['search_attributes']['saved_view_compatible']);
    }

    public function testDefinitionIncludesProjectionSchemaVersion(): void
    {
        $definition = VisibilityFilters::definition();

        $this->assertSame(RunSummaryProjector::SCHEMA_VERSION, $definition['projection_schema_version']);
        $this->assertIsInt($definition['projection_schema_version']);
        $this->assertGreaterThanOrEqual(1, $definition['projection_schema_version']);
    }

    public function testApplyFiltersExcludeRowsWithNullVisibilityFieldsFromFilteredViews(): void
    {
        WorkflowRunSummary::create([
            'id' => '01JVISMIXEDFLEETMATCH00001',
            'workflow_instance_id' => 'mixed-fleet-match',
            'run_number' => 1,
            'is_current_run' => true,
            'engine_source' => 'v2',
            'projection_schema_version' => RunSummaryProjector::SCHEMA_VERSION,
            'class' => 'BillingWorkflow',
            'workflow_type' => 'billing.invoice-sync',
            'namespace' => 'production',
            'status' => 'running',
            'status_bucket' => 'running',
            'liveness_state' => 'waiting_for_signal',
        ]);
        WorkflowRunSummary::create([
            'id' => '01JVISMIXEDFLEET0NULL00001',
            'workflow_instance_id' => 'mixed-fleet-null-ns',
            'run_number' => 1,
            'is_current_run' => true,
            'engine_source' => 'v2',
            'projection_schema_version' => null,
            'class' => 'BillingWorkflow',
            'workflow_type' => 'billing.invoice-sync',
            'namespace' => null,
            'status' => 'running',
            'status_bucket' => 'running',
            'liveness_state' => null,
        ]);

        $withNamespace = VisibilityFilters::apply(WorkflowRunSummary::query(), [
            'namespace' => 'production',
        ])->pluck('id')
            ->all();

        $this->assertSame(['01JVISMIXEDFLEETMATCH00001'], $withNamespace);

        $withLiveness = VisibilityFilters::apply(WorkflowRunSummary::query(), [
            'liveness_state' => 'waiting_for_signal',
        ])->pluck('id')
            ->all();

        $this->assertSame(['01JVISMIXEDFLEETMATCH00001'], $withLiveness);

        $noFilters = WorkflowRunSummary::query()
            ->whereIn('workflow_instance_id', ['mixed-fleet-match', 'mixed-fleet-null-ns'])
            ->pluck('id')
            ->all();

        $this->assertCount(2, $noFilters);
    }

    /**
     * @param array<string, string>|null $searchAttributes
     */
    private function createSearchAttributeSummary(string $runId, string $instanceId, ?array $searchAttributes): void
    {
        WorkflowInstance::query()->create([
            'id' => $instanceId,
            'workflow_class' => 'BillingWorkflow',
            'workflow_type' => 'billing.invoice-sync',
            'run_count' => 1,
            'current_run_id' => $runId,
        ]);

        WorkflowRun::query()->create([
            'id' => $runId,
            'workflow_instance_id' => $instanceId,
            'run_number' => 1,
            'workflow_class' => 'BillingWorkflow',
            'workflow_type' => 'billing.invoice-sync',
            'status' => 'running',
        ]);

        WorkflowRunSummary::create([
            'id' => $runId,
            'workflow_instance_id' => $instanceId,
            'run_number' => 1,
            'is_current_run' => true,
            'engine_source' => 'v2',
            'class' => 'BillingWorkflow',
            'workflow_type' => 'billing.invoice-sync',
            'status' => 'running',
            'status_bucket' => 'running',
        ]);

        foreach ($searchAttributes ?? [] as $key => $value) {
            WorkflowSearchAttribute::query()->create([
                'workflow_run_id' => $runId,
                'workflow_instance_id' => $instanceId,
                'key' => $key,
                'type' => WorkflowSearchAttribute::TYPE_KEYWORD,
                'value_keyword' => $value,
                'upserted_at_sequence' => 1,
            ]);
        }
    }
}
