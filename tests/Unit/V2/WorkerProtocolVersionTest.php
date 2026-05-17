<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Tests\TestCase;
use Workflow\V2\Support\WorkerProtocolVersion;

final class WorkerProtocolVersionTest extends TestCase
{
    public function testVersionIsNonEmptyString(): void
    {
        $this->assertNotEmpty(WorkerProtocolVersion::VERSION);
        $this->assertMatchesRegularExpression('/^\d+\.\d+$/', WorkerProtocolVersion::VERSION);
    }

    public function testVersionTracksQueryTaskWorkerProtocolShape(): void
    {
        $this->assertSame('1.6', WorkerProtocolVersion::VERSION);
    }

    public function testWorkflowTaskVerbsIncludesAllBridgeMethods(): void
    {
        $verbs = WorkerProtocolVersion::workflowTaskVerbs();

        $this->assertContains('poll', $verbs);
        $this->assertContains('claim', $verbs);
        $this->assertContains('claimStatus', $verbs);
        $this->assertContains('historyPayload', $verbs);
        $this->assertContains('historyPayloadPaginated', $verbs);
        $this->assertContains('execute', $verbs);
        $this->assertContains('complete', $verbs);
        $this->assertContains('fail', $verbs);
        $this->assertContains('heartbeat', $verbs);
    }

    public function testActivityTaskVerbsIncludesAllBridgeMethods(): void
    {
        $verbs = WorkerProtocolVersion::activityTaskVerbs();

        $this->assertContains('poll', $verbs);
        $this->assertContains('claim', $verbs);
        $this->assertContains('claimStatus', $verbs);
        $this->assertContains('complete', $verbs);
        $this->assertContains('fail', $verbs);
        $this->assertContains('status', $verbs);
        $this->assertContains('heartbeat', $verbs);
    }

    public function testQueryTaskVerbsIncludesStandaloneWorkerOperations(): void
    {
        $this->assertSame(['poll', 'complete', 'fail'], WorkerProtocolVersion::queryTaskVerbs());
    }

    public function testWorkerCapabilitiesIncludeQueryTasks(): void
    {
        $this->assertSame(['query_tasks'], WorkerProtocolVersion::workerCapabilities());
    }

    public function testNonTerminalCommandTypesAreFrozen(): void
    {
        $this->assertSame([
            'schedule_activity',
            'start_timer',
            'start_child_workflow',
            'complete_update',
            'fail_update',
            'record_side_effect',
            'record_version_marker',
            'upsert_search_attributes',
        ], WorkerProtocolVersion::nonTerminalCommandTypes());
    }

    public function testTerminalCommandTypesAreFrozen(): void
    {
        $this->assertSame([
            'complete_workflow',
            'fail_workflow',
            'continue_as_new',
        ], WorkerProtocolVersion::terminalCommandTypes());
    }

    public function testDescribeReturnsFullProtocolSummary(): void
    {
        $summary = WorkerProtocolVersion::describe();

        $this->assertSame(WorkerProtocolVersion::VERSION, $summary['version']);
        $this->assertSame(WorkerProtocolVersion::workflowTaskVerbs(), $summary['workflow_task_verbs']);
        $this->assertSame(WorkerProtocolVersion::activityTaskVerbs(), $summary['activity_task_verbs']);
        $this->assertSame(WorkerProtocolVersion::queryTaskVerbs(), $summary['query_task_verbs']);
        $this->assertSame(WorkerProtocolVersion::workerCapabilities(), $summary['worker_capabilities']);
        $this->assertSame(WorkerProtocolVersion::nonTerminalCommandTypes(), $summary['non_terminal_command_types']);
        $this->assertSame(WorkerProtocolVersion::terminalCommandTypes(), $summary['terminal_command_types']);
        $this->assertSame(
            WorkerProtocolVersion::DEFAULT_HISTORY_PAGE_SIZE,
            $summary['history_pagination']['default_page_size']
        );
        $this->assertSame(
            WorkerProtocolVersion::MAX_HISTORY_PAGE_SIZE,
            $summary['history_pagination']['max_page_size']
        );
        $this->assertSame(
            \Workflow\Serializers\CodecRegistry::universal(),
            $summary['payload_codecs_universal']
        );
        $this->assertSame(
            \Workflow\Serializers\CodecRegistry::engineSpecific(),
            $summary['payload_codecs_engine_specific']
        );
        $this->assertSame(
            WorkerProtocolVersion::REASON_UNSUPPORTED_PAYLOAD_CODEC,
            $summary['unsupported_payload_codec_reason']
        );
        $this->assertSame('unsupported_payload_codec', $summary['unsupported_payload_codec_reason']);
    }

