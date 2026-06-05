<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;
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
 * on `start_child_workflow`, and the worker-side `non_retryable` failure
 * marker only belongs on `fail_workflow` / `fail_update`. Structured
 * exception payloads belong on `fail_workflow` so terminal worker failures can
 * preserve the original typed failure. Workflow failure itself is non-retryable,
 * and the SDK HTTP transport retry policy is a client concern that does not
 * appear in the workflow task command stream.
 *
 * Extracted from App\Http\Controllers\Api\WorkerController so the server
 * is no longer the source of truth for the command grammar.
 *
 * @api Stable class surface consumed by the standalone workflow-server.
 *      The public static method signatures on this class are covered by
 *      the workflow package's semver guarantee. See docs/api-stability.md.
 */
final class WorkflowCommandNormalizer
{
    /**
     * Scope contract for retry/timeout/failure fields. Each entry lists the
     * command types that legitimately accept the field and a short guidance
     * sentence that names the correct layer.
     *
     * @var array<string, array{allowed: list<string>, guidance: string}>
     */
    private const FIELD_SCOPES = [
        'retry_policy' => [
            'allowed' => ['schedule_activity', 'start_child_workflow'],
            'guidance' => 'Configure activity retries on a schedule_activity command, or child workflow retries on a start_child_workflow command. Workflow failure itself is non-retryable, and SDK HTTP transport retry is a client concern that does not appear in the workflow task command stream.',
        ],
        'start_to_close_timeout' => [
            'allowed' => ['schedule_activity'],
            'guidance' => 'start_to_close_timeout limits one activity attempt and only applies to a schedule_activity command.',
        ],
        'schedule_to_start_timeout' => [
            'allowed' => ['schedule_activity'],
            'guidance' => 'schedule_to_start_timeout limits queue wait before an activity attempt starts and only applies to a schedule_activity command.',
        ],
        'schedule_to_close_timeout' => [
            'allowed' => ['schedule_activity'],
            'guidance' => 'schedule_to_close_timeout limits the entire activity execution including retries and only applies to a schedule_activity command.',
        ],
        'heartbeat_timeout' => [
            'allowed' => ['schedule_activity'],
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
            'allowed' => ['fail_workflow', 'fail_update'],
            'guidance' => 'non_retryable marks a workflow or update failure as non-retryable and only applies to a fail_workflow or fail_update command. Activity non-retryable error types belong inside the schedule_activity retry_policy.non_retryable_error_types list.',
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
            ],
            'guidance' => 'payload_codec identifies the codec used for command payload bytes and only applies to commands that carry result or arguments payloads.',
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
     * @param  list<array<string, mixed>>  $commands
     * @return list<array<string, mixed>>
     */
    public static function normalize(array $commands): array
    {
        $normalized = [];
        $errors = [];

        foreach ($commands as $index => $command) {
            $type = is_array($command) ? ($command['type'] ?? null) : null;

            if (! is_string($type)) {
                $errors["commands.{$index}.type"] = ['Each command must declare a supported type.'];

                continue;
            }

            self::assertCommandFieldScope($type, is_array($command) ? $command : [], $index, $errors);

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

                self::assertActivityTimeoutOrdering($startToClose, $scheduleToClose, $heartbeat, $index, $errors);

                $arguments = self::resolveCommandArgumentsWithCodec($command, $index, $errors);
                $payloadCodec = self::payloadCodecForResolvedPayload($command, $arguments, 'arguments', $index, $errors);

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
                $payloadCodec = self::payloadCodecForResolvedPayload($command, $arguments, 'arguments', $index, $errors);

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
                ], static fn (mixed $value): bool => $value !== null);

                continue;
            }

            if ($type === 'continue_as_new') {
                $workflowType = self::optionalCommandString($command, 'workflow_type', $index, $errors);
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

            if ($type === 'open_condition_wait') {
                $conditionKey = self::optionalCommandString($command, 'condition_key', $index, $errors);
                $conditionDefinitionFingerprint = self::optionalCommandString(
                    $command,
                    'condition_definition_fingerprint',
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
                    'timeout_seconds' => $timeoutSeconds,
                ], static fn (mixed $value): bool => $value !== null);

                continue;
            }

            if ($type === 'open_signal_wait') {
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
