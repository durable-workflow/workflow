<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;
use Workflow\Serializers\CodecDecodeException;
use Workflow\Serializers\CodecRegistry;
use Workflow\V2\Models\WorkflowSearchAttribute;

/**
 * Validates and normalizes workflow commands reported by a worker on a
 * workflow-task completion.
 *
 * This is the grammar the server contract accepts — any SDK (PHP, Python,
 * future Go/TS) produces commands in this shape, and the normalizer is the
 * authoritative mapping from raw JSON into canonical command arrays the
 * workflow task bridge consumes.
 *
 * Retry and timeout fields are scope-checked: durable activity retry policy
 * and per-attempt/total/heartbeat timeouts only belong on `schedule_activity`,
 * durable child workflow retry policy and execution/run timeouts only belong
 * on `start_child_workflow`, Nexus service-call admission settings only belong
 * on `start_service_operation`, and the worker-side `non_retryable` failure
 * marker belongs on workflow/update failures and recorded local-activity
 * failures. Structured
 * exception payloads belong on `fail_workflow` so terminal worker failures can
 * preserve the original typed failure. Workflow failure itself is non-retryable,
 * and the SDK HTTP transport retry policy is a client concern that does not
 * appear in the workflow task command stream.
 *
 * Extracted from App\Http\Controllers\Api\WorkerController so the server
 * is no longer the source of truth for the command grammar.
 * HTTP hosts use the package-owned parallel metadata rules and preflight
 * below when they must reject malformed commands before task ownership is
 * inspected. Keep parallel command fields, kinds, identities, paths, and
 * structural limits here rather than mirroring them in a transport.
 *
 * @api Stable class surface consumed by the standalone workflow-server.
 *      The public static method signatures on this class are covered by
 *      the workflow package's semver guarantee. See docs/api-stability.md.
 */
final class WorkflowCommandNormalizer
{
    public const MAX_LOCAL_ACTIVITY_ATTEMPTS = 100;

    public const MAX_LOCAL_ACTIVITY_HEARTBEATS_PER_ATTEMPT = 1000;

    public const MAX_LOCAL_ACTIVITY_HEARTBEATS = 1000;

    /**
     * @var list<string>
     */
    private const LOCAL_ACTIVITY_RETRY_POLICY_FIELDS = [
        'max_attempts',
        'backoff_seconds',
        'non_retryable_error_types',
    ];

    /**
     * @var list<string>
     */
    private const LOCAL_ACTIVITY_ATTEMPT_FIELDS = [
        'attempt_id',
        'attempt_number',
        'outcome',
        'duration_ms',
        'message',
        'exception_type',
        'non_retryable',
        'timeout_kind',
        'retry_reason',
        'backoff_seconds',
        'heartbeats',
    ];

    /**
     * @var list<string>
     */
    private const LOCAL_ACTIVITY_HEARTBEAT_FIELDS = ['elapsed_ms', 'details'];

    /**
     * Scope contract for retry/timeout/failure fields. Each entry lists the
     * command types that legitimately accept the field and a short guidance
     * sentence that names the correct layer.
     *
     * @var array<string, array{allowed: list<string>, guidance: string}>
     */
    private const FIELD_SCOPES = [
        'retry_policy' => [
            'allowed' => ['schedule_activity', 'record_local_activity', 'start_child_workflow'],
            'guidance' => 'Configure activity retries on a schedule_activity command, or child workflow retries on a start_child_workflow command. Workflow failure itself is non-retryable, and SDK HTTP transport retry is a client concern that does not appear in the workflow task command stream.',
        ],
        'start_to_close_timeout' => [
            'allowed' => ['schedule_activity', 'record_local_activity'],
            'guidance' => 'start_to_close_timeout limits one activity attempt and only applies to a schedule_activity command.',
        ],
        'schedule_to_start_timeout' => [
            'allowed' => ['schedule_activity'],
            'guidance' => 'schedule_to_start_timeout limits queue wait before an activity attempt starts and only applies to a schedule_activity command.',
        ],
        'schedule_to_close_timeout' => [
            'allowed' => ['schedule_activity', 'record_local_activity'],
            'guidance' => 'schedule_to_close_timeout limits the entire activity execution including retries and only applies to a schedule_activity command.',
        ],
        'heartbeat_timeout' => [
            'allowed' => ['schedule_activity', 'record_local_activity'],
            'guidance' => 'heartbeat_timeout limits the gap between activity heartbeats and only applies to a schedule_activity command.',
        ],
        'worker_session' => [
            'allowed' => ['schedule_activity'],
            'guidance' => 'worker_session pins a sequence of activity attempts to one worker and only applies to a schedule_activity command.',
        ],
        'execution_timeout_seconds' => [
            'allowed' => ['start_child_workflow'],
            'guidance' => 'execution_timeout_seconds limits the entire child workflow execution and only applies to a start_child_workflow command.',
        ],
        'run_timeout_seconds' => [
            'allowed' => ['start_child_workflow'],
            'guidance' => 'run_timeout_seconds limits one child workflow run and only applies to a start_child_workflow command.',
        ],
        'non_retryable' => [
            'allowed' => ['fail_workflow', 'fail_update', 'record_local_activity'],
            'guidance' => 'non_retryable marks a workflow, update, or recorded local-activity failure as non-retryable. Durable activity non-retryable error types belong inside the schedule_activity retry_policy.non_retryable_error_types list.',
        ],
        'timeout_kind' => [
            'allowed' => ['record_local_activity'],
            'guidance' => 'timeout_kind identifies the bounded timeout that ended a recorded local activity and only applies to a record_local_activity command.',
        ],
        'exception' => [
            'allowed' => ['fail_workflow'],
            'guidance' => 'exception carries the structured terminal workflow failure payload and only applies to a fail_workflow command.',
        ],
        'parent_close_policy' => [
            'allowed' => ['start_child_workflow'],
            'guidance' => 'parent_close_policy declares how a child workflow reacts when its parent closes and only applies to a start_child_workflow command.',
        ],
        'delay_seconds' => [
            'allowed' => ['start_timer'],
            'guidance' => 'delay_seconds is the timer delay and only applies to a start_timer command.',
        ],
        'timeout_seconds' => [
            'allowed' => ['open_condition_wait', 'open_signal_wait'],
            'guidance' => 'timeout_seconds only applies to open_condition_wait or open_signal_wait. For activities use start_to_close_timeout / schedule_to_start_timeout / schedule_to_close_timeout / heartbeat_timeout; for child workflows use execution_timeout_seconds / run_timeout_seconds.',
        ],
        'payload_codec' => [
            'allowed' => [
                'complete_workflow',
                'schedule_activity',
                'start_child_workflow',
                'continue_as_new',
                'complete_update',
                'record_side_effect',
                'record_local_activity',
                'start_service_operation',
            ],
            'guidance' => 'payload_codec identifies the codec used for command payload bytes and only applies to commands that carry result, arguments, or request_payload bytes.',
        ],
        'entries' => [
            'allowed' => ['upsert_memo'],
            'guidance' => 'entries contains the non-indexed memo patch and only applies to an upsert_memo command.',
        ],
    ];

    /**
     * Command payload fields that accept a raw serialized string, a
     * `{codec, blob}` envelope, or a `{codec, external_storage}` envelope.
     *
     * Server-side HTTP ingress resolves external payload references before
     * normalization and uses this package-owned map so it does not need a
     * duplicate allow-list for codec-bearing bridge payloads.
     *
     * @var array<string, list<string>>
     */
    private const PAYLOAD_ENVELOPE_FIELDS = [
        'complete_workflow' => ['result'],
        'schedule_activity' => ['arguments'],
        'start_child_workflow' => ['arguments'],
        'continue_as_new' => ['arguments'],
        'complete_update' => ['result'],
        'record_side_effect' => ['result'],
        'record_local_activity' => ['arguments', 'result'],
        'start_service_operation' => ['request_payload'],
        'upsert_memo' => ['entries'],
    ];

    /**
     * @var array<string, string>
     */
    private const PARALLEL_COMMAND_LEAF_KINDS = [
        'schedule_activity' => 'activity',
        'start_timer' => 'timer',
        'start_child_workflow' => 'child',
        'open_condition_wait' => 'condition',
        'open_signal_wait' => 'signal',
    ];

    /**
     * @var list<string>
     */
    private const PARALLEL_METADATA_FIELDS = [
        'parallel_group_id',
        'parallel_group_kind',
        'parallel_group_mode',
        'parallel_group_base_sequence',
        'parallel_group_size',
        'parallel_group_index',
        'selection_member_key',
        'selection_member_index',
        'selection_member_base_sequence',
        'selection_member_size',
        'selection_member_kind',
    ];

    /**
     * @var list<string>
     */
    private const PARALLEL_METADATA_INTEGER_FIELDS = [
        'parallel_group_base_sequence',
        'parallel_group_size',
        'parallel_group_index',
        'selection_member_index',
        'selection_member_base_sequence',
        'selection_member_size',
    ];

    /**
     * Return the command payload-envelope grammar consumed by
     * {@see self::normalize()}.
     *
     * @return array<string, list<string>>
     */
    public static function payloadEnvelopeFields(): array
    {
        return self::PAYLOAD_ENVELOPE_FIELDS;
    }

    public static function acceptsPayloadEnvelope(string $commandType, string $field): bool
    {
        return in_array($field, self::PAYLOAD_ENVELOPE_FIELDS[$commandType] ?? [], true);
    }

    /**
     * Return the portable local-activity command grammar and identity/timing
     * mappings consumed by {@see self::normalize()} and the history bridge.
     *
     * @return array<string, mixed>
     */
    public static function localActivityCommandContract(): array
    {
        return [
            'type' => 'record_local_activity',
            'minimum_protocol_version' => WorkerProtocolVersion::PORTABLE_WORKER_AFFINITY_MINIMUM_PROTOCOL_VERSION,
            'required_fields' => ['activity_type', 'outcome', 'attempts'],
            'attempt_reports' => [
                'field' => 'attempts',
                'shape' => 'ordered_attempt_reports',
                'required_fields' => ['attempt_number', 'outcome'],
                'maximum' => self::MAX_LOCAL_ACTIVITY_ATTEMPTS,
                'required_from_protocol_version' =>
                    WorkerProtocolVersion::LOCAL_ACTIVITY_ATTEMPT_REPORTS_MINIMUM_PROTOCOL_VERSION,
                'worker_attempt_identity' => [
                    'report_field' => 'attempt_id',
                    'persistence_field' => 'worker_attempt_id',
                    'history_field' => 'worker_attempt_id',
                    'durable_field' => 'activity_attempt_id',
                ],
            ],
            'heartbeat_reports' => [
                'field' => 'heartbeats',
                'shape' => 'ordered_heartbeat_reports',
                'required_fields' => ['elapsed_ms'],
                'maximum_per_attempt' => self::MAX_LOCAL_ACTIVITY_HEARTBEATS_PER_ATTEMPT,
                'maximum_per_command' => self::MAX_LOCAL_ACTIVITY_HEARTBEATS,
                'elapsed_ms_origin' => 'attempt_started_at',
                'persisted_last_heartbeat_at' => 'attempt_started_at_plus_last_elapsed_ms',
            ],
            'rolling_upgrade' => [
                'retained_protocol_version' =>
                    WorkerProtocolVersion::PORTABLE_WORKER_AFFINITY_MINIMUM_PROTOCOL_VERSION,
                'missing_attempts' => 'normalize_to_one_terminal_attempt_without_worker_identity_or_heartbeats',
                'strict_shape_selected_by' => 'negotiated_worker_protocol_version',
                'retained_nested_object_unknown_fields' => 'ignore',
                'strict_nested_object_shape_from_protocol_version' =>
                    WorkerProtocolVersion::LOCAL_ACTIVITY_ATTEMPT_REPORTS_MINIMUM_PROTOCOL_VERSION,
            ],
        ];
    }