    public function testDescribeIncludesQueryTaskSemantics(): void
    {
        $summary = WorkerProtocolVersion::describe();

        $this->assertSame('query_tasks', WorkerProtocolVersion::CAPABILITY_QUERY_TASKS);
        $this->assertArrayHasKey('query_tasks', $summary);

        $queryTasks = $summary['query_tasks'];
        $this->assertSame(WorkerProtocolVersion::CAPABILITY_QUERY_TASKS, $queryTasks['feature']);
        $this->assertSame(WorkerProtocolVersion::VERSION, $queryTasks['minimum_protocol_version']);
        $this->assertSame(WorkerProtocolVersion::CAPABILITY_QUERY_TASKS, $queryTasks['worker_capability']);
        $this->assertSame(WorkerProtocolVersion::queryTaskVerbs(), $queryTasks['verbs']);
        $this->assertSame('/api/worker/query-tasks', $queryTasks['path_prefix']);
        $this->assertSame('/api/worker/query-tasks/poll', $queryTasks['endpoints']['poll']['path']);
        $this->assertSame(
            '/api/worker/query-tasks/{query_task_id}/complete',
            $queryTasks['endpoints']['complete']['path'],
        );
        $this->assertSame(
            '/api/worker/query-tasks/{query_task_id}/fail',
            $queryTasks['endpoints']['fail']['path'],
        );
        $this->assertTrue($queryTasks['poll']['leases_on_return']);
        $this->assertSame(WorkerProtocolVersion::longPollSemantics(), $queryTasks['poll']['long_poll']);
        $this->assertSame('empty', $queryTasks['poll']['empty_response_poll_status']);
        $this->assertTrue($queryTasks['poll']['requires_registered_worker']);
        $this->assertSame(
            WorkerProtocolVersion::CAPABILITY_QUERY_TASKS,
            $queryTasks['poll']['requires_worker_capability'],
        );
        $this->assertContains('query_task_id', $queryTasks['task_fields']);
        $this->assertContains('query_name', $queryTasks['task_fields']);
        $this->assertContains('history_export', $queryTasks['task_fields']);
        $this->assertSame(
            ['codec', 'blob', 'external_storage'],
            $queryTasks['completion']['result_envelope_fields'],
        );
        $this->assertSame(
            ['message', 'reason', 'type', 'stack_trace', 'validation_errors'],
            $queryTasks['failure']['failure_fields'],
        );
        $this->assertContains('rejected_unknown_query', $queryTasks['failure']['known_reasons']);
        $this->assertContains('invalid_query_arguments', $queryTasks['failure']['known_reasons']);
        $this->assertFalse($queryTasks['durability']['history_event_appended']);
        $this->assertFalse($queryTasks['durability']['workflow_command_created']);
        $this->assertTrue($queryTasks['durability']['result_resolves_waiting_query_request']);
    }

    public function testDefaultHistoryPageSizeIsReasonable(): void
    {
        $this->assertGreaterThan(0, WorkerProtocolVersion::DEFAULT_HISTORY_PAGE_SIZE);
        $this->assertLessThanOrEqual(
            WorkerProtocolVersion::MAX_HISTORY_PAGE_SIZE,
            WorkerProtocolVersion::DEFAULT_HISTORY_PAGE_SIZE,
        );
    }

    /**
     * Regression for TD-080: bridge contract and default implementation must
     * use {@see WorkerProtocolVersion::DEFAULT_HISTORY_PAGE_SIZE} as the
     * historyPayloadPaginated default — a hard-coded literal here would let
     * the wire-protocol advertised default and the package call default
     * silently drift apart again.
     */
    public function testHistoryPayloadPaginatedDefaultsMatchProtocolConstant(): void
    {
        $contract = (new \ReflectionMethod(
            \Workflow\V2\Contracts\WorkflowTaskBridge::class,
            'historyPayloadPaginated',
        ))->getParameters()[2];

        $bridge = (new \ReflectionMethod(
            \Workflow\V2\Support\DefaultWorkflowTaskBridge::class,
            'historyPayloadPaginated',
        ))->getParameters()[2];

        $this->assertSame(
            WorkerProtocolVersion::DEFAULT_HISTORY_PAGE_SIZE,
            $contract->getDefaultValue(),
            'WorkflowTaskBridge::historyPayloadPaginated default must use the protocol constant.',
        );
        $this->assertSame(
            WorkerProtocolVersion::DEFAULT_HISTORY_PAGE_SIZE,
            $bridge->getDefaultValue(),
            'DefaultWorkflowTaskBridge::historyPayloadPaginated default must use the protocol constant.',
        );
    }

