<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Validation\ValidationException;

/**
 * Validates and normalizes workflow commands reported by a worker on a
 * workflow-task completion.
 *
 * This is the grammar the server contract accepts — any SDK (PHP, Python,
 * future Go/TS) produces commands in this shape, and the normalizer is the
 * authoritative mapping from raw JSON into canonical command arrays the
 * workflow task bridge consumes.
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

            if ($type === 'complete_workflow') {
                $normalized[] = [
                    'type' => $type,
                    'result' => PayloadEnvelopeResolver::resolveCommandPayload(
                        $command['result'] ?? null,
                        "commands.{$index}.result",
                    ),
                ];

                continue;
            }

            if ($type === 'fail_workflow') {
                if (! is_string($command['message'] ?? null) || trim((string) $command['message']) === '') {
                    $errors["commands.{$index}.message"] = ['Fail workflow commands require a non-empty message.'];

                    continue;
                }

                $normalized[] = array_filter([
                    'type' => $type,
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

            if ($type === 'schedule_activity') {
                if (! is_string($command['activity_type'] ?? null) || trim((string) $command['activity_type']) === '') {
                    $errors["commands.{$index}.activity_type"] = [
                        'Schedule activity commands require a non-empty activity_type.',
                    ];

                    continue;
                }

                $retryPolicy = self::optionalActivityRetryPolicy($command, $index, $errors);

                $normalized[] = array_filter([
                    'type' => $type,
                    'activity_type' => trim($command['activity_type']),
                    'arguments' => self::resolveCommandArguments($command, $index, $errors),
                    'connection' => self::optionalCommandString($command, 'connection', $index, $errors),
                    'queue' => self::optionalCommandString($command, 'queue', $index, $errors),
                    'retry_policy' => $retryPolicy,
                    'start_to_close_timeout' => self::optionalPositiveInt(
                        $command,
                        'start_to_close_timeout',
                        $index,
                        $errors,
                    ),
                    'schedule_to_start_timeout' => self::optionalPositiveInt(
                        $command,
                        'schedule_to_start_timeout',
                        $index,
                        $errors,
                    ),
                    'schedule_to_close_timeout' => self::optionalPositiveInt(
                        $command,
                        'schedule_to_close_timeout',
                        $index,
                        $errors,
                    ),
                    'heartbeat_timeout' => self::optionalPositiveInt($command, 'heartbeat_timeout', $index, $errors),
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

                $normalized[] = array_filter([
                    'type' => $type,
                    'workflow_type' => trim($command['workflow_type']),
                    'arguments' => self::resolveCommandArguments($command, $index, $errors),
                    'connection' => self::optionalCommandString($command, 'connection', $index, $errors),
                    'queue' => self::optionalCommandString($command, 'queue', $index, $errors),
                    'parent_close_policy' => $parentClosePolicy,
                ], static fn (mixed $value): bool => $value !== null);

                continue;
            }

            if ($type === 'continue_as_new') {
                $workflowType = self::optionalCommandString($command, 'workflow_type', $index, $errors);

                $normalized[] = array_filter([
                    'type' => $type,
                    'arguments' => self::resolveCommandArguments($command, $index, $errors),
                    'workflow_type' => $workflowType,
                ], static fn (mixed $value): bool => $value !== null);

                continue;
            }

            if ($type === 'record_side_effect') {
                if (! is_string($command['result'] ?? null)) {
                    $errors["commands.{$index}.result"] = ['Record side effect commands require a string result.'];

                    continue;
                }

                $normalized[] = [
                    'type' => $type,
                    'result' => $command['result'],
                ];

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

                $normalized[] = [
                    'type' => $type,
                    'attributes' => $command['attributes'],
                ];

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
        if (! array_key_exists('arguments', $command) || $command['arguments'] === null) {
            return null;
        }

        $value = $command['arguments'];

        if (is_string($value)) {
            return $value !== '' ? $value : null;
        }

        if (is_array($value)) {
            try {
                return PayloadEnvelopeResolver::resolveCommandPayload($value, "commands.{$index}.arguments");
            } catch (ValidationException $e) {
                foreach ($e->errors() as $field => $messages) {
                    $errors[$field] = $messages;
                }

                return null;
            }
        }

        $errors["commands.{$index}.arguments"] = [
            'Workflow task command field [arguments] must be a string or a payload envelope when provided.',
        ];

        return null;
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
    private static function optionalActivityRetryPolicy(array $command, int $index, array &$errors): ?array
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
                    'Activity retry policy max_attempts must be a positive integer when provided.',
                ];
            } else {
                $policy['max_attempts'] = (int) $raw['max_attempts'];
            }
        }

        if (array_key_exists('backoff_seconds', $raw)) {
            if (! is_array($raw['backoff_seconds'])) {
                $errors["commands.{$index}.retry_policy.backoff_seconds"] = [
                    'Activity retry policy backoff_seconds must be a list of non-negative integers.',
                ];
            } else {
                $backoff = [];
                foreach (array_values($raw['backoff_seconds']) as $position => $value) {
                    if (! is_int($value) || $value < 0) {
                        $errors["commands.{$index}.retry_policy.backoff_seconds.{$position}"] = [
                            'Activity retry policy backoff_seconds entries must be non-negative integers.',
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
                    'Activity retry policy non_retryable_error_types must be a list of strings.',
                ];
            } else {
                $types = [];
                foreach (array_values($raw['non_retryable_error_types']) as $position => $value) {
                    if (! is_string($value) || trim($value) === '') {
                        $errors["commands.{$index}.retry_policy.non_retryable_error_types.{$position}"] = [
                            'Activity retry policy non_retryable_error_types entries must be non-empty strings.',
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
}