    /**
     * Return transport-level rules for retaining and type-checking every
     * parallel metadata field before semantic preflight. The semantic grammar
     * remains in {@see self::preflightParallelMetadata()} and
     * {@see self::normalize()}.
     *
     * @return array<string, list<string>>
     */
    public static function parallelMetadataValidationRules(): array
    {
        return [
            'commands.*.parallel_group_id' => ['nullable', 'string'],
            'commands.*.parallel_group_kind' => ['nullable', 'string'],
            'commands.*.parallel_group_mode' => ['nullable', 'string'],
            'commands.*.parallel_group_base_sequence' => ['nullable', 'integer', 'min:1'],
            'commands.*.parallel_group_size' => ['nullable', 'integer', 'min:1'],
            'commands.*.parallel_group_index' => ['nullable', 'integer', 'min:0'],
            'commands.*.selection_member_key' => ['nullable'],
            'commands.*.selection_member_index' => ['nullable', 'integer', 'min:0'],
            'commands.*.selection_member_base_sequence' => ['nullable', 'integer', 'min:1'],
            'commands.*.selection_member_size' => ['nullable', 'integer', 'min:1'],
            'commands.*.selection_member_kind' => ['nullable', 'string'],
            'commands.*.parallel_group_path' => ['nullable', 'array'],
            'commands.*.parallel_group_path.*.parallel_group_id' => ['required', 'string'],
            'commands.*.parallel_group_path.*.parallel_group_kind' => ['required', 'string'],
            'commands.*.parallel_group_path.*.parallel_group_mode' => ['nullable', 'string'],
            'commands.*.parallel_group_path.*.parallel_group_base_sequence' => ['required', 'integer', 'min:1'],
            'commands.*.parallel_group_path.*.parallel_group_size' => ['required', 'integer', 'min:1'],
            'commands.*.parallel_group_path.*.parallel_group_index' => ['required', 'integer', 'min:0'],
            'commands.*.parallel_group_path.*.selection_member_key' => ['nullable'],
            'commands.*.parallel_group_path.*.selection_member_index' => ['nullable', 'integer', 'min:0'],
            'commands.*.parallel_group_path.*.selection_member_base_sequence' => ['nullable', 'integer', 'min:1'],
            'commands.*.parallel_group_path.*.selection_member_size' => ['nullable', 'integer', 'min:1'],
            'commands.*.parallel_group_path.*.selection_member_kind' => ['nullable', 'string'],
        ];
    }

