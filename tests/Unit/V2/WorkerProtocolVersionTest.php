<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Tests\NonDatabaseTestCase;
use Workflow\V2\Models\WorkflowSearchAttribute;
use Workflow\V2\Support\WorkerHistoryPayloadContract;
use Workflow\V2\Support\WorkerProtocolVersion;

final class WorkerProtocolVersionTest extends NonDatabaseTestCase
{
    public function testVersionIsNonEmptyString(): void
    {
        $this->assertNotEmpty(WorkerProtocolVersion::VERSION);
        $this->assertMatchesRegularExpression('/^\d+\.\d+$/', WorkerProtocolVersion::VERSION);
    }

    public function testVersionTracksServiceOperationCommandShape(): void
    {
        $this->assertSame('1.19', WorkerProtocolVersion::VERSION);
        $this->assertContains('start_service_operation', WorkerProtocolVersion::nonTerminalCommandTypes());
        $this->assertSame(0, WorkerProtocolVersion::longPollSemantics()['min_timeout_seconds']);
    }

    public function testVersionIncludesSignalWaitCommandShape(): void
    {
        $this->assertTrue(version_compare(WorkerProtocolVersion::VERSION, '1.9', '>='));
        $this->assertContains('open_signal_wait', WorkerProtocolVersion::nonTerminalCommandTypes());
    }