    public function testSupportedHistoryEncodingsAreFrozen(): void
    {
        $this->assertSame(['gzip', 'deflate'], WorkerProtocolVersion::supportedHistoryEncodings());
    }

    public function testCompressionThresholdIsPositive(): void
    {
        $this->assertGreaterThan(0, WorkerProtocolVersion::COMPRESSION_THRESHOLD);
    }

    public function testLongPollSemanticsContainsAllFields(): void
    {
        $semantics = WorkerProtocolVersion::longPollSemantics();

        $this->assertArrayHasKey('default_timeout_seconds', $semantics);
        $this->assertArrayHasKey('min_timeout_seconds', $semantics);
        $this->assertArrayHasKey('max_timeout_seconds', $semantics);
        $this->assertGreaterThan(0, $semantics['default_timeout_seconds']);
        $this->assertGreaterThan(0, $semantics['min_timeout_seconds']);
        $this->assertGreaterThanOrEqual($semantics['min_timeout_seconds'], $semantics['default_timeout_seconds']);
        $this->assertLessThanOrEqual($semantics['max_timeout_seconds'], $semantics['default_timeout_seconds']);
    }

    public function testClampLongPollTimeoutClampsBelowMinimum(): void
    {
        $this->assertSame(
            WorkerProtocolVersion::MIN_LONG_POLL_TIMEOUT,
            WorkerProtocolVersion::clampLongPollTimeout(0),
        );
    }

    public function testClampLongPollTimeoutClampsAboveMaximum(): void
    {
        $this->assertSame(
            WorkerProtocolVersion::MAX_LONG_POLL_TIMEOUT,
            WorkerProtocolVersion::clampLongPollTimeout(999),
        );
    }

    public function testClampLongPollTimeoutPassesThroughValidValue(): void
    {
        $this->assertSame(15, WorkerProtocolVersion::clampLongPollTimeout(15));
    }

    public function testDescribeIncludesCompressionAndLongPoll(): void
    {
        $summary = WorkerProtocolVersion::describe();

        $this->assertArrayHasKey('history_compression', $summary);
        $this->assertSame(
            WorkerProtocolVersion::supportedHistoryEncodings(),
            $summary['history_compression']['supported_encodings'],
        );
        $this->assertSame(
            WorkerProtocolVersion::COMPRESSION_THRESHOLD,
            $summary['history_compression']['compression_threshold'],
        );

        $this->assertArrayHasKey('long_poll', $summary);
        $this->assertSame(WorkerProtocolVersion::longPollSemantics(), $summary['long_poll']);

        $this->assertArrayHasKey('sticky_execution', $summary);
        $this->assertSame('sticky_execution', $summary['sticky_execution']['feature']);
        $this->assertSame('cold_replay', $summary['sticky_execution']['correctness_fallback']);
    }

    public function testDescribeIncludesLocalActivityContract(): void
    {
        $summary = WorkerProtocolVersion::describe();

        $this->assertArrayHasKey('local_activities', $summary);
        $this->assertSame(
            'durable-workflow.v2.local-activity.contract',
            $summary['local_activities']['schema'],
        );
        $this->assertSame(1, $summary['local_activities']['version']);
        $this->assertSame('local', $summary['local_activities']['execution']['mode']);
        $this->assertFalse($summary['local_activities']['execution']['ordinary_activity_task_created']);
        $this->assertSame(
            ['connection', 'queue', 'worker_session', 'schedule_to_start_timeout'],
            $summary['local_activities']['routing']['rejected_options'],
        );
        $this->assertSame(
            ['execution_mode' => 'local', 'local_activity' => true],
            $summary['local_activities']['execution']['history_marker'],
        );
    }