    /**
     * Validate and canonicalize the package-owned parallel command grammar
     * without normalizing unrelated command payloads. Servers call this before
     * checking task ownership so malformed input retains validation precedence.
     *
     * @param  list<array<string, mixed>>  $commands
     * @return list<array<string, mixed>>
     */
    public static function preflightParallelMetadata(array $commands): array
    {
        $commands = self::normalizeParallelMetadataIntegerFields($commands);
        $errors = [];

        foreach ($commands as $index => $command) {
            $type = $command['type'] ?? null;
            if (! is_string($type)) {
                continue;
            }

            self::assertParallelMetadataScope($type, $command, $index, $errors);
            $metadata = self::optionalParallelMetadataForCommand($command, $type, $index, $errors);

            if ($metadata === []) {
                continue;
            }

            foreach ([...self::PARALLEL_METADATA_FIELDS, 'parallel_group_path'] as $field) {
                unset($commands[$index][$field]);
            }

            $commands[$index] = [...$commands[$index], ...$metadata];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $commands;
    }

    /**
     * @param  list<array<string, mixed>>  $commands
     * @return list<array<string, mixed>>
     */
    public static function normalize(array $commands, ?string $protocolVersion = null): array
    {
        $protocolVersion ??= WorkerProtocolVersion::VERSION;
        $normalized = [];
        $errors = [];

        foreach ($commands as $index => $command) {
            $type = is_array($command) ? ($command['type'] ?? null) : null;

            if (! is_string($type)) {
                $errors["commands.{$index}.type"] = ['Each command must declare a supported type.'];

                continue;
            }

            self::assertCommandFieldScope($type, is_array($command) ? $command : [], $index, $errors);
            self::assertParallelMetadataScope($type, is_array($command) ? $command : [], $index, $errors);

            if ($type === 'cancel_selection_operation') {
                $groupId = $command['selection_group_id'] ?? null;
                $memberKey = $command['member_key'] ?? null;
                $memberIndex = $command['member_index'] ?? null;
                $memberBase = $command['member_base_sequence'] ?? null;
                $memberSize = $command['member_size'] ?? null;
                $operationKind = $command['operation_kind'] ?? null;
                $operationIdentity = $command['operation_identity'] ?? null;
                $matches = is_string($groupId)
                    && preg_match('/^select-calls:([1-9][0-9]*):([1-9][0-9]*)$/', $groupId, $groupParts) === 1;
                $groupBase = $matches ? (int) $groupParts[1] : null;
                $groupSize = $matches ? (int) $groupParts[2] : null;

                if (! $matches
                    || ! self::validSelectionKey($memberKey)
                    || ! is_int($memberIndex) || $memberIndex < 0 || $memberIndex >= $groupSize
                    || ! is_int($memberBase) || $memberBase < $groupBase
                    || ! is_int($memberSize) || $memberSize < 1
                    || $memberBase + $memberSize > $groupBase + $groupSize
                    || ! is_string($operationKind) || ! in_array(
                        $operationKind,
                        ['activity', 'child', 'timer', 'signal', 'condition', 'group'],
                        true,
                    )
                    || ! is_string($operationIdentity) || trim($operationIdentity) === ''
                    || ($operationKind === 'group'
                        && $operationIdentity !== sprintf('group:%d:%d', $memberBase, $memberSize))) {
                    $errors["commands.{$index}.selection_group_id"] = [
                        'Selection cancellation must identify one authored member within a durable selection group.',
                    ];

                    continue;
                }

                $normalized[] = [
                    'type' => $type,
                    'selection_group_id' => $groupId,
                    'member_key' => $memberKey,
                    'member_index' => $memberIndex,
                    'member_base_sequence' => $memberBase,
                    'member_size' => $memberSize,
                    'operation_kind' => $operationKind,
                    'operation_identity' => trim($operationIdentity),
                ];

                continue;
            }

            if ($type === 'complete_workflow') {
                $result = self::resolveCommandPayloadWithCodec($command, 'result', $index, $errors);
                $payloadCodec = self::payloadCodecForResolvedPayload($command, $result, 'result', $index, $errors);

                $normalized[] = array_filter([
                    'type' => $type,
                    'result' => $result['payload'],
                    'payload_codec' => is_string($result['payload']) ? $payloadCodec : null,
                ], static fn (mixed $value): bool => $value !== null);

                continue;
            }

            if ($type === 'fail_workflow') {
                if (! is_string($command['message'] ?? null) || trim((string) $command['message']) === '') {
                    $errors["commands.{$index}.message"] = ['Fail workflow commands require a non-empty message.'];

                    continue;
                }

                $exception = self::optionalExceptionPayload($command, $index, $errors);

                $normalized[] = array_filter([
                    'type' => $type,
                    'message' => $command['message'],
                    'exception_class' => is_string($command['exception_class'] ?? null)
                        ? $command['exception_class']
                        : null,
                    'exception_type' => is_string($command['exception_type'] ?? null)
                        ? $command['exception_type']
                        : null,
                    'exception' => $exception,
                    'non_retryable' => is_bool($command['non_retryable'] ?? null)
                        ? $command['non_retryable']
                        : null,
                ], static fn (mixed $value): bool => $value !== null);

                continue;
            }

            if ($type === 'schedule_activity') {
                if (! is_string($command['activity_type'] ?? null) || trim((string) $command['activity_type']) === '') {
                    $errors["commands.{$index}.activity_type"] = [
                        'Schedule activity commands require a non-empty activity_type.',
                    ];

                    continue;
                }

                $retryPolicy = self::optionalRetryPolicy($command, $index, $errors, 'Activity');
                $startToClose = self::optionalPositiveInt($command, 'start_to_close_timeout', $index, $errors);
                $scheduleToStart = self::optionalPositiveInt($command, 'schedule_to_start_timeout', $index, $errors);
                $scheduleToClose = self::optionalPositiveInt($command, 'schedule_to_close_timeout', $index, $errors);
                $heartbeat = self::optionalPositiveInt($command, 'heartbeat_timeout', $index, $errors);
                $workerSession = self::optionalWorkerSession($command, $index, $errors);
                $parallelMetadata = self::optionalParallelMetadataForCommand($command, $type, $index, $errors);

                self::assertActivityTimeoutOrdering($startToClose, $scheduleToClose, $heartbeat, $index, $errors);

                $arguments = self::resolveCommandArgumentsWithCodec($command, $index, $errors);
                $payloadCodec = self::payloadCodecForResolvedPayload(
                    $command,
                    $arguments,
                    'arguments',
                    $index,
                    $errors
                );

                $normalized[] = array_filter([
                    'type' => $type,
                    'activity_type' => trim($command['activity_type']),
                    'arguments' => $arguments['payload'],
                    'payload_codec' => $arguments['payload'] !== null ? $payloadCodec : null,
                    'connection' => self::optionalCommandString($command, 'connection', $index, $errors),
                    'queue' => self::optionalCommandString($command, 'queue', $index, $errors),
                    'retry_policy' => $retryPolicy,
                    'start_to_close_timeout' => $startToClose,
                    'schedule_to_start_timeout' => $scheduleToStart,
                    'schedule_to_close_timeout' => $scheduleToClose,
                    'heartbeat_timeout' => $heartbeat,
                    'worker_session' => $workerSession,
                    ...$parallelMetadata,
                ], static fn (mixed $value): bool => $value !== null);

                continue;
            }

            if ($type === 'record_local_activity') {
                if (! is_string($command['activity_type'] ?? null) || trim((string) $command['activity_type']) === '') {
                    $errors["commands.{$index}.activity_type"] = [
                        'Local activity records require a non-empty activity_type.',
                    ];

                    continue;
                }

                $outcome = self::optionalCommandString($command, 'outcome', $index, $errors);
                if ($outcome === null || ! in_array(
                    $outcome,
                    ['completed', 'failed', 'timed_out', 'cancelled'],
                    true
                )) {
                    $errors["commands.{$index}.outcome"] = [
                        'Local activity outcome must be completed, failed, timed_out, or cancelled.',
                    ];

                    continue;
                }

                $retryPolicy = self::optionalRetryPolicy($command, $index, $errors, 'Local activity');
                $startToClose = self::optionalPositiveInt($command, 'start_to_close_timeout', $index, $errors);
                $scheduleToClose = self::optionalPositiveInt($command, 'schedule_to_close_timeout', $index, $errors);
                $heartbeat = self::optionalPositiveInt($command, 'heartbeat_timeout', $index, $errors);
                self::assertActivityTimeoutOrdering($startToClose, $scheduleToClose, $heartbeat, $index, $errors);

                $nonRetryable = null;
                if (array_key_exists('non_retryable', $command)) {
                    if (! is_bool($command['non_retryable'])) {
                        $errors["commands.{$index}.non_retryable"] = [
                            'Local activity non_retryable must be boolean when provided.',
                        ];
                    } else {
                        $nonRetryable = $command['non_retryable'];
                        if ($outcome === 'completed') {
                            $errors["commands.{$index}.non_retryable"] = [
                                'Completed local activities cannot be marked non-retryable.',
                            ];
                        }
                    }
                }
                $attempts = self::normalizeLocalActivityAttempts(
                    $command,
                    $outcome,
                    $nonRetryable,
                    $retryPolicy,
                    $protocolVersion,
                    $index,
                    $errors,
                );
                $timeoutKind = self::optionalCommandString($command, 'timeout_kind', $index, $errors);
                if ($timeoutKind !== null && ! in_array(
                    $timeoutKind,
                    ['start_to_close', 'schedule_to_close', 'heartbeat'],
                    true,
                )) {
                    $errors["commands.{$index}.timeout_kind"] = [
                        'Local activity timeout_kind must be start_to_close, schedule_to_close, or heartbeat.',
                    ];
                }
                if ($outcome === 'timed_out' && $timeoutKind === null) {
                    $errors["commands.{$index}.timeout_kind"] = [
                        'Timed out local activities require a timeout_kind.',
                    ];
                }
                if ($outcome !== 'timed_out' && $timeoutKind !== null) {
                    $errors["commands.{$index}.timeout_kind"] = [
                        'Only timed out local activities may declare timeout_kind.',
                    ];
                }

                $arguments = self::resolveCommandArgumentsWithCodec($command, $index, $errors);
                $result = self::resolveCommandPayloadWithCodec($command, 'result', $index, $errors);
                $payloadCodec = self::payloadCodecForResolvedPayload(
                    $command,
                    $arguments['payload'] !== null ? $arguments : $result,
                    $arguments['payload'] !== null ? 'arguments' : 'result',
                    $index,
                    $errors,
                );
                if ($outcome === 'completed' && $result['payload'] === null) {
                    $errors["commands.{$index}.result"] = [
                        'Completed local activities require a result payload envelope.',
                    ];
                }
                if ($outcome !== 'completed' && (! is_string($command['message'] ?? null) || trim(
                    $command['message']
                ) === '')) {
                    $errors["commands.{$index}.message"] = [
                        'Failed, timed out, and cancelled local activities require a message.',
                    ];
                }

                $normalized[] = array_filter([
                    'type' => $type,
                    'activity_type' => trim($command['activity_type']),
                    'arguments' => $arguments['payload'],
                    'result' => $result['payload'],
                    'payload_codec' => $payloadCodec,
                    'outcome' => $outcome,
                    'message' => is_string($command['message'] ?? null) ? trim($command['message']) : null,
                    'exception_type' => self::optionalCommandString($command, 'exception_type', $index, $errors),
                    'non_retryable' => $nonRetryable,
                    'timeout_kind' => $timeoutKind,
                    'attempts' => $attempts,
                    'retry_policy' => $retryPolicy,
                    'start_to_close_timeout' => $startToClose,
                    'schedule_to_close_timeout' => $scheduleToClose,
                    'heartbeat_timeout' => $heartbeat,
                    'execution_mode' => 'local',
                ], static fn (mixed $value): bool => $value !== null);

                continue;
            }

            if ($type === 'start_timer') {
                if (! is_int($command['delay_seconds'] ?? null) || (int) $command['delay_seconds'] < 0) {
                    $errors["commands.{$index}.delay_seconds"] = [
                        'Start timer commands require a non-negative integer delay_seconds.',
                    ];

                    continue;
                }

                $normalized[] = [
                    'type' => $type,
                    'delay_seconds' => (int) $command['delay_seconds'],
                    ...self::optionalParallelMetadataForCommand($command, $type, $index, $errors),
                ];

                continue;
            }

            if ($type === 'start_child_workflow') {
                if (! is_string($command['workflow_type'] ?? null) || trim((string) $command['workflow_type']) === '') {
                    $errors["commands.{$index}.workflow_type"] = [
                        'Start child workflow commands require a non-empty workflow_type.',
                    ];

                    continue;
                }

                $parentClosePolicy = self::optionalCommandString($command, 'parent_close_policy', $index, $errors);
                $retryPolicy = self::optionalRetryPolicy($command, $index, $errors, 'Child workflow');
                $parallelMetadata = self::optionalParallelMetadataForCommand($command, $type, $index, $errors);

                if ($parentClosePolicy !== null && ! in_array(
                    $parentClosePolicy,
                    ['abandon', 'request_cancel', 'terminate'],
                    true
                )) {
                    $errors["commands.{$index}.parent_close_policy"] = [
                        'The parent_close_policy must be one of: abandon, request_cancel, terminate.',
                    ];

                    continue;
                }

                $executionTimeout = self::optionalPositiveInt($command, 'execution_timeout_seconds', $index, $errors);
                $runTimeout = self::optionalPositiveInt($command, 'run_timeout_seconds', $index, $errors);

                self::assertChildWorkflowTimeoutOrdering($executionTimeout, $runTimeout, $index, $errors);

                $arguments = self::resolveCommandArgumentsWithCodec($command, $index, $errors);
                $payloadCodec = self::payloadCodecForResolvedPayload(
                    $command,
                    $arguments,
                    'arguments',
                    $index,
                    $errors
                );

                $normalized[] = array_filter([
                    'type' => $type,
                    'workflow_type' => trim($command['workflow_type']),
                    'arguments' => $arguments['payload'],
                    'payload_codec' => $arguments['payload'] !== null ? $payloadCodec : null,
                    'connection' => self::optionalCommandString($command, 'connection', $index, $errors),
                    'queue' => self::optionalCommandString($command, 'queue', $index, $errors),
                    'parent_close_policy' => $parentClosePolicy,
                    'retry_policy' => $retryPolicy,
                    'execution_timeout_seconds' => $executionTimeout,
                    'run_timeout_seconds' => $runTimeout,
                    ...$parallelMetadata,
                ], static fn (mixed $value): bool => $value !== null);

                continue;
            }

            if ($type === 'start_service_operation') {
                $endpointName = self::requiredCommandString($command, 'endpoint_name', $index, $errors);
                $serviceName = self::requiredCommandString($command, 'service_name', $index, $errors);
                $operationName = self::requiredCommandString($command, 'operation_name', $index, $errors);
                $requestPayload = self::resolveCommandPayloadWithCodec($command, 'request_payload', $index, $errors);
                $payloadCodec = self::payloadCodecForResolvedPayload(
                    $command,
                    $requestPayload,
                    'request_payload',
                    $index,
                    $errors,
                );

                if ($endpointName === null || $serviceName === null || $operationName === null) {
                    continue;
                }

                if (! is_string($requestPayload['payload'] ?? null)) {
                    $errors["commands.{$index}.request_payload"] = [
                        'Start service operation commands require a string request_payload or payload envelope.',
                    ];

                    continue;
                }

                $modeOverride = self::optionalCommandString($command, 'mode_override', $index, $errors);
                if ($modeOverride !== null) {
                    $modeOverride = strtolower($modeOverride);
                    if (! in_array($modeOverride, ['sync', 'async'], true)) {
                        $errors["commands.{$index}.mode_override"] = [
                            'The mode_override must be one of: sync, async.',
                        ];

                        continue;
                    }
                }

                $waitFor = self::optionalCommandString($command, 'wait_for', $index, $errors);
                if ($waitFor !== null) {
                    $waitFor = strtolower($waitFor);
                    if (! in_array($waitFor, ['accepted', 'completed'], true)) {
                        $errors["commands.{$index}.wait_for"] = [
                            'The wait_for must be one of: accepted, completed.',
                        ];

                        continue;
                    }
                }

                $waitTimeoutSeconds = self::optionalNonNegativeInt(
                    $command,
                    'wait_timeout_seconds',
                    $index,
                    $errors,
                );

                $normalized[] = array_filter([
                    'type' => $type,
                    'endpoint_name' => $endpointName,
                    'service_name' => $serviceName,
                    'operation_name' => $operationName,
                    'request_payload' => $requestPayload['payload'],
                    'payload_codec' => $payloadCodec,
                    'namespace' => self::optionalCommandString($command, 'namespace', $index, $errors),
                    'caller_namespace' => self::optionalCommandString($command, 'caller_namespace', $index, $errors),
                    'service_call_id' => self::optionalCommandString($command, 'service_call_id', $index, $errors),
                    'idempotency_key' => self::optionalCommandString($command, 'idempotency_key', $index, $errors),
                    'mode_override' => $modeOverride,
                    'wait_for' => $waitFor,
                    'wait_timeout_seconds' => $waitTimeoutSeconds,
                    'target_workflow_instance_id' => self::optionalCommandString(
                        $command,
                        'target_workflow_instance_id',
                        $index,
                        $errors,
                    ),
                    'target_workflow_run_id' => self::optionalCommandString(
                        $command,
                        'target_workflow_run_id',
                        $index,
                        $errors,
                    ),
                    'connection' => self::optionalCommandString($command, 'connection', $index, $errors),
                    'queue' => self::optionalCommandString($command, 'queue', $index, $errors),
                    'business_key' => self::optionalCommandString($command, 'business_key', $index, $errors),
                    'labels' => self::optionalCommandArray($command, 'labels', $index, $errors),
                    'memo' => self::optionalCommandArray($command, 'memo', $index, $errors),
                    'search_attributes' => self::optionalCommandArray($command, 'search_attributes', $index, $errors),
                    'duplicate_start_policy' => self::optionalCommandString(
                        $command,
                        'duplicate_start_policy',
                        $index,
                        $errors,
                    ),
                    'metadata' => self::optionalCommandArray($command, 'metadata', $index, $errors),
                    'request_payload_reference' => self::optionalCommandString(
                        $command,
                        'request_payload_reference',
                        $index,
                        $errors,
                    ),
                    'principal_subject' => self::optionalCommandString($command, 'principal_subject', $index, $errors),
                    'principal_method' => self::optionalCommandString($command, 'principal_method', $index, $errors),
                    'principal_roles' => self::optionalCommandStringList(
                        $command,
                        'principal_roles',
                        $index,
                        $errors,
                    ),
                    'principal_tenant' => self::optionalCommandString($command, 'principal_tenant', $index, $errors),
                    'principal_claims' => self::optionalCommandArray($command, 'principal_claims', $index, $errors),
                ], static fn (mixed $value): bool => $value !== null);

                continue;
            }

            if ($type === 'continue_as_new') {
                $workflowType = self::optionalCommandString($command, 'workflow_type', $index, $errors);
                $queue = self::optionalCommandString($command, 'queue', $index, $errors);
                $arguments = self::resolveCommandArgumentsWithCodec($command, $index, $errors);
                $explicitPayloadCodec = self::optionalPayloadCodec($command, $index, $errors);

                if (
                    $explicitPayloadCodec !== null
                    && $arguments['codec'] !== null
                    && $explicitPayloadCodec !== $arguments['codec']
                ) {
                    $errors["commands.{$index}.payload_codec"] = [
                        'Workflow task command field [payload_codec] must match the arguments envelope codec.',
                    ];
                }

                $payloadCodec = $arguments['payload'] !== null
                    ? $explicitPayloadCodec ?? $arguments['codec']
                    : null;

                $normalized[] = array_filter([
                    'type' => $type,
                    'arguments' => $arguments['payload'],
                    'payload_codec' => $payloadCodec,
                    'workflow_type' => $workflowType,
                    'queue' => $queue,
                ], static fn (mixed $value): bool => $value !== null);

                continue;
            }

            if ($type === 'complete_update') {
                $updateId = self::requiredCommandString($command, 'update_id', $index, $errors);

                if ($updateId === null) {
                    continue;
                }

                $result = self::resolveCommandPayloadWithCodec($command, 'result', $index, $errors);
                $payloadCodec = self::payloadCodecForResolvedPayload($command, $result, 'result', $index, $errors);

                $normalized[] = array_filter([
                    'type' => $type,
                    'update_id' => $updateId,
                    'result' => $result['payload'],
                    'payload_codec' => is_string($result['payload']) ? $payloadCodec : null,
                ], static fn (mixed $value): bool => $value !== null);

                continue;
            }

            if ($type === 'fail_update') {
                $updateId = self::requiredCommandString($command, 'update_id', $index, $errors);

                if ($updateId === null) {
                    continue;
                }

                if (! is_string($command['message'] ?? null) || trim((string) $command['message']) === '') {
                    $errors["commands.{$index}.message"] = ['Fail update commands require a non-empty message.'];

                    continue;
                }

                $normalized[] = array_filter([
                    'type' => $type,
                    'update_id' => $updateId,
                    'message' => $command['message'],
                    'exception_class' => is_string($command['exception_class'] ?? null)
                        ? $command['exception_class']
                        : null,
                    'exception_type' => is_string($command['exception_type'] ?? null)
                        ? $command['exception_type']
                        : null,
                    'non_retryable' => is_bool($command['non_retryable'] ?? null)
                        ? $command['non_retryable']
                        : null,
                ], static fn (mixed $value): bool => $value !== null);

                continue;
            }

            if ($type === 'record_side_effect') {
                $result = self::resolveCommandPayloadWithCodec($command, 'result', $index, $errors);
                $payloadCodec = self::payloadCodecForResolvedPayload($command, $result, 'result', $index, $errors);

                if (! is_string($result['payload'] ?? null)) {
                    $errors["commands.{$index}.result"] = [
                        'Record side effect commands require a string result or payload envelope.',
                    ];

                    continue;
                }

                $normalized[] = array_filter([
                    'type' => $type,
                    'result' => $result['payload'],
                    'payload_codec' => $payloadCodec,
                ], static fn (mixed $value): bool => $value !== null);

                continue;
            }

            if ($type === 'record_version_marker') {
                $markerErrors = [];

                if (! is_string($command['change_id'] ?? null) || trim((string) $command['change_id']) === '') {
                    $markerErrors[] = 'change_id is required';
                }
                if (! is_int($command['version'] ?? null)) {
                    $markerErrors[] = 'version must be an integer';
                }
                if (! is_int($command['min_supported'] ?? null)) {
                    $markerErrors[] = 'min_supported must be an integer';
                }
                if (! is_int($command['max_supported'] ?? null)) {
                    $markerErrors[] = 'max_supported must be an integer';
                }

                if ($markerErrors !== []) {
                    $errors["commands.{$index}"] = $markerErrors;

                    continue;
                }

                $normalized[] = [
                    'type' => $type,
                    'change_id' => trim($command['change_id']),
                    'version' => (int) $command['version'],
                    'min_supported' => (int) $command['min_supported'],
                    'max_supported' => (int) $command['max_supported'],
                ];

                continue;
            }

            if ($type === 'upsert_search_attributes') {
                if (! is_array($command['attributes'] ?? null) || $command['attributes'] === []) {
                    $errors["commands.{$index}.attributes"] = [
                        'Upsert search attributes commands require a non-empty attributes object.',
                    ];

                    continue;
                }

                try {
                    $call = new UpsertSearchAttributesCall($command['attributes']);
                } catch (LogicException $e) {
                    $errors["commands.{$index}.attributes"] = [$e->getMessage()];

                    continue;
                }

                $attributeTypes = self::normalizeSearchAttributeTypes($command['attribute_types'] ?? null);

                try {
                    SearchAttributeUpsertService::assertDeclaredTypesCompatible($call, $attributeTypes);
                } catch (InvalidArgumentException $e) {
                    $errors["commands.{$index}.attribute_types"] = [$e->getMessage()];

                    continue;
                }

                $normalized[] = [
                    'type' => $type,
                    'attributes' => $call->attributes,
                ] + array_filter([
                    'attribute_types' => $attributeTypes,
                ], static fn (mixed $value): bool => $value !== []);

                continue;
            }

            if ($type === 'upsert_memo') {
                if (! is_array($command['entries'] ?? null) || $command['entries'] === []) {
                    $errors["commands.{$index}.entries"] = [
                        'Upsert memo commands require an Avro entries payload envelope.',
                    ];

                    continue;
                }

                try {
                    $resolved = PayloadEnvelopeResolver::resolveCommandPayloadWithCodec(
                        $command['entries'],
                        "commands.{$index}.entries",
                    );

                    if (($resolved['codec'] ?? null) !== MemoPayload::CODEC || ! is_string($resolved['payload'])) {
                        throw new InvalidArgumentException(
                            'Memo entries must use the standard Avro {codec, blob} payload envelope.',
                        );
                    }

                    $entriesEnvelope = MemoPayload::canonicalMapEnvelope([
                        'codec' => $resolved['codec'],
                        'blob' => $resolved['payload'],
                    ]);
                    $call = new UpsertMemosCall(MemoPayload::decodeEntries($entriesEnvelope));
                } catch (ValidationException $e) {
                    foreach ($e->errors() as $field => $messages) {
                        $errors[$field] = $messages;
                    }

                    continue;
                } catch (CodecDecodeException|InvalidArgumentException|LogicException $e) {
                    $errors["commands.{$index}.entries"] = [$e->getMessage()];

                    continue;
                }

                $oversizedKey = null;
                foreach ($call->memos as $key => $value) {
                    if ($value === null) {
                        continue;
                    }

                    if (MemoPayload::encodedSize($value) > \Workflow\V2\Models\WorkflowMemo::MAX_VALUE_SIZE_BYTES) {
                        $oversizedKey = $key;

                        break;
                    }
                }

                if ($oversizedKey !== null) {
                    $errors["commands.{$index}.entries.{$oversizedKey}"] = [sprintf(
                        'Memo values may not exceed %d bytes.',
                        \Workflow\V2\Models\WorkflowMemo::MAX_VALUE_SIZE_BYTES,
                    )];

                    continue;
                }

                $normalized[] = [
                    'type' => $type,
                    'entries' => $entriesEnvelope,
                ];

                continue;
            }

            if ($type === 'open_condition_wait') {
                $parallelMetadata = self::optionalParallelMetadataForCommand($command, $type, $index, $errors);
                $conditionKey = self::optionalCommandString($command, 'condition_key', $index, $errors);
                $conditionDefinitionFingerprint = self::optionalCommandString(
                    $command,
                    'condition_definition_fingerprint',
                    $index,
                    $errors,
                );
                $conditionWaitOccurrenceId = self::optionalCommandString(
                    $command,
                    'condition_wait_occurrence_id',
                    $index,
                    $errors,
                );
                $timeoutSeconds = null;

                if (array_key_exists('timeout_seconds', $command) && $command['timeout_seconds'] !== null) {
                    if (! is_int($command['timeout_seconds']) || (int) $command['timeout_seconds'] < 0) {
                        $errors["commands.{$index}.timeout_seconds"] = [
                            'Open condition wait timeout_seconds must be a non-negative integer when provided.',
                        ];

                        continue;
                    }

                    $timeoutSeconds = (int) $command['timeout_seconds'];
                }

                $normalized[] = array_filter([
                    'type' => $type,
                    'condition_key' => $conditionKey,
                    'condition_definition_fingerprint' => $conditionDefinitionFingerprint,
                    'condition_wait_occurrence_id' => $conditionWaitOccurrenceId,
                    'timeout_seconds' => $timeoutSeconds,
                    ...$parallelMetadata,
                ], static fn (mixed $value): bool => $value !== null);

                continue;
            }

            if ($type === 'open_signal_wait') {
                $parallelMetadata = self::optionalParallelMetadataForCommand($command, $type, $index, $errors);
                if (! is_string($command['signal_name'] ?? null) || trim((string) $command['signal_name']) === '') {
                    $errors["commands.{$index}.signal_name"] = [
                        'Open signal wait commands require a non-empty signal_name.',
                    ];

                    continue;
                }

                $timeoutSeconds = null;

                if (array_key_exists('timeout_seconds', $command) && $command['timeout_seconds'] !== null) {
                    if (! is_int($command['timeout_seconds']) || (int) $command['timeout_seconds'] < 0) {
                        $errors["commands.{$index}.timeout_seconds"] = [
                            'Open signal wait timeout_seconds must be a non-negative integer when provided.',
                        ];

                        continue;
                    }

                    $timeoutSeconds = (int) $command['timeout_seconds'];
                }

                $normalized[] = array_filter([
                    'type' => $type,
                    'signal_name' => trim((string) $command['signal_name']),
                    'timeout_seconds' => $timeoutSeconds,
                    ...$parallelMetadata,
                ], static fn (mixed $value): bool => $value !== null);

                continue;
            }

            $errors["commands.{$index}.type"] = [
                sprintf('Workflow task command type [%s] is not supported by the server yet.', $type),
            ];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $command
     * @param  array<string, mixed>|null  $retryPolicy
     * @param  array<string, list<string>>  $errors
     * @return list<array<string, mixed>>|null
     */
    private static function normalizeLocalActivityAttempts(
        array $command,
        string $terminalOutcome,
        ?bool $terminalNonRetryable,
        ?array $retryPolicy,
        string $protocolVersion,
        int $index,
        array &$errors,
    ): ?array {
        $rawAttempts = $command['attempts'] ?? null;

        if (! array_key_exists('attempts', $command)
            && WorkerProtocolVersion::supportsLocalActivities($protocolVersion)
            && ! WorkerProtocolVersion::supportsLocalActivityAttemptReports($protocolVersion)
        ) {
            $rawAttempts = [array_filter([
                'attempt_number' => 1,
                'outcome' => $terminalOutcome,
                'message' => is_string($command['message'] ?? null) ? trim($command['message']) : null,
                'exception_type' => self::optionalNestedString(
                    $command,
                    'exception_type',
                    "commands.{$index}",
                    $errors,
                ),
                'non_retryable' => $terminalNonRetryable,
                'timeout_kind' => is_string($command['timeout_kind'] ?? null)
                    ? trim($command['timeout_kind'])
                    : null,
                'heartbeats' => [],
            ], static fn (mixed $value): bool => $value !== null)];
        }

        if (! is_array($rawAttempts) || ! array_is_list($rawAttempts) || $rawAttempts === []) {
            $errors["commands.{$index}.attempts"] = ['Local activity attempts must be a non-empty ordered list.'];

            return null;
        }

        $attemptCount = count($rawAttempts);
        $strictReportShape = WorkerProtocolVersion::supportsLocalActivityAttemptReports($protocolVersion);
        $maxAttempts = is_int($retryPolicy['max_attempts'] ?? null)
            ? $retryPolicy['max_attempts']
            : 1;

        self::validateLocalActivityRetryPolicyShape($command, $strictReportShape, $index, $errors);

        if ($maxAttempts > self::MAX_LOCAL_ACTIVITY_ATTEMPTS) {
            $errors["commands.{$index}.retry_policy.max_attempts"] = [
                sprintf(
                    'Local activity retry policy max_attempts must not exceed %d.',
                    self::MAX_LOCAL_ACTIVITY_ATTEMPTS,
                ),
            ];
        }

        if ($attemptCount > self::MAX_LOCAL_ACTIVITY_ATTEMPTS) {
            $errors["commands.{$index}.attempts"] = [
                sprintf('Local activity reports may contain at most %d attempts.', self::MAX_LOCAL_ACTIVITY_ATTEMPTS),
            ];
        } elseif ($attemptCount > $maxAttempts) {
            $errors["commands.{$index}.attempts"] = [
                sprintf(
                    'Local activity report contains %d attempts but retry_policy.max_attempts is %d.',
                    $attemptCount,
                    $maxAttempts,
                ),
            ];
        }

        $backoffSchedule = is_array($retryPolicy['backoff_seconds'] ?? null)
            ? array_values($retryPolicy['backoff_seconds'])
            : [];
        if (count($backoffSchedule) > max(0, $maxAttempts - 1)) {
            $errors["commands.{$index}.retry_policy.backoff_seconds"] = [
                'Local activity retry backoff entries must not exceed the number of possible retries.',
            ];
        }

        $normalized = [];
        $attemptIds = [];
        $heartbeatCount = 0;
        $lastPosition = $attemptCount - 1;

        foreach ($rawAttempts as $position => $rawAttempt) {
            $path = "commands.{$index}.attempts.{$position}";
            if (! is_array($rawAttempt) || array_is_list($rawAttempt)) {
                $errors[$path] = ['Each local activity attempt must be an object.'];

                continue;
            }

            if ($strictReportShape) {
                self::rejectUnknownNestedFields(
                    $rawAttempt,
                    self::LOCAL_ACTIVITY_ATTEMPT_FIELDS,
                    $path,
                    'local activity attempt',
                    $errors,
                );
            }

            $expectedAttemptNumber = $position + 1;
            $attemptNumber = $rawAttempt['attempt_number'] ?? null;
            if (! is_int($attemptNumber) || $attemptNumber !== $expectedAttemptNumber) {
                $errors["{$path}.attempt_number"] = [
                    sprintf(
                        'Local activity attempt_number must be the ordered one-based value %d.',
                        $expectedAttemptNumber,
                    ),
                ];
            }

            $attemptOutcome = $rawAttempt['outcome'] ?? null;
            if (! is_string($attemptOutcome) || ! in_array(
                $attemptOutcome,
                ['completed', 'failed', 'timed_out', 'cancelled'],
                true,
            )) {
                $errors["{$path}.outcome"] = [
                    'Local activity attempt outcome must be completed, failed, timed_out, or cancelled.',
                ];

                continue;
            }

            $terminal = $position === $lastPosition;
            if ($terminal && $attemptOutcome !== $terminalOutcome) {
                $errors["{$path}.outcome"] = [
                    'The final local activity attempt outcome must match the command outcome.',
                ];
            }
            if (! $terminal && ! in_array($attemptOutcome, ['failed', 'timed_out'], true)) {
                $errors["{$path}.outcome"] = [
                    'Only failed or timed_out local activity attempts may be followed by a retry.',
                ];
            }

            $attemptId = self::optionalNestedString($rawAttempt, 'attempt_id', $path, $errors);
            if ($attemptId !== null && strlen($attemptId) > 255) {
                $errors["{$path}.attempt_id"] = ['Local activity attempt_id must not exceed 255 bytes.'];
            } elseif ($attemptId !== null && isset($attemptIds[$attemptId])) {
                $errors["{$path}.attempt_id"] = [
                    'Local activity attempt_id must be unique within the reported sequence.',
                ];
            } elseif ($attemptId !== null) {
                $attemptIds[$attemptId] = true;
            }

            $durationMs = null;
            if (array_key_exists('duration_ms', $rawAttempt)) {
                if (! is_int($rawAttempt['duration_ms']) || $rawAttempt['duration_ms'] < 0) {
                    $errors["{$path}.duration_ms"] = [
                        'Local activity attempt duration_ms must be a non-negative integer.',
                    ];
                } else {
                    $durationMs = $rawAttempt['duration_ms'];
                }
            }
            $message = self::optionalNestedString($rawAttempt, 'message', $path, $errors);
            $exceptionType = self::optionalNestedString($rawAttempt, 'exception_type', $path, $errors);
            if ($attemptOutcome !== 'completed' && $message === null) {
                $errors["{$path}.message"] = [
                    'Failed, timed out, and cancelled local activity attempts require a message.',
                ];
            }

            $nonRetryable = null;
            if (array_key_exists('non_retryable', $rawAttempt)) {
                if (! is_bool($rawAttempt['non_retryable'])) {
                    $errors["{$path}.non_retryable"] = [
                        'Local activity attempt non_retryable must be boolean when provided.',
                    ];
                } else {
                    $nonRetryable = $rawAttempt['non_retryable'];
                    if (! $terminal && $nonRetryable) {
                        $errors["{$path}.non_retryable"] = [
                            'A non-retryable local activity attempt cannot be followed by a retry.',
                        ];
                    }
                }
            }
            if ($terminal
                && $nonRetryable !== null
                && $nonRetryable !== ($terminalNonRetryable ?? false)
            ) {
                $errors["{$path}.non_retryable"] = [
                    'The terminal local activity attempt non_retryable value must match the command outcome.',
                ];
            }

            $timeoutKind = self::optionalNestedString($rawAttempt, 'timeout_kind', $path, $errors);
            if ($attemptOutcome === 'timed_out' && ($timeoutKind === null || ! in_array(
                $timeoutKind,
                ['start_to_close', 'schedule_to_close', 'heartbeat'],
                true,
            ))) {
                $errors["{$path}.timeout_kind"] = [
                    'Timed out local activity attempts require start_to_close, schedule_to_close, or heartbeat timeout_kind.',
                ];
            }
            if ($attemptOutcome !== 'timed_out' && $timeoutKind !== null) {
                $errors["{$path}.timeout_kind"] = [
                    'Only timed out local activity attempts may declare timeout_kind.',
                ];
            }

            $retryReason = self::optionalNestedString($rawAttempt, 'retry_reason', $path, $errors);
            $backoffSeconds = null;
            if (array_key_exists('backoff_seconds', $rawAttempt)) {
                if (! is_int($rawAttempt['backoff_seconds']) || $rawAttempt['backoff_seconds'] < 0) {
                    $errors["{$path}.backoff_seconds"] = [
                        'Local activity attempt backoff_seconds must be a non-negative integer.',
                    ];
                } else {
                    $backoffSeconds = $rawAttempt['backoff_seconds'];
                }
            }

            if (! $terminal) {
                $allowedRetryReasons = $attemptOutcome === 'timed_out'
                    ? ['timeout', 'cold_replay']
                    : ['failure', 'cold_replay'];
                if ($retryReason === null || ! in_array($retryReason, $allowedRetryReasons, true)) {
                    $errors["{$path}.retry_reason"] = [
                        sprintf(
                            'A retried %s local activity attempt requires a compatible retry_reason.',
                            $attemptOutcome,
                        ),
                    ];
                }
                if ($backoffSeconds === null) {
                    $errors["{$path}.backoff_seconds"] = [
                        'A retried local activity attempt requires backoff_seconds.',
                    ];
                } elseif (isset($backoffSchedule[$position]) && $backoffSchedule[$position] !== $backoffSeconds) {
                    $errors["{$path}.backoff_seconds"] = [
                        'Local activity attempt backoff_seconds must match retry_policy.backoff_seconds.',
                    ];
                }
            } elseif ($retryReason !== null || $backoffSeconds !== null) {
                $errors[$path] = [
                    'The terminal local activity attempt cannot declare retry_reason or backoff_seconds.',
                ];
            }

            $heartbeats = self::normalizeLocalActivityHeartbeats(
                $rawAttempt['heartbeats'] ?? null,
                $path,
                $strictReportShape,
                $errors,
            );
            $heartbeatCount += count($heartbeats);

            $normalized[] = array_filter([
                'attempt_id' => $attemptId,
                'attempt_number' => is_int($attemptNumber) ? $attemptNumber : $expectedAttemptNumber,
                'outcome' => $attemptOutcome,
                'duration_ms' => $durationMs,
                'message' => $message,
                'exception_type' => $exceptionType,
                'non_retryable' => $nonRetryable,
                'timeout_kind' => $timeoutKind,
                'retry_reason' => $retryReason,
                'backoff_seconds' => $backoffSeconds,
                'heartbeats' => $heartbeats,
            ], static fn (mixed $value): bool => $value !== null);
        }

        if ($heartbeatCount > self::MAX_LOCAL_ACTIVITY_HEARTBEATS) {
            $errors["commands.{$index}.attempts"] = [
                sprintf(
                    'Local activity reports may contain at most %d total heartbeats.',
                    self::MAX_LOCAL_ACTIVITY_HEARTBEATS,
                ),
            ];
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $command
     * @param array<string, list<string>> $errors
     */
    private static function validateLocalActivityRetryPolicyShape(
        array $command,
        bool $strictReportShape,
        int $index,
        array &$errors,
    ): void {
        $raw = $command['retry_policy'] ?? null;
        if (! is_array($raw)) {
            return;
        }

        $path = "commands.{$index}.retry_policy";
        if ($strictReportShape) {
            self::rejectUnknownNestedFields(
                $raw,
                self::LOCAL_ACTIVITY_RETRY_POLICY_FIELDS,
                $path,
                'local activity retry policy',
                $errors,
            );
        }

        foreach (['backoff_seconds', 'non_retryable_error_types'] as $field) {
            if (isset($raw[$field]) && is_array($raw[$field]) && ! array_is_list($raw[$field])) {
                $errors["{$path}.{$field}"] = ["Local activity retry policy {$field} must be an ordered list."];
            }
        }

        $types = $raw['non_retryable_error_types'] ?? null;
        if (is_array($types) && array_is_list($types) && count($types) !== count(array_unique($types, SORT_REGULAR))) {
            $errors["{$path}.non_retryable_error_types"] = [
                'Local activity retry policy non_retryable_error_types must not contain duplicates.',
            ];
        }
    }

    /**
     * @param array<string, mixed> $value
     * @param list<string> $allowed
     * @param array<string, list<string>> $errors
     */
    private static function rejectUnknownNestedFields(
        array $value,
        array $allowed,
        string $path,
        string $subject,
        array &$errors,
    ): void {
        foreach (array_keys($value) as $field) {
            if (! is_string($field) || in_array($field, $allowed, true)) {
                continue;
            }

            $errors["{$path}.{$field}"] = [
                sprintf('Field [%s] is not supported in a %s report.', $field, $subject),
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  array<string, list<string>>  $errors
     */
    private static function optionalNestedString(
        array $value,
        string $field,
        string $path,
        array &$errors,
    ): ?string {
        if (! array_key_exists($field, $value) || $value[$field] === null) {
            return null;
        }

        if (! is_string($value[$field]) || trim($value[$field]) === '') {
            $errors["{$path}.{$field}"] = [
                sprintf('Local activity attempt field [%s] must be a non-empty string.', $field),
            ];

            return null;
        }

        return trim($value[$field]);
    }

    /**
     * @param  array<string, list<string>>  $errors
     * @return list<array{elapsed_ms: int, details?: array<string, mixed>}>
     */
    private static function normalizeLocalActivityHeartbeats(
        mixed $value,
        string $attemptPath,
        bool $strictReportShape,
        array &$errors,
    ): array {
        if ($value === null) {
            return [];
        }

        if (! is_array($value) || ! array_is_list($value)) {
            $errors["{$attemptPath}.heartbeats"] = ['Local activity attempt heartbeats must be an ordered list.'];

            return [];
        }

        if (count($value) > self::MAX_LOCAL_ACTIVITY_HEARTBEATS_PER_ATTEMPT) {
            $errors["{$attemptPath}.heartbeats"] = [
                sprintf(
                    'A local activity attempt may contain at most %d heartbeats.',
                    self::MAX_LOCAL_ACTIVITY_HEARTBEATS_PER_ATTEMPT,
                ),
            ];
        }

        $heartbeats = [];
        $lastElapsedMs = -1;
        foreach ($value as $position => $heartbeat) {
            $path = "{$attemptPath}.heartbeats.{$position}";
            if (! is_array($heartbeat) || array_is_list($heartbeat)) {
                $errors[$path] = ['Each local activity heartbeat must be an object.'];

                continue;
            }

            if ($strictReportShape) {
                self::rejectUnknownNestedFields(
                    $heartbeat,
                    self::LOCAL_ACTIVITY_HEARTBEAT_FIELDS,
                    $path,
                    'local activity heartbeat',
                    $errors,
                );
            }

            $elapsedMs = $heartbeat['elapsed_ms'] ?? null;
            if (! is_int($elapsedMs) || $elapsedMs < 0) {
                $errors["{$path}.elapsed_ms"] = [
                    'Local activity heartbeat elapsed_ms must be a non-negative integer.',
                ];

                continue;
            }
            if ($elapsedMs < $lastElapsedMs) {
                $errors["{$path}.elapsed_ms"] = [
                    'Local activity heartbeat elapsed_ms values must be ordered monotonically.',
                ];
            }
            $lastElapsedMs = $elapsedMs;

            $details = null;
            if (array_key_exists('details', $heartbeat) && $heartbeat['details'] !== null) {
                if (! is_array($heartbeat['details'])
                    || ($heartbeat['details'] !== [] && array_is_list($heartbeat['details']))
                ) {
                    $errors["{$path}.details"] = [
                        'Local activity heartbeat details must be an object when provided.',
                    ];
                } else {
                    $details = $heartbeat['details'];
                }
            }

            $heartbeats[] = array_filter([
                'elapsed_ms' => $elapsedMs,
                'details' => $details,
            ], static fn (mixed $item): bool => $item !== null);
        }

        return $heartbeats;
    }

    /**
     * @param  array<string, mixed>  $command
     * @param  array<string, list<string>>  $errors
     * @return array<string, mixed>
     */
    private static function optionalParallelMetadataForCommand(
        array $command,
        string $type,
        int $index,
        array &$errors,
    ): array {
        $leafKind = self::PARALLEL_COMMAND_LEAF_KINDS[$type] ?? null;

        return $leafKind === null
            ? []
            : self::optionalParallelMetadata($command, $leafKind, $index, $errors);
    }

    /**
     * @param  array<string, mixed>  $command
     * @param  array<string, list<string>>  $errors
     */
    private static function assertParallelMetadataScope(
        string $type,
        array $command,
        int $index,
        array &$errors,
    ): void {
        if (array_key_exists($type, self::PARALLEL_COMMAND_LEAF_KINDS)) {
            return;
        }

        foreach ([...self::PARALLEL_METADATA_FIELDS, 'parallel_group_path'] as $field) {
            if (! array_key_exists($field, $command) || $command[$field] === null) {
                continue;
            }

            $errors["commands.{$index}.{$field}"] = [
                sprintf(
                    '%s is only supported for activity, timer, and child-workflow scheduling commands.',
                    $field,
                ),
            ];
        }
    }

    /**
     * @param  list<array<string, mixed>>  $commands
     * @return list<array<string, mixed>>
     */
    private static function normalizeParallelMetadataIntegerFields(array $commands): array
    {
        foreach ($commands as $commandIndex => $command) {
            foreach (self::PARALLEL_METADATA_INTEGER_FIELDS as $field) {
                if (array_key_exists($field, $command)) {
                    $commands[$commandIndex][$field] = self::normalizeValidatedInteger($command[$field]);
                }
            }

            $path = $command['parallel_group_path'] ?? null;
            if (! is_array($path)) {
                continue;
            }

            foreach ($path as $pathIndex => $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                foreach (self::PARALLEL_METADATA_INTEGER_FIELDS as $field) {
                    if (array_key_exists($field, $entry)) {
                        $path[$pathIndex][$field] = self::normalizeValidatedInteger($entry[$field]);
                    }
                }
            }

            $commands[$commandIndex]['parallel_group_path'] = $path;
        }

        return $commands;
    }

    private static function normalizeValidatedInteger(mixed $value): mixed
    {
        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $command
     * @param array<string, list<string>> $errors
     * @return array<string, mixed>
     */
    private static function optionalParallelMetadata(
        array $command,
        string $leafKind,
        int $index,
        array &$errors,
    ): array {
        $present = array_key_exists('parallel_group_path', $command);
        foreach (self::PARALLEL_METADATA_FIELDS as $field) {
            $present = $present || array_key_exists($field, $command);
        }
        if (! $present) {
            return [];
        }

        $top = self::parallelMetadataEntry($command, $leafKind);
        $path = $command['parallel_group_path'] ?? null;
        if ($top === null || ! is_array($path) || ! array_is_list($path) || $path === []) {
            $errors["commands.{$index}.parallel_group_path"] = [
                'Parallel workflow commands require complete top-level metadata and a non-empty group path.',
            ];

            return [];
        }

        $normalizedPath = [];
        foreach ($path as $pathIndex => $entry) {
            $normalized = is_array($entry)
                ? self::parallelMetadataEntry($entry, $leafKind)
                : null;
            if ($normalized === null) {
                $errors["commands.{$index}.parallel_group_path.{$pathIndex}"] = [
                    'Each parallel group path entry must contain valid identity, kind, base, size, and index fields.',
                ];

                return [];
            }
            $normalizedPath[] = $normalized;
        }

        if ($normalizedPath[array_key_last($normalizedPath)] !== $top) {
            $errors["commands.{$index}.parallel_group_path"] = [
                'The innermost parallel group path entry must match the top-level parallel metadata.',
            ];

            return [];
        }

        return [
            ...$top,
            'parallel_group_path' => $normalizedPath,
        ];
    }

    /**
     * @param array<string, mixed> $value
     * @return array<string, mixed>|null
     */
    private static function parallelMetadataEntry(array $value, string $leafKind): ?array
    {
        $id = $value['parallel_group_id'] ?? null;
        $kind = $value['parallel_group_kind'] ?? null;
        $base = $value['parallel_group_base_sequence'] ?? null;
        $size = $value['parallel_group_size'] ?? null;
        $index = $value['parallel_group_index'] ?? null;
        $mode = $value['parallel_group_mode'] ?? 'all';
        $limit = StructuralLimits::commandBatchSizeLimit();
        if (! is_string($id) || $id === ''
            || ! is_string($kind) || ! in_array($kind, [$leafKind, 'mixed'], true)
            || ! is_int($base) || $base < 1
            || ! is_int($size) || $size < 1 || ($limit > 0 && $size > $limit)
            || ! is_int($index) || $index < 0 || $index >= $size
            || ! is_string($mode) || ! in_array($mode, ['all', 'select'], true)) {
            return null;
        }

        $prefix = $mode === 'select' ? 'select-calls' : match ($kind) {
            'activity' => 'parallel-activities',
            'child' => 'parallel-children',
            'timer' => 'parallel-timers',
            default => 'parallel-calls',
        };
        if ($id !== sprintf('%s:%d:%d', $prefix, $base, $size)) {
            return null;
        }

        $memberKey = $value['selection_member_key'] ?? null;
        $memberIndex = $value['selection_member_index'] ?? null;
        $memberBase = $value['selection_member_base_sequence'] ?? null;
        $memberSize = $value['selection_member_size'] ?? null;
        $memberKind = $value['selection_member_kind'] ?? null;
        if ($mode === 'select' && (
            ! self::validSelectionKey($memberKey)
            || ! is_int($memberIndex) || $memberIndex < 0
            || ! is_int($memberBase) || $memberBase < $base || $memberBase >= $base + $size
            || ! is_int($memberSize) || $memberSize < 1 || $memberBase + $memberSize > $base + $size
            || ! is_string($memberKind)
            || ! in_array($memberKind, ['activity', 'child', 'timer', 'signal', 'condition', 'group'], true)
        )) {
            return null;
        }

        return array_filter([
            'parallel_group_id' => $id,
            'parallel_group_kind' => $kind,
            'parallel_group_mode' => $mode === 'select' ? $mode : null,
            'parallel_group_base_sequence' => $base,
            'parallel_group_size' => $size,
            'parallel_group_index' => $index,
            'selection_member_key' => $mode === 'select' ? $memberKey : null,
            'selection_member_index' => $mode === 'select' ? $memberIndex : null,
            'selection_member_base_sequence' => $mode === 'select' ? $memberBase : null,
            'selection_member_size' => $mode === 'select' ? $memberSize : null,
            'selection_member_kind' => $mode === 'select' ? $memberKind : null,
        ], static fn (mixed $item): bool => $item !== null);
    }

    private static function validSelectionKey(mixed $value): bool
    {
        return (is_string($value) && $value !== '') || (is_int($value) && $value >= 0);
    }

    /**
     * @param  array<string, mixed>  $command
     * @param  array<string, list<string>>  $errors
     */
    private static function resolveCommandArguments(array $command, int $index, array &$errors): ?string
    {
        $resolved = self::resolveCommandArgumentsWithCodec($command, $index, $errors);

        return $resolved['payload'];
    }

    /**
     * @param  array<string, mixed>  $command
     * @param  array<string, list<string>>  $errors
     * @return array{payload: string|null, codec: string|null}
     */
    private static function resolveCommandArgumentsWithCodec(array $command, int $index, array &$errors): array
    {
        if (! array_key_exists('arguments', $command) || $command['arguments'] === null) {
            return [
                'payload' => null,
                'codec' => null,
            ];
        }

        $value = $command['arguments'];

        if (is_string($value)) {
            return [
                'payload' => $value !== '' ? $value : null,
                'codec' => null,
            ];
        }

        if (is_array($value)) {
            try {
                $resolved = PayloadEnvelopeResolver::resolveCommandPayloadWithCodec(
                    $value,
                    "commands.{$index}.arguments",
                );

                return [
                    'payload' => is_string($resolved['payload']) && $resolved['payload'] !== ''
                        ? $resolved['payload']
                        : null,
                    'codec' => $resolved['codec'],
                ];
            } catch (ValidationException $e) {
                foreach ($e->errors() as $field => $messages) {
                    $errors[$field] = $messages;
                }

                return [
                    'payload' => null,
                    'codec' => null,
                ];
            }
        }

        $errors["commands.{$index}.arguments"] = [
            'Workflow task command field [arguments] must be a string or a payload envelope when provided.',
        ];

        return [
            'payload' => null,
            'codec' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $command
     * @param  array<string, list<string>>  $errors
     * @return array{payload: string|null, codec: string|null}
     */
    private static function resolveCommandPayloadWithCodec(
        array $command,
        string $field,
        int $index,
        array &$errors,
    ): array {
        if (! array_key_exists($field, $command) || $command[$field] === null) {
            return [
                'payload' => null,
                'codec' => null,
            ];
        }

        if (is_string($command[$field])) {
            return [
                'payload' => $command[$field],
                'codec' => null,
            ];
        }

        if (is_array($command[$field])) {
            try {
                $resolved = PayloadEnvelopeResolver::resolveCommandPayloadWithCodec(
                    $command[$field],
                    "commands.{$index}.{$field}",
                );

                $payload = $resolved['payload'];

                if ($payload === null || is_string($payload)) {
                    return [
                        'payload' => $payload,
                        'codec' => $resolved['codec'],
                    ];
                }

                $errors["commands.{$index}.{$field}"] = [
                    sprintf(
                        'Workflow task command field [%s] must be a string or a payload envelope when provided.',
                        $field,
                    ),
                ];

                return [
                    'payload' => null,
                    'codec' => null,
                ];
            } catch (ValidationException $e) {
                foreach ($e->errors() as $errorField => $messages) {
                    $errors[$errorField] = $messages;
                }

                return [
                    'payload' => null,
                    'codec' => null,
                ];
            }
        }

        $errors["commands.{$index}.{$field}"] = [
            sprintf(
                'Workflow task command field [%s] must be a string or a payload envelope when provided.',
                $field,
            ),
        ];

        return [
            'payload' => null,
            'codec' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $command
     * @param  array{payload: string|null, codec: string|null}  $resolved
     * @param  array<string, list<string>>  $errors
     */
    private static function payloadCodecForResolvedPayload(
        array $command,
        array $resolved,
        string $payloadField,
        int $index,
        array &$errors,
    ): ?string {
        $explicitPayloadCodec = self::optionalPayloadCodec($command, $index, $errors);
        $resolvedCodec = $resolved['codec'];

        if (
            $explicitPayloadCodec !== null
            && $resolvedCodec !== null
            && $explicitPayloadCodec !== $resolvedCodec
        ) {
            $errors["commands.{$index}.payload_codec"] = [
                sprintf(
                    'Workflow task command field [payload_codec] must match the %s envelope codec.',
                    $payloadField,
                ),
            ];
        }

        return $explicitPayloadCodec ?? $resolvedCodec;
    }

    /**
     * @param  array<string, mixed>  $command
     * @param  array<string, list<string>>  $errors
     */
    private static function optionalPayloadCodec(array $command, int $index, array &$errors): ?string
    {
        if (! array_key_exists('payload_codec', $command) || $command['payload_codec'] === null) {
            return null;
        }

        if (! is_string($command['payload_codec']) || trim($command['payload_codec']) === '') {
            $errors["commands.{$index}.payload_codec"] = [
                'Workflow task command field [payload_codec] must be a non-empty string when provided.',
            ];

            return null;
        }

        try {
            return CodecRegistry::canonicalize(trim($command['payload_codec']));
        } catch (\InvalidArgumentException $e) {
            $errors["commands.{$index}.payload_codec"] = [$e->getMessage()];

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $command
     * @param  array<string, list<string>>  $errors
     * @return array<string, mixed>|null
     */
    private static function optionalExceptionPayload(array $command, int $index, array &$errors): ?array
    {
        if (! array_key_exists('exception', $command) || $command['exception'] === null) {
            return null;
        }

        if (! is_array($command['exception'])) {
            $errors["commands.{$index}.exception"] = [
                'Workflow task command field [exception] must be an object when provided.',
            ];

            return null;
        }

        return $command['exception'];
    }

    /**
     * @param  array<string, mixed>  $command
     * @param  array<string, list<string>>  $errors
     */
    private static function requiredCommandString(array $command, string $field, int $index, array &$errors): ?string
    {
        if (! is_string($command[$field] ?? null) || trim($command[$field]) === '') {
            $errors["commands.{$index}.{$field}"] = [
                sprintf('Workflow task command field [%s] must be a non-empty string.', $field),
            ];

            return null;
        }

        return trim($command[$field]);
    }

    /**
     * @param  array<string, mixed>  $command
     * @param  array<string, list<string>>  $errors
     */
    private static function optionalCommandString(array $command, string $field, int $index, array &$errors): ?string
    {
        if (! array_key_exists($field, $command) || $command[$field] === null) {
            return null;
        }

        if (! is_string($command[$field]) || trim($command[$field]) === '') {
            $errors["commands.{$index}.{$field}"] = [
                sprintf('Workflow task command field [%s] must be a non-empty string when provided.', $field),
            ];

            return null;
        }

        return trim($command[$field]);
    }

    /**
     * @param  array<string, mixed>  $command
     * @param  array<string, list<string>>  $errors
     */
    private static function optionalPositiveInt(array $command, string $field, int $index, array &$errors): ?int
    {
        if (! array_key_exists($field, $command) || $command[$field] === null) {
            return null;
        }

        if (! is_int($command[$field]) || (int) $command[$field] < 1) {
            $errors["commands.{$index}.{$field}"] = [
                sprintf('Workflow task command field [%s] must be a positive integer when provided.', $field),
            ];

            return null;
        }

        return (int) $command[$field];
    }

    /**
     * @param  array<string, mixed>  $command
     * @param  array<string, list<string>>  $errors
     */
    private static function optionalNonNegativeInt(array $command, string $field, int $index, array &$errors): ?int
    {
        if (! array_key_exists($field, $command) || $command[$field] === null) {
            return null;
        }

        if (! is_int($command[$field]) || (int) $command[$field] < 0) {
            $errors["commands.{$index}.{$field}"] = [
                sprintf('Workflow task command field [%s] must be a non-negative integer when provided.', $field),
            ];

            return null;
        }

        return (int) $command[$field];
    }

    /**
     * @param  array<string, mixed>  $command
     * @param  array<string, list<string>>  $errors
     * @return array<string, mixed>|null
     */
    private static function optionalCommandArray(array $command, string $field, int $index, array &$errors): ?array
    {
        if (! array_key_exists($field, $command) || $command[$field] === null) {
            return null;
        }

        if (! is_array($command[$field])) {
            $errors["commands.{$index}.{$field}"] = [
                sprintf('Workflow task command field [%s] must be an object when provided.', $field),
            ];

            return null;
        }

        return $command[$field];
    }

    /**
     * @param  array<string, mixed>  $command
     * @param  array<string, list<string>>  $errors
     * @return list<string>|null
     */
    private static function optionalCommandStringList(array $command, string $field, int $index, array &$errors): ?array
    {
        if (! array_key_exists($field, $command) || $command[$field] === null) {
            return null;
        }

        if (! is_array($command[$field])) {
            $errors["commands.{$index}.{$field}"] = [
                sprintf('Workflow task command field [%s] must be a list of strings when provided.', $field),
            ];

            return null;
        }

        $values = [];
        foreach (array_values($command[$field]) as $position => $value) {
            if (! is_string($value) || trim($value) === '') {
                $errors["commands.{$index}.{$field}.{$position}"] = [
                    sprintf('Workflow task command field [%s] entries must be non-empty strings.', $field),
                ];

                continue;
            }

            $values[] = trim($value);
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $command
     * @param  array<string, list<string>>  $errors
     * @return array<string, mixed>|null
     */
    private static function optionalRetryPolicy(array $command, int $index, array &$errors, string $subject): ?array
    {
        if (! array_key_exists('retry_policy', $command) || $command['retry_policy'] === null) {
            return null;
        }

        if (! is_array($command['retry_policy'])) {
            $errors["commands.{$index}.retry_policy"] = [
                'Workflow task command field [retry_policy] must be an object when provided.',
            ];

            return null;
        }

        $policy = [];
        $raw = $command['retry_policy'];

        if (array_key_exists('max_attempts', $raw)) {
            if (! is_int($raw['max_attempts']) || (int) $raw['max_attempts'] < 1) {
                $errors["commands.{$index}.retry_policy.max_attempts"] = [
                    sprintf('%s retry policy max_attempts must be a positive integer when provided.', $subject),
                ];
            } else {
                $policy['max_attempts'] = (int) $raw['max_attempts'];
            }
        }

        if (array_key_exists('backoff_seconds', $raw)) {
            if (! is_array($raw['backoff_seconds'])) {
                $errors["commands.{$index}.retry_policy.backoff_seconds"] = [
                    sprintf('%s retry policy backoff_seconds must be a list of non-negative integers.', $subject),
                ];
            } else {
                $backoff = [];
                foreach (array_values($raw['backoff_seconds']) as $position => $value) {
                    if (! is_int($value) || $value < 0) {
                        $errors["commands.{$index}.retry_policy.backoff_seconds.{$position}"] = [
                            sprintf('%s retry policy backoff_seconds entries must be non-negative integers.', $subject),
                        ];

                        continue;
                    }

                    $backoff[] = $value;
                }
                $policy['backoff_seconds'] = $backoff;
            }
        }

        if (array_key_exists('non_retryable_error_types', $raw)) {
            if (! is_array($raw['non_retryable_error_types'])) {
                $errors["commands.{$index}.retry_policy.non_retryable_error_types"] = [
                    sprintf('%s retry policy non_retryable_error_types must be a list of strings.', $subject),
                ];
            } else {
                $types = [];
                foreach (array_values($raw['non_retryable_error_types']) as $position => $value) {
                    if (! is_string($value) || trim($value) === '') {
                        $errors["commands.{$index}.retry_policy.non_retryable_error_types.{$position}"] = [
                            sprintf(
                                '%s retry policy non_retryable_error_types entries must be non-empty strings.',
                                $subject
                            ),
                        ];

                        continue;
                    }

                    $types[] = trim($value);
                }
                $policy['non_retryable_error_types'] = array_values(array_unique($types));
            }
        }

        return $policy === [] ? null : $policy;
    }

    /**
     * @param  array<string, mixed>  $command
     * @param  array<string, list<string>>  $errors
     * @return array<string, mixed>|null
     */
    private static function optionalWorkerSession(array $command, int $index, array &$errors): ?array
    {
        if (! array_key_exists('worker_session', $command) || $command['worker_session'] === null) {
            return null;
        }

        if (! is_array($command['worker_session'])) {
            $errors["commands.{$index}.worker_session"] = [
                'Workflow task command field [worker_session] must be an object when provided.',
            ];

            return null;
        }

        $raw = $command['worker_session'];
        $sessionId = $raw['session_id'] ?? null;

        if (! is_string($sessionId) || trim($sessionId) === '') {
            $errors["commands.{$index}.worker_session.session_id"] = [
                'Worker session commands require a non-empty session_id.',
            ];

            return null;
        }

        $session = [
            'session_id' => trim($sessionId),
        ];

        foreach (['connection', 'queue'] as $field) {
            if (! array_key_exists($field, $raw) || $raw[$field] === null) {
                continue;
            }

            if (! is_string($raw[$field]) || trim($raw[$field]) === '') {
                $errors["commands.{$index}.worker_session.{$field}"] = [
                    sprintf('Worker session field [%s] must be a non-empty string when provided.', $field),
                ];

                continue;
            }

            $session[$field] = trim($raw[$field]);
        }

        foreach (['lease_seconds', 'ttl_seconds', 'max_concurrent_activities'] as $field) {
            if (! array_key_exists($field, $raw) || $raw[$field] === null) {
                continue;
            }

            if (! is_int($raw[$field]) || (int) $raw[$field] < 1) {
                $errors["commands.{$index}.worker_session.{$field}"] = [
                    sprintf('Worker session field [%s] must be a positive integer when provided.', $field),
                ];

                continue;
            }

            $session[$field] = (int) $raw[$field];
        }

        foreach (['create_if_missing', 'allow_reacquire_after_failure'] as $field) {
            if (! array_key_exists($field, $raw) || $raw[$field] === null) {
                continue;
            }

            if (! is_bool($raw[$field])) {
                $errors["commands.{$index}.worker_session.{$field}"] = [
                    sprintf('Worker session field [%s] must be boolean when provided.', $field),
                ];

                continue;
            }

            $session[$field] = (bool) $raw[$field];
        }

        if (array_key_exists('requirements', $raw) && $raw['requirements'] !== null) {
            if (! is_array($raw['requirements'])) {
                $errors["commands.{$index}.worker_session.requirements"] = [
                    'Worker session requirements must be a list of non-empty strings.',
                ];
            } else {
                $requirements = [];
                foreach (array_values($raw['requirements']) as $position => $requirement) {
                    if (! is_string($requirement) || trim($requirement) === '') {
                        $errors["commands.{$index}.worker_session.requirements.{$position}"] = [
                            'Worker session requirements must be non-empty strings.',
                        ];

                        continue;
                    }

                    $requirements[] = trim($requirement);
                }

                if ($requirements !== []) {
                    $session['requirements'] = array_values(array_unique($requirements));
                }
            }
        }

        return $session;
    }

    /**
     * Reject retry/timeout/failure fields that have been placed on a command
     * type that does not accept them. The check fires only when the field is
     * populated (non-null and present), so omitting fields stays valid.
     *
     * @param  array<string, mixed>  $command
     * @param  array<string, list<string>>  $errors
     */
    private static function assertCommandFieldScope(string $type, array $command, int $index, array &$errors): void
    {
        foreach (self::FIELD_SCOPES as $field => $scope) {
            if (! array_key_exists($field, $command) || $command[$field] === null) {
                continue;
            }

            // Version-marker commands have a frozen wire shape and deliberately
            // drop fields added by newer SDKs. Treat payload_codec like every
            // other unknown marker field instead of promoting it into the
            // canonical command or rejecting an otherwise replayable marker.
            if ($type === 'record_version_marker' && $field === 'payload_codec') {
                continue;
            }

            if (in_array($type, $scope['allowed'], true)) {
                continue;
            }

            $errors["commands.{$index}.{$field}"] = [
                sprintf(
                    'Workflow task command field [%s] is not valid on a %s command. %s',
                    $field,
                    $type,
                    $scope['guidance'],
                ),
            ];
        }
    }

    /**
     * Activity timeout fields must form a coherent envelope:
     * schedule_to_close covers the whole execution including retries, so it
     * cannot be smaller than start_to_close, which limits one attempt; and
     * heartbeat_timeout polices liveness within an attempt, so it cannot
     * exceed start_to_close.
     *
     * @param  array<string, list<string>>  $errors
     */
    private static function assertActivityTimeoutOrdering(
        ?int $startToClose,
        ?int $scheduleToClose,
        ?int $heartbeat,
        int $index,
        array &$errors,
    ): void {
        if ($startToClose !== null && $scheduleToClose !== null && $scheduleToClose < $startToClose) {
            $errors["commands.{$index}.schedule_to_close_timeout"] = [
                sprintf(
                    'schedule_to_close_timeout (%d) must be greater than or equal to start_to_close_timeout (%d). schedule_to_close covers the whole activity execution including retries; one attempt cannot exceed the total budget.',
                    $scheduleToClose,
                    $startToClose,
                ),
            ];
        }

        if ($startToClose !== null && $heartbeat !== null && $heartbeat > $startToClose) {
            $errors["commands.{$index}.heartbeat_timeout"] = [
                sprintf(
                    'heartbeat_timeout (%d) must be less than or equal to start_to_close_timeout (%d). heartbeat_timeout polices liveness within one attempt and cannot exceed the per-attempt budget.',
                    $heartbeat,
                    $startToClose,
                ),
            ];
        }
    }

    /**
     * Child workflow timeout fields must form a coherent envelope:
     * execution_timeout_seconds covers the whole child execution across runs,
     * so it cannot be smaller than run_timeout_seconds, which limits one run.
     *
     * @param  array<string, list<string>>  $errors
     */
    private static function assertChildWorkflowTimeoutOrdering(
        ?int $executionTimeout,
        ?int $runTimeout,
        int $index,
        array &$errors,
    ): void {
        if ($executionTimeout !== null && $runTimeout !== null && $executionTimeout < $runTimeout) {
            $errors["commands.{$index}.execution_timeout_seconds"] = [
                sprintf(
                    'execution_timeout_seconds (%d) must be greater than or equal to run_timeout_seconds (%d). execution_timeout_seconds covers the whole child workflow execution; one run cannot exceed the total budget.',
                    $executionTimeout,
                    $runTimeout,
                ),
            ];
        }
    }

    /**
     * @return array<string, string>
     */
    private static function normalizeSearchAttributeTypes(mixed $types): array
    {
        if (! is_array($types)) {
            return [];
        }

        $normalized = [];

        foreach ($types as $key => $type) {
            if (! is_string($key) || ! is_string($type)) {
                continue;
            }

            if (! in_array($type, WorkflowSearchAttribute::VALID_TYPES, true)) {
                continue;
            }

            $normalized[$key] = $type;
        }

        ksort($normalized);

        return $normalized;
    }
}