    public function testVersionIncludesFailWorkflowExceptionCommandShape(): void
    {
        $this->assertTrue(version_compare(WorkerProtocolVersion::VERSION, '1.10', '>='));
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

    public function testWorkerCapabilitiesRespectTheirProtocolFloors(): void
    {
        $this->assertSame(
            [
                'query_tasks',
                'memo_upserts',
                'message_streams',
                'typed_search_attributes',
                'condition_wait_occurrence_identity',
                'local_activities',
                'worker_sessions',
                'sticky_execution',
                'durable_selection',
            ],
            WorkerProtocolVersion::workerCapabilities(),
        );
        $this->assertSame([], WorkerProtocolVersion::workerCapabilitiesForVersion('1.7'));
        $this->assertSame(
            ['query_tasks', 'memo_upserts'],
            WorkerProtocolVersion::workerCapabilitiesForVersion('1.14'),
        );
        $this->assertSame(
            ['query_tasks', 'memo_upserts', 'message_streams'],
            WorkerProtocolVersion::workerCapabilitiesForVersion('1.15'),
        );
        $this->assertSame(
            ['query_tasks', 'memo_upserts', 'message_streams', 'typed_search_attributes'],
            WorkerProtocolVersion::workerCapabilitiesForVersion('1.16'),
        );
        $this->assertSame(
            [
                'query_tasks',
                'memo_upserts',
                'message_streams',
                'typed_search_attributes',
                'condition_wait_occurrence_identity',
            ],
            WorkerProtocolVersion::workerCapabilitiesForVersion('1.17'),
        );
        $this->assertSame(
            [
                'query_tasks',
                'memo_upserts',
                'message_streams',
                'typed_search_attributes',
                'condition_wait_occurrence_identity',
                'local_activities',
                'worker_sessions',
                'sticky_execution',
            ],
            WorkerProtocolVersion::workerCapabilitiesForVersion('1.18'),
        );
        $this->assertSame(
            [
                'query_tasks',
                'memo_upserts',
                'message_streams',
                'typed_search_attributes',
                'condition_wait_occurrence_identity',
                'local_activities',
                'worker_sessions',
                'sticky_execution',
                'durable_selection',
            ],
            WorkerProtocolVersion::workerCapabilitiesForVersion('1.19'),
        );
    }

    public function testNonTerminalCommandTypesAreFrozen(): void
    {
        $this->assertSame([
            'cancel_selection_operation',
            'schedule_activity',
            'start_timer',
            'start_child_workflow',
            'start_service_operation',
            'complete_update',
            'fail_update',
            'record_side_effect',
            'record_local_activity',
            'record_version_marker',
            'upsert_memo',
            'upsert_search_attributes',
            'open_condition_wait',
            'open_signal_wait',
        ], WorkerProtocolVersion::nonTerminalCommandTypes());
    }

    public function testSelectionCancellationAcceptsThePublishedNestedGroupKind(): void
    {
        $normalize = new \ReflectionMethod(
            \Workflow\V2\Support\DefaultWorkflowTaskBridge::class,
            'normalizeCancelSelectionOperationCommand',
        );
        $command = [
            'type' => 'cancel_selection_operation',
            'selection_group_id' => 'select-calls:1:3',
            'member_key' => 'work',
            'member_index' => 0,
            'member_base_sequence' => 1,
            'member_size' => 2,
            'operation_kind' => 'group',
            'operation_identity' => 'group:1',
        ];

        $this->assertSame($command, $normalize->invoke(null, $command));

        $command['operation_kind'] = 'mixed';
        $this->assertNull($normalize->invoke(null, $command));
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
        $this->assertSame(
            \Workflow\V2\Support\WorkflowCommandNormalizer::payloadEnvelopeFields(),
            $summary['workflow_task_command_payload_envelope_fields'],
        );
        $this->assertSame(WorkerProtocolVersion::nonTerminalCommandTypes(), $summary['non_terminal_command_types']);
        $this->assertSame(WorkerProtocolVersion::terminalCommandTypes(), $summary['terminal_command_types']);
        $this->assertSame(WorkerHistoryPayloadContract::manifest(), $summary['workflow_history_budget']);
        $this->assertSame(
            WorkerProtocolVersion::DEFAULT_HISTORY_PAGE_SIZE,
            $summary['history_pagination']['default_page_size']
        );
        $this->assertSame(
            WorkerProtocolVersion::MAX_HISTORY_PAGE_SIZE,
            $summary['history_pagination']['max_page_size']
        );
        $this->assertSame(\Workflow\Serializers\CodecRegistry::universal(), $summary['payload_codecs_universal']);
        $this->assertArrayNotHasKey('payload_codecs_engine_specific', $summary);
        $this->assertSame(
            WorkerProtocolVersion::REASON_UNSUPPORTED_PAYLOAD_CODEC,
            $summary['unsupported_payload_codec_reason']
        );
        $this->assertSame('unsupported_payload_codec', $summary['unsupported_payload_codec_reason']);
    }

    public function testDescribeIncludesUpsertSearchAttributesCommandShape(): void
    {
        $summary = WorkerProtocolVersion::describe();

        $this->assertArrayHasKey('upsert_search_attributes_command', $summary);

        $shape = $summary['upsert_search_attributes_command'];
        $this->assertSame('upsert_search_attributes', $shape['type']);
        $this->assertSame('non_terminal_command', $shape['category']);
        $this->assertSame('1.8', $shape['minimum_protocol_version']);
        $this->assertSame(['type', 'attributes'], $shape['required_fields']);
        $this->assertSame(['attribute_types'], $shape['optional_fields']);
        $this->assertSame('map<string, scalar|list<string>|null>', $shape['attributes']['shape']);
        $this->assertContains('list<string>', $shape['attributes']['value_types']);
        $this->assertSame('delete_attribute', $shape['attributes']['null_value']);
        $this->assertSame('list<string>', $shape['attributes']['list_values']['shape']);
        $this->assertSame(
            WorkflowSearchAttribute::TYPE_KEYWORD_LIST,
            $shape['attributes']['list_values']['search_attribute_type'],
        );
        $this->assertSame(
            WorkflowSearchAttribute::MAX_KEYWORD_LENGTH,
            $shape['attributes']['list_values']['max_entry_length'],
        );
        $this->assertSame('map<string, search_attribute_type>', $shape['attribute_types']['shape']);
        $this->assertFalse($shape['attribute_types']['required']);
        $this->assertSame('1.16', $shape['attribute_types']['minimum_protocol_version']);
        $this->assertSame(WorkflowSearchAttribute::VALID_TYPES, $shape['attribute_types']['valid_values']);
        $this->assertSame('reject_command', $shape['attribute_types']['invalid_values']);
        $this->assertSame(['sequence', 'attributes', 'attribute_types'], $shape['history']['replay_identity']);
    }

    public function testDescribeIncludesDurableSelectionSemantics(): void
    {
        $shape = WorkerProtocolVersion::describe()['durable_selection'];

        $this->assertSame('1.19', $shape['minimum_protocol_version']);
        $this->assertSame('durable_selection', $shape['worker_capability']);
        $this->assertSame('select', $shape['group_mode']);
        $this->assertSame('SelectionResolved', $shape['winner_history_event']);
        $this->assertSame('void', $shape['cancellation_request_result']);
        $this->assertSame(
            'advance_only_after_committed_noop_boundary',
            $shape['cancellation_replay_without_marker'],
        );
        $this->assertSame('non_empty_string_or_non_negative_integer', $shape['selection_key_domain']);
        $this->assertContains('selection_member_kind', $shape['command_metadata_fields']);
        $this->assertSame('durable_scheduled_or_open_history', $shape['cancellation_member_authority']);
        $this->assertSame(
            'preserve_terminal_result_without_cancellation_marker',
            $shape['terminal_before_cancellation'],
        );
        $this->assertSame(['continue', 'await', 'cancel'], $shape['non_winners']);
        $this->assertFalse($shape['implicit_cancellation']);
    }

    public function testDescribeIncludesPortableMemoCommandAndHistorySemantics(): void
    {
        $shape = WorkerProtocolVersion::describe()['upsert_memo_command'];

        $this->assertSame('upsert_memo', $shape['type']);
        $this->assertSame('1.14', $shape['minimum_protocol_version']);
        $this->assertSame('memo_upserts', $shape['worker_capability']);
        $this->assertSame(['type', 'entries'], $shape['required_fields']);
        $this->assertSame('payload-envelope<avro-map<string, Value|null>>', $shape['entries']['shape']);
        $this->assertTrue($shape['entries']['payload_envelope_field']);
        $this->assertSame('avro', $shape['entries']['codec']);
        $this->assertSame(\Workflow\V2\Support\MemoPayload::KEY_PATTERN, $shape['entries']['key_pattern']);
        $this->assertSame(0, preg_match('/' . $shape['entries']['key_pattern'] . '/D', '0'));
        $this->assertSame(0, preg_match('/' . $shape['entries']['key_pattern'] . '/D', '-12'));
        $this->assertSame(1, preg_match('/' . $shape['entries']['key_pattern'] . '/D', '0stage'));
        $this->assertSame('delete_key', $shape['entries']['null_value']);
        $this->assertSame('MemoUpserted', $shape['history']['event_type']);
        $this->assertSame(['sequence', 'entries'], $shape['history']['replay_identity']);
        $this->assertSame('payload-envelope<avro-map<string, Value>>', $shape['history']['merged_shape']);
        $this->assertSame('does_not_append_or_apply', $shape['idempotency']['duplicate_completion']);
        $this->assertSame(
            'merged memo is inherited before commands on the continued run',
            $shape['continue_as_new'],
        );
        $this->assertSame('runtime', $shape['external_payloads']['resolution_owner']);
        $this->assertFalse($shape['external_payloads']['sdk_storage_drivers']);
        $this->assertSame(['php', 'python', 'rust'], $shape['published_artifact_conformance']['worker_languages']);
        $this->assertSame(
            ['standalone_server', 'managed_cloud'],
            $shape['published_artifact_conformance']['runtime_targets'],
        );
    }

    public function testDescribeVersionGatesConditionWaitOccurrenceIdentity(): void
    {
        $shape = WorkerProtocolVersion::describe()['condition_wait_command'];

        $this->assertSame('open_condition_wait', $shape['type']);
        $this->assertSame('1.9', $shape['minimum_protocol_version']);
        $this->assertSame('1.17', $shape['condition_wait_occurrence_id']['minimum_protocol_version']);
        $this->assertSame(
            WorkerProtocolVersion::CAPABILITY_CONDITION_WAIT_OCCURRENCE_IDENTITY,
            $shape['condition_wait_occurrence_id']['worker_capability'],
        );
        $this->assertSame('condition_wait_occurrence_id', $shape['history']['occurrence_field']);
        $this->assertSame(
            [
                'sequence',
                'condition_wait_occurrence_id',
                'condition_key',
                'condition_definition_fingerprint',
                'timeout_seconds',
            ],
            $shape['history']['replay_identity'],
        );
        $this->assertTrue($shape['version_gate']['capable_workers_must_submit_occurrence_id']);
        $this->assertTrue($shape['version_gate']['servers_below_minimum_must_reject_before_execution']);
        $this->assertSame(400, $shape['version_gate']['rejection_status']);
        $this->assertSame('unsupported_protocol_version', $shape['version_gate']['rejection_reason']);
        $this->assertFalse(WorkerProtocolVersion::supportsConditionWaitOccurrenceIdentity('1.16'));
        $this->assertTrue(WorkerProtocolVersion::supportsConditionWaitOccurrenceIdentity('1.17'));
    }

    public function testDescribeIncludesFailWorkflowCommandShape(): void
    {
        $summary = WorkerProtocolVersion::describe();

        $this->assertArrayHasKey('fail_workflow_command', $summary);

        $shape = $summary['fail_workflow_command'];
        $this->assertSame('fail_workflow', $shape['type']);
        $this->assertSame('terminal_command', $shape['category']);
        $this->assertSame(['type', 'message'], $shape['required_fields']);
        $this->assertSame(
            ['exception_class', 'exception_type', 'exception', 'non_retryable'],
            $shape['optional_fields'],
        );
        $this->assertSame([
            'exception' => '1.10',
        ], $shape['field_minimum_protocol_versions'],);
        $this->assertSame('non-empty string', $shape['message']['shape']);
        $this->assertSame('string', $shape['exception_class']['shape']);
        $this->assertFalse($shape['exception_class']['required']);
        $this->assertSame('string', $shape['exception_type']['shape']);
        $this->assertFalse($shape['exception_type']['required']);
        $this->assertSame('array<string, mixed>', $shape['exception']['shape']);
        $this->assertFalse($shape['exception']['required']);
        $this->assertSame('1.10', $shape['exception']['minimum_protocol_version']);
        $this->assertSame('bool', $shape['non_retryable']['shape']);
        $this->assertFalse($shape['non_retryable']['required']);
    }

    public function testDescribeIncludesServiceOperationCommandShape(): void
    {
        $summary = WorkerProtocolVersion::describe();

        $this->assertArrayHasKey('service_operation_command', $summary);

        $shape = $summary['service_operation_command'];
        $this->assertSame('start_service_operation', $shape['type']);
        $this->assertSame('non_terminal_command', $shape['category']);
        $this->assertSame('1.13', $shape['minimum_protocol_version']);
        $this->assertSame(
            ['type', 'endpoint_name', 'service_name', 'operation_name', 'request_payload'],
            $shape['required_fields'],
        );
        $this->assertContains('payload_codec', $shape['optional_fields']);
        $this->assertContains('service_call_id', $shape['optional_fields']);
        $this->assertContains('metadata', $shape['optional_fields']);
        $this->assertSame(['sync', 'async'], $shape['mode_override']['valid_values']);
        $this->assertSame(['accepted', 'completed'], $shape['wait_for']['valid_values']);
        $this->assertContains('ServiceCallStarted', $shape['service_call_result_events']);
        $this->assertContains(
            'published_artifact_worker_execution',
            $shape['metadata']['reserved_conformance_keys'],
        );
    }

    public function testMessageStreamCompletionContractIsUnavailableBeforeProtocolOneFifteen(): void
    {
        $this->assertFalse(WorkerProtocolVersion::supportsMessageStreams('1.14'));
        $this->assertSame([], WorkerProtocolVersion::messageStreamCompletionFieldsForVersion('1.14'));
        $this->assertNotContains(
            WorkerProtocolVersion::CAPABILITY_MESSAGE_STREAMS,
            WorkerProtocolVersion::workerCapabilitiesForVersion('1.14'),
        );

        $this->assertTrue(WorkerProtocolVersion::supportsMessageStreams('1.15'));
        $this->assertSame(
            ['message_stream_cursors', 'message_stream_waits'],
            WorkerProtocolVersion::messageStreamCompletionFieldsForVersion('1.15'),
        );
        $this->assertContains(
            WorkerProtocolVersion::CAPABILITY_MESSAGE_STREAMS,
            WorkerProtocolVersion::workerCapabilitiesForVersion('1.15'),
        );

        foreach (['1.14.0', '2.15', 'not-a-version'] as $invalidOrDifferentMajor) {
            $this->assertFalse(WorkerProtocolVersion::supportsMessageStreams($invalidOrDifferentMajor));
        }
    }

    public function testDescribeIncludesMessageStreamCompletionShapes(): void
    {
        $shape = WorkerProtocolVersion::describe()['message_streams'];

        $this->assertSame('message_streams', $shape['feature']);
        $this->assertSame('1.15', $shape['minimum_protocol_version']);
        $this->assertSame('message_streams', $shape['worker_capability']);
        $this->assertSame('capabilities', $shape['registration_field']);
        $this->assertSame(
            ['message_stream_cursors', 'message_stream_waits'],
            array_keys($shape['completion_fields']),
        );

        $cursors = $shape['completion_fields']['message_stream_cursors'];
        $this->assertSame(100, $cursors['max_items']);
        $this->assertSame(['stream_name', 'through_position'], $cursors['item']['required_fields']);
        $this->assertSame(0, $cursors['item']['through_position']['minimum']);

        $waits = $shape['completion_fields']['message_stream_waits'];
        $this->assertSame(100, $waits['max_items']);
        $this->assertSame(['stream_name', 'after_position'], $waits['item']['required_fields']);
        $this->assertSame(0, $waits['item']['after_position']['minimum']);
        $this->assertTrue($shape['version_gate']['workers_below_minimum_must_not_advertise_capability']);
        $this->assertTrue($shape['version_gate']['workers_below_minimum_must_not_submit_completion_fields']);
        $this->assertSame('message_streams_unavailable', $shape['version_gate']['rejection_reason']);
    }

    public function testDescribeIncludesQueryTaskSemantics(): void
    {
        $summary = WorkerProtocolVersion::describe();

        $this->assertSame('query_tasks', WorkerProtocolVersion::CAPABILITY_QUERY_TASKS);
        $this->assertArrayHasKey('query_tasks', $summary);

        $queryTasks = $summary['query_tasks'];
        $this->assertSame(WorkerProtocolVersion::CAPABILITY_QUERY_TASKS, $queryTasks['feature']);
        $this->assertSame('1.8', $queryTasks['minimum_protocol_version']);
        $this->assertSame(WorkerProtocolVersion::CAPABILITY_QUERY_TASKS, $queryTasks['worker_capability']);
        $this->assertSame(WorkerProtocolVersion::queryTaskVerbs(), $queryTasks['verbs']);
        $this->assertSame('/api/worker/query-tasks', $queryTasks['path_prefix']);
        $this->assertSame('/api/worker/query-tasks/poll', $queryTasks['endpoints']['poll']['path']);
        $this->assertContains('poll_request_id', $queryTasks['endpoints']['poll']['request_fields']);
        $this->assertContains('timeout_seconds', $queryTasks['endpoints']['poll']['request_fields']);
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
        $this->assertTrue($queryTasks['poll']['poll_request_idempotency']);
        $this->assertSame('empty', $queryTasks['poll']['empty_response_poll_status']);
        $this->assertSame('workflow_task_pending', $queryTasks['poll']['workflow_task_pending_poll_status']);
        $this->assertContains('workflow_task_pending', $queryTasks['poll']['poll_statuses']);
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

        $facade = (new \ReflectionMethod(
            \Workflow\V2\WorkflowTaskBridge::class,
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
        $this->assertSame(
            WorkerProtocolVersion::DEFAULT_HISTORY_PAGE_SIZE,
            $facade->getDefaultValue(),
            'WorkflowTaskBridge facade historyPayloadPaginated default must use the protocol constant.',
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
        $this->assertGreaterThanOrEqual(0, $semantics['min_timeout_seconds']);
        $this->assertGreaterThanOrEqual($semantics['min_timeout_seconds'], $semantics['default_timeout_seconds']);
        $this->assertLessThanOrEqual($semantics['max_timeout_seconds'], $semantics['default_timeout_seconds']);
    }

    public function testClampLongPollTimeoutAllowsImmediateProbe(): void
    {
        $this->assertSame(0, WorkerProtocolVersion::clampLongPollTimeout(0));
    }

    public function testClampLongPollTimeoutClampsBelowMinimum(): void
    {
        $this->assertSame(
            WorkerProtocolVersion::MIN_LONG_POLL_TIMEOUT,
            WorkerProtocolVersion::clampLongPollTimeout(-1),
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
        $this->assertSame('durable-workflow.v2.local-activity.contract', $summary['local_activities']['schema']);
        $this->assertSame(1, $summary['local_activities']['version']);
        $this->assertSame('local', $summary['local_activities']['execution']['mode']);
        $this->assertSame('record_local_activity', $summary['local_activities']['command_type']);
        $this->assertSame(
            \Workflow\V2\Support\WorkflowCommandNormalizer::localActivityCommandContract(),
            $summary['local_activities']['command'],
        );
        $this->assertSame(
            '1.19',
            $summary['local_activities']['command']['attempt_reports']['required_from_protocol_version'],
        );
        $this->assertTrue(WorkerProtocolVersion::supportsLocalActivities('1.18'));
        $this->assertFalse(WorkerProtocolVersion::supportsLocalActivityAttemptReports('1.18'));
        $this->assertTrue(WorkerProtocolVersion::supportsLocalActivityAttemptReports('1.19'));
        $this->assertFalse($summary['local_activities']['execution']['ordinary_activity_task_created']);
        $this->assertSame(
            ['connection', 'queue', 'worker_session', 'schedule_to_start_timeout'],
            $summary['local_activities']['routing']['rejected_options'],
        );
        $this->assertSame(
            [
                'execution_mode' => 'local',
                'local_activity' => true,
            ],
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
        $this->assertSame('1.8', $summary['worker_sessions']['minimum_protocol_version']);
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
        $this->assertSame('1.8', $summary['worker_sessions']['rollout_safety']['minimum_protocol_version']);
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
        $this->assertContains(
            'registered_worker_heartbeat_staleness',
            $summary['worker_sessions']['failure_detection']
        );
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