    public function testDescribeIncludesWorkerSessionSemantics(): void
    {
        $summary = WorkerProtocolVersion::describe();

        $this->assertSame(['create', 'heartbeat', 'close'], WorkerProtocolVersion::workerSessionVerbs());
        $this->assertSame(WorkerProtocolVersion::workerSessionVerbs(), $summary['worker_session_verbs']);
        $this->assertArrayHasKey('worker_sessions', $summary);
        $this->assertSame('worker_sessions', $summary['worker_sessions']['feature']);
        $this->assertSame(WorkerProtocolVersion::VERSION, $summary['worker_sessions']['minimum_protocol_version']);
        $this->assertSame('worker_session', $summary['worker_sessions']['command_field']);
        $this->assertSame(['create', 'heartbeat', 'close'], $summary['worker_sessions']['verbs']);
        $this->assertSame(
            'lazy_create_on_first_admitted_activity_or_explicit_worker_create',
            $summary['worker_sessions']['lifecycle']['creation'],
        );
        $this->assertTrue($summary['worker_sessions']['admission']['queue_routing_first']);
        $this->assertTrue(
            $summary['worker_sessions']['rollout_safety']['mixed_server_rollout_fenced_by_protocol_version'],
        );
        $this->assertTrue(
            $summary['worker_sessions']['rollout_safety']['servers_below_minimum_must_reject_worker_session_commands'],
        );
        $this->assertSame(
            'worker_registration',
            $summary['worker_sessions']['limits']['max_concurrent_worker_sessions'],
        );
        $this->assertTrue(
            $summary['worker_sessions']['holder_loss']['process_local_state_must_be_rebuilt_after_reacquire'],
        );
        $this->assertTrue(
            $summary['worker_sessions']['cancellation']['session_lease_does_not_override_activity_cancel_requested'],
        );
        $this->assertContains('active', $summary['worker_sessions']['statuses']);
        $this->assertContains('orphaned', $summary['worker_sessions']['visibility']);
        $this->assertSame(['closed'], $summary['worker_sessions']['terminal_statuses']);
        $this->assertContains('ttl_expired', $summary['worker_sessions']['terminal_conditions']);
        $this->assertContains('registered_worker_heartbeat_staleness', $summary['worker_sessions']['failure_detection']);
        $this->assertContains(
            'prefer_ordinary_queued_activities_for_independent_steps',
            $summary['worker_sessions']['authoring_guidance'],
        );
    }

    public function testDescribeIncludesInvocableCarrierSemantics(): void
    {
        $summary = WorkerProtocolVersion::describe();

        $this->assertArrayHasKey('invocable_carrier', $summary);
        $carrier = $summary['invocable_carrier'];
        $this->assertSame('invocable_http_carrier', $carrier['feature']);
        $this->assertSame(['activity_task'], $carrier['scope']);
        $this->assertSame('POST', $carrier['request']['method']);
        $this->assertSame(
            'application/vnd.durable-workflow.external-task-input+json',
            $carrier['request']['content_type'],
        );
        $this->assertSame('durable-workflow.v2.external-task-input', $carrier['request']['body_schema']);
        $this->assertSame(1, $carrier['request']['body_schema_version']);
        $this->assertSame(200, $carrier['response']['success_status']);
        $this->assertSame(
            'application/vnd.durable-workflow.external-task-result+json',
            $carrier['response']['content_type'],
        );
        $this->assertSame('durable-workflow.v2.external-task-result', $carrier['response']['body_schema']);
        $this->assertSame(1, $carrier['response']['body_schema_version']);
        $this->assertContains('application', $carrier['failure_kinds']);
        $this->assertContains('unsupported_payload', $carrier['failure_kinds']);
        $this->assertContains('application_error', $carrier['failure_classifications']);
        $this->assertContains('unsupported_payload_codec', $carrier['failure_classifications']);
        $this->assertContains('unsupported_payload_reference', $carrier['failure_classifications']);
        $this->assertSame('worker_protocol.invocable_carrier_contract', $carrier['cluster_info_path']);
    }

    public function testInvocableCarrierSemanticsNonGoalsExcludeWorkflowReplay(): void
    {
        $carrier = WorkerProtocolVersion::invocableCarrierSemantics();

        $this->assertContains('workflow_task_execution', $carrier['explicit_non_goals']);
        $this->assertContains('workflow_replay', $carrier['explicit_non_goals']);
        $this->assertContains('history_mutation', $carrier['explicit_non_goals']);
    }
}
