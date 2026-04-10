<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use LogicException;
use Throwable;
use Workflow\V2\Contracts\HistoryExportRedactor;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowSignal;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowUpdate;

final class HistoryExport
{
    public const SCHEMA = 'durable-workflow.v2.history-export';

    public const SCHEMA_VERSION = 1;

    private const INTEGRITY_CANONICALIZATION = 'json-recursive-ksort-v1';

    /**
     * @return array<string, mixed>
     */
    public static function forRun(
        WorkflowRun $run,
        ?CarbonInterface $exportedAt = null,
        HistoryExportRedactor|callable|null $redactor = null,
    ): array {
        $exportedAt ??= now();

        $run->loadMissing([
            'instance.runs.summary',
            'summary',
            'commands',
            'signals',
            'updates',
            'tasks',
            'activityExecutions.attempts',
            'timers',
            'failures',
            'historyEvents',
            'parentLinks',
            'childLinks',
        ]);

        $currentRun = $run->instance === null
            ? null
            : CurrentRunResolver::forInstance($run->instance, ['summary']);
        $summary = $run->summary;

        $bundle = [
            'schema' => self::SCHEMA,
            'schema_version' => self::SCHEMA_VERSION,
            'exported_at' => self::timestamp($exportedAt),
            'dedupe_key' => self::dedupeKey($run),
            'history_complete' => $run->status->isTerminal(),
            'workflow' => [
                'instance_id' => $run->workflow_instance_id,
                'run_id' => $run->id,
                'run_number' => $run->run_number,
                'is_current_run' => $currentRun?->id === $run->id,
                'current_run_id' => $currentRun?->id,
                'workflow_type' => $run->workflow_type,
                'workflow_class' => $run->workflow_class,
                'business_key' => $summary?->business_key ?? $run->business_key ?? $run->instance?->business_key,
                'visibility_labels' => $summary?->visibility_labels ?? $run->visibility_labels ?? $run->instance?->visibility_labels ?? [],
                'status' => $run->status->value,
                'status_bucket' => $run->status->statusBucket()->value,
                'closed_reason' => $summary?->closed_reason ?? $run->closed_reason,
                'archived_at' => self::timestamp($summary?->archived_at ?? $run->archived_at),
                'archive_command_id' => $summary?->archive_command_id ?? $run->archive_command_id,
                'archive_reason' => $summary?->archive_reason ?? $run->archive_reason,
                'compatibility' => $run->compatibility,
                'connection' => $run->connection,
                'queue' => $run->queue,
                'last_history_sequence' => $run->last_history_sequence,
                'started_at' => self::timestamp($summary?->started_at ?? $run->started_at),
                'closed_at' => self::timestamp($summary?->closed_at ?? $run->closed_at),
                'last_progress_at' => self::timestamp($run->last_progress_at),
            ],
            'payloads' => [
                'codec' => $run->payload_codec ?? config('workflows.serializer'),
                'arguments' => [
                    'available' => $run->arguments !== null,
                    'data' => $run->arguments,
                ],
                'output' => [
                    'available' => $run->output !== null,
                    'data' => $run->output,
                ],
            ],
            'summary' => self::summary($summary),
            'history_events' => $run->historyEvents
                ->map(static fn (WorkflowHistoryEvent $event): array => self::historyEvent($event))
                ->values()
                ->all(),
            'commands' => $run->commands
                ->map(static fn (WorkflowCommand $command): array => self::command($command))
                ->values()
                ->all(),
            'signals' => $run->signals
                ->map(static fn (WorkflowSignal $signal): array => self::signal($signal))
                ->values()
                ->all(),
            'updates' => $run->updates
                ->map(static fn (WorkflowUpdate $update): array => self::update($update))
                ->values()
                ->all(),
            'tasks' => $run->tasks
                ->map(static fn (WorkflowTask $task): array => self::task($task))
                ->values()
                ->all(),
            'activities' => collect(self::activitySnapshots($run))
                ->map(static fn (array $activity): array => self::activity($activity))
                ->values()
                ->all(),
            'timers' => collect(RunTimerView::timersForRun($run))
                ->map(static fn (array $timer): array => self::timer($timer))
                ->values()
                ->all(),
            'failures' => collect(FailureSnapshots::forRun($run))
                ->map(static fn (array $failure): array => self::failure($failure))
                ->values()
                ->all(),
            'links' => [
                'parents' => self::parentLinks($run),
                'children' => self::childLinks($run),
            ],
        ];

        return self::withIntegrity(self::withRedaction($bundle, $run, $redactor));
    }

    /**
     * @param array<string, mixed> $bundle
     *
     * @return array<string, mixed>
     */
    private static function withIntegrity(array $bundle): array
    {
        unset($bundle['integrity']);

        $canonicalJson = self::canonicalJson($bundle);
        $signingKey = self::signingKey();

        $bundle['integrity'] = [
            'canonicalization' => self::INTEGRITY_CANONICALIZATION,
            'checksum_algorithm' => 'sha256',
            'checksum' => hash('sha256', $canonicalJson),
            'signature_algorithm' => $signingKey === null ? null : 'hmac-sha256',
            'signature' => $signingKey === null ? null : hash_hmac('sha256', $canonicalJson, $signingKey),
            'key_id' => $signingKey === null ? null : self::signingKeyId(),
        ];

        return $bundle;
    }

    /**
     * @param array<string, mixed> $bundle
     *
     * @return array<string, mixed>
     */
    private static function withRedaction(
        array $bundle,
        WorkflowRun $run,
        HistoryExportRedactor|callable|null $redactor,
    ): array {
        $resolvedRedactor = self::resolveRedactor($redactor);

        if ($resolvedRedactor === null) {
            $bundle['redaction'] = [
                'applied' => false,
                'policy' => null,
                'paths' => [],
            ];

            return $bundle;
        }

        $paths = [];
        $callback = $resolvedRedactor['callback'];

        if (isset($bundle['payloads']['arguments']) && is_array($bundle['payloads']['arguments'])) {
            self::redactField(
                $bundle['payloads']['arguments'],
                'data',
                $callback,
                self::redactionContext($run, 'payloads.arguments.data', 'workflow_payload', [
                    'field' => 'arguments',
                ]),
                $paths,
            );
        }

        if (isset($bundle['payloads']['output']) && is_array($bundle['payloads']['output'])) {
            self::redactField(
                $bundle['payloads']['output'],
                'data',
                $callback,
                self::redactionContext($run, 'payloads.output.data', 'workflow_payload', [
                    'field' => 'output',
                ]),
                $paths,
            );
        }

        if (isset($bundle['history_events']) && is_array($bundle['history_events'])) {
            foreach ($bundle['history_events'] as $index => &$event) {
                if (! is_array($event)) {
                    continue;
                }

                self::redactField(
                    $event,
                    'payload',
                    $callback,
                    self::redactionContext($run, "history_events.{$index}.payload", 'history_event', [
                        'history_event_id' => $event['id'] ?? null,
                        'history_event_type' => $event['type'] ?? null,
                        'sequence' => $event['sequence'] ?? null,
                    ]),
                    $paths,
                );
            }

            unset($event);
        }

        if (isset($bundle['commands']) && is_array($bundle['commands'])) {
            foreach ($bundle['commands'] as $index => &$command) {
                if (! is_array($command)) {
                    continue;
                }

                self::redactField(
                    $command,
                    'payload',
                    $callback,
                    self::redactionContext($run, "commands.{$index}.payload", 'command_payload', [
                        'command_id' => $command['id'] ?? null,
                        'command_type' => $command['type'] ?? null,
                        'sequence' => $command['sequence'] ?? null,
                    ]),
                    $paths,
                );

                self::redactField(
                    $command,
                    'context',
                    $callback,
                    self::redactionContext($run, "commands.{$index}.context", 'command_context', [
                        'command_id' => $command['id'] ?? null,
                        'command_type' => $command['type'] ?? null,
                        'sequence' => $command['sequence'] ?? null,
                    ]),
                    $paths,
                );
            }

            unset($command);
        }

        if (isset($bundle['updates']) && is_array($bundle['updates'])) {
            foreach ($bundle['updates'] as $index => &$update) {
                if (! is_array($update)) {
                    continue;
                }

                foreach (['arguments', 'result'] as $field) {
                    self::redactField(
                        $update,
                        $field,
                        $callback,
                        self::redactionContext($run, "updates.{$index}.{$field}", 'update_payload', [
                            'update_id' => $update['id'] ?? null,
                            'update_name' => $update['name'] ?? null,
                            'field' => $field,
                        ]),
                        $paths,
                    );
                }
            }

            unset($update);
        }

        if (isset($bundle['signals']) && is_array($bundle['signals'])) {
            foreach ($bundle['signals'] as $index => &$signal) {
                if (! is_array($signal)) {
                    continue;
                }

                self::redactField(
                    $signal,
                    'arguments',
                    $callback,
                    self::redactionContext($run, "signals.{$index}.arguments", 'signal_payload', [
                        'signal_id' => $signal['id'] ?? null,
                        'signal_name' => $signal['name'] ?? null,
                    ]),
                    $paths,
                );
            }

            unset($signal);
        }

        if (isset($bundle['tasks']) && is_array($bundle['tasks'])) {
            foreach ($bundle['tasks'] as $index => &$task) {
                if (! is_array($task)) {
                    continue;
                }

                self::redactField(
                    $task,
                    'payload',
                    $callback,
                    self::redactionContext($run, "tasks.{$index}.payload", 'task_payload', [
                        'task_id' => $task['id'] ?? null,
                        'task_type' => $task['type'] ?? null,
                    ]),
                    $paths,
                );
            }

            unset($task);
        }

        if (isset($bundle['activities']) && is_array($bundle['activities'])) {
            foreach ($bundle['activities'] as $index => &$activity) {
                if (! is_array($activity)) {
                    continue;
                }

                foreach (['arguments', 'result'] as $field) {
                    self::redactField(
                        $activity,
                        $field,
                        $callback,
                        self::redactionContext($run, "activities.{$index}.{$field}", 'activity_payload', [
                            'activity_execution_id' => $activity['id'] ?? null,
                            'activity_type' => $activity['activity_type'] ?? null,
                            'field' => $field,
                        ]),
                        $paths,
                    );
                }
            }

            unset($activity);
        }

        if (isset($bundle['failures']) && is_array($bundle['failures'])) {
            foreach ($bundle['failures'] as $index => &$failure) {
                if (! is_array($failure)) {
                    continue;
                }

                foreach (['message', 'file', 'trace_preview'] as $field) {
                    self::redactField(
                        $failure,
                        $field,
                        $callback,
                        self::redactionContext($run, "failures.{$index}.{$field}", 'failure_diagnostic', [
                            'failure_id' => $failure['id'] ?? null,
                            'source_kind' => $failure['source_kind'] ?? null,
                            'source_id' => $failure['source_id'] ?? null,
                            'field' => $field,
                        ]),
                        $paths,
                    );
                }
            }

            unset($failure);
        }

        $bundle['redaction'] = [
            'applied' => true,
            'policy' => $resolvedRedactor['policy'],
            'paths' => array_values(array_unique($paths)),
        ];

        return $bundle;
    }

    /**
     * @return array{callback: callable(mixed, array<string, mixed>): mixed, policy: string}|null
     */
    private static function resolveRedactor(HistoryExportRedactor|callable|null $redactor): ?array
    {
        $configured = $redactor ?? config('workflows.v2.history_export.redactor');

        if ($configured === null || $configured === false || $configured === '') {
            return null;
        }

        if (is_string($configured) && class_exists($configured)) {
            $configured = app($configured);
        }

        if ($configured instanceof HistoryExportRedactor) {
            return [
                'callback' => static fn (mixed $value, array $context): mixed => $configured->redact($value, $context),
                'policy' => $configured::class,
            ];
        }

        if (is_callable($configured)) {
            return [
                'callback' => $configured,
                'policy' => self::redactorName($configured),
            ];
        }

        throw new InvalidArgumentException(
            'Configured workflow v2 history export redactor must implement '
            . HistoryExportRedactor::class
            . ' or be callable.',
        );
    }

    /**
     * @param array<string, mixed> $target
     * @param callable(mixed, array<string, mixed>): mixed $redactor
     * @param array<string, mixed> $context
     * @param list<string> $paths
     */
    private static function redactField(
        array &$target,
        string $field,
        callable $redactor,
        array $context,
        array &$paths,
    ): void {
        if (! array_key_exists($field, $target)) {
            return;
        }

        $target[$field] = self::redactValue($target[$field], $redactor, $context);
        $paths[] = (string) $context['path'];
    }

    /**
     * @param callable(mixed, array<string, mixed>): mixed $redactor
     * @param array<string, mixed> $context
     */
    private static function redactValue(mixed $value, callable $redactor, array $context): mixed
    {
        try {
            return $redactor($value, $context);
        } catch (Throwable $exception) {
            throw new LogicException(
                sprintf(
                    'Workflow v2 history export redactor failed for [%s]: %s',
                    $context['path'] ?? 'unknown',
                    $exception->getMessage(),
                ),
                previous: $exception,
            );
        }
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private static function redactionContext(WorkflowRun $run, string $path, string $category, array $extra = []): array
    {
        return array_merge([
            'path' => $path,
            'category' => $category,
            'workflow_instance_id' => $run->workflow_instance_id,
            'workflow_run_id' => $run->id,
            'workflow_type' => $run->workflow_type,
        ], $extra);
    }

    private static function redactorName(callable $redactor): string
    {
        if ($redactor instanceof Closure) {
            return 'closure';
        }

        if (is_array($redactor)) {
            $target = $redactor[0] ?? null;
            $method = $redactor[1] ?? '__invoke';
            $targetName = is_object($target)
                ? $target::class
                : (is_string($target) ? $target : 'callable');

            return $targetName . '::' . (is_string($method) ? $method : '__invoke');
        }

        if (is_object($redactor)) {
            return $redactor::class;
        }

        if (is_string($redactor)) {
            return $redactor;
        }

        return 'callable';
    }

    private static function canonicalJson(mixed $value): string
    {
        $json = json_encode(
            self::canonicalize($value),
            JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        );

        if (! is_string($json)) {
            throw new LogicException('Failed to canonicalize workflow history export.');
        }

        return $json;
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(static fn (mixed $item): mixed => self::canonicalize($item), $value);
        }

        $canonical = [];

        foreach ($value as $key => $item) {
            $canonical[$key] = self::canonicalize($item);
        }

        ksort($canonical, SORT_STRING);

        return $canonical;
    }

    private static function signingKey(): ?string
    {
        $key = config('workflows.v2.history_export.signing_key');

        if (! is_string($key)) {
            return null;
        }

        $key = trim($key);

        return $key === '' ? null : $key;
    }

    private static function signingKeyId(): ?string
    {
        $keyId = config('workflows.v2.history_export.signing_key_id');

        if (! is_string($keyId)) {
            return null;
        }

        $keyId = trim($keyId);

        return $keyId === '' ? null : $keyId;
    }

    private static function dedupeKey(WorkflowRun $run): string
    {
        return implode(':', [
            $run->id,
            (string) $run->last_history_sequence,
            self::timestamp($run->last_progress_at ?? $run->updated_at) ?? 'unknown',
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function summary(?WorkflowRunSummary $summary): ?array
    {
        if ($summary === null) {
            return null;
        }

        return [
            'status' => $summary->status,
            'status_bucket' => $summary->status_bucket,
            'closed_reason' => $summary->closed_reason,
            'archived_at' => self::timestamp($summary->archived_at),
            'archive_command_id' => $summary->archive_command_id,
            'archive_reason' => $summary->archive_reason,
            'business_key' => $summary->business_key,
            'visibility_labels' => $summary->visibility_labels ?? [],
            'is_current_run' => (bool) $summary->is_current_run,
            'started_at' => self::timestamp($summary->started_at),
            'closed_at' => self::timestamp($summary->closed_at),
            'duration_ms' => $summary->duration_ms,
            'wait_kind' => $summary->wait_kind,
            'wait_reason' => $summary->wait_reason,
            'wait_started_at' => self::timestamp($summary->wait_started_at),
            'wait_deadline_at' => self::timestamp($summary->wait_deadline_at),
            'open_wait_id' => $summary->open_wait_id,
            'resume_source_kind' => $summary->resume_source_kind,
            'resume_source_id' => $summary->resume_source_id,
            'next_task_at' => self::timestamp($summary->next_task_at),
            'next_task_id' => $summary->next_task_id,
            'next_task_type' => $summary->next_task_type,
            'next_task_status' => $summary->next_task_status,
            'next_task_lease_expires_at' => self::timestamp($summary->next_task_lease_expires_at),
            'liveness_state' => $summary->liveness_state,
            'liveness_reason' => $summary->liveness_reason,
            'exception_count' => (int) $summary->exception_count,
            'history_event_count' => (int) $summary->history_event_count,
            'history_size_bytes' => (int) $summary->history_size_bytes,
            'continue_as_new_recommended' => (bool) $summary->continue_as_new_recommended,
            'sort_timestamp' => self::timestamp($summary->sort_timestamp),
            'sort_key' => $summary->sort_key,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function historyEvent(WorkflowHistoryEvent $event): array
    {
        return [
            'id' => $event->id,
            'sequence' => $event->sequence,
            'type' => $event->event_type->value,
            'workflow_task_id' => $event->workflow_task_id,
            'workflow_command_id' => $event->workflow_command_id,
            'recorded_at' => self::timestamp($event->recorded_at),
            'payload' => $event->payload ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function command(WorkflowCommand $command): array
    {
        return [
            'id' => $command->id,
            'sequence' => $command->command_sequence,
            'type' => $command->command_type->value,
            'target_scope' => $command->target_scope,
            'requested_run_id' => $command->requestedRunId(),
            'resolved_run_id' => $command->resolvedRunId(),
            'target_name' => $command->targetName(),
            'payload_codec' => $command->payload_codec,
            'payload' => $command->payload,
            'source' => $command->source,
            'context' => $command->publicContext(),
            'caller_label' => $command->callerLabel(),
            'auth_status' => $command->authStatus(),
            'auth_method' => $command->authMethod(),
            'request_method' => $command->requestMethod(),
            'request_path' => $command->requestPath(),
            'request_route_name' => $command->requestRouteName(),
            'request_fingerprint' => $command->requestFingerprint(),
            'request_id' => $command->requestId(),
            'correlation_id' => $command->correlationId(),
            'status' => $command->status->value,
            'outcome' => $command->outcome?->value,
            'rejection_reason' => $command->rejection_reason,
            'validation_errors' => $command->validationErrors(),
            'accepted_at' => self::timestamp($command->accepted_at),
            'applied_at' => self::timestamp($command->applied_at),
            'rejected_at' => self::timestamp($command->rejected_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function update(WorkflowUpdate $update): array
    {
        return [
            'id' => $update->id,
            'command_id' => $update->workflow_command_id,
            'command_sequence' => $update->command_sequence,
            'workflow_sequence' => $update->workflow_sequence,
            'name' => $update->name,
            'status' => $update->status->value,
            'outcome' => $update->outcome?->value,
            'rejection_reason' => $update->rejection_reason,
            'validation_errors' => $update->normalizedValidationErrors(),
            'failure_id' => $update->failure_id,
            'accepted_at' => self::timestamp($update->accepted_at),
            'applied_at' => self::timestamp($update->applied_at),
            'rejected_at' => self::timestamp($update->rejected_at),
            'closed_at' => self::timestamp($update->closed_at),
            'arguments' => $update->arguments,
            'result' => $update->result,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function signal(WorkflowSignal $signal): array
    {
        return [
            'id' => $signal->id,
            'command_id' => $signal->workflow_command_id,
            'command_sequence' => $signal->command_sequence,
            'workflow_sequence' => $signal->workflow_sequence,
            'name' => $signal->signal_name,
            'signal_wait_id' => $signal->signal_wait_id,
            'target_scope' => $signal->target_scope,
            'requested_run_id' => $signal->requested_workflow_run_id,
            'resolved_run_id' => $signal->resolved_workflow_run_id,
            'status' => $signal->status->value,
            'outcome' => $signal->outcome?->value,
            'rejection_reason' => $signal->rejection_reason,
            'validation_errors' => $signal->normalizedValidationErrors(),
            'received_at' => self::timestamp($signal->received_at),
            'applied_at' => self::timestamp($signal->applied_at),
            'rejected_at' => self::timestamp($signal->rejected_at),
            'closed_at' => self::timestamp($signal->closed_at),
            'arguments' => $signal->arguments,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function task(WorkflowTask $task): array
    {
        return [
            'id' => $task->id,
            'type' => $task->task_type->value,
            'status' => $task->status->value,
            'payload' => $task->payload ?? [],
            'connection' => $task->connection,
            'queue' => $task->queue,
            'compatibility' => $task->compatibility,
            'attempt_count' => $task->attempt_count,
            'repair_count' => $task->repair_count,
            'available_at' => self::timestamp($task->available_at),
            'leased_at' => self::timestamp($task->leased_at),
            'lease_owner' => $task->lease_owner,
            'lease_expires_at' => self::timestamp($task->lease_expires_at),
            'last_dispatch_attempt_at' => self::timestamp($task->last_dispatch_attempt_at),
            'last_dispatched_at' => self::timestamp($task->last_dispatched_at),
            'last_dispatch_error' => $task->last_dispatch_error,
            'last_claim_failed_at' => self::timestamp($task->last_claim_failed_at),
            'last_claim_error' => $task->last_claim_error,
            'repair_available_at' => self::timestamp($task->repair_available_at),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function activitySnapshots(WorkflowRun $run): array
    {
        $run->loadMissing(['activityExecutions.attempts', 'historyEvents']);

        $states = [];
        $executions = $run->activityExecutions->keyBy('id');
        $attemptsByActivityId = ActivityAttemptSnapshots::forRun($run);

        foreach (self::activityEvents($run) as $event) {
            $snapshot = ActivitySnapshot::fromEvent($event);
            $activityId = is_array($snapshot) && is_string($snapshot['id'] ?? null)
                ? $snapshot['id']
                : null;

            if ($activityId === null) {
                continue;
            }

            /** @var ActivityExecution|null $execution */
            $execution = $executions->get($activityId);
            $state = $states[$activityId]
                ?? ($execution instanceof ActivityExecution ? ActivitySnapshot::fromExecution($execution) : ['id' => $activityId]);

            $states[$activityId] = ActivitySnapshot::merge($state, $snapshot);
        }

        foreach ($run->activityExecutions as $execution) {
            if (! $execution instanceof ActivityExecution || array_key_exists($execution->id, $states)) {
                continue;
            }

            $states[$execution->id] = ActivitySnapshot::fromExecution($execution);
        }

        $activities = [];

        foreach ($states as $activityId => $state) {
            /** @var ActivityExecution|null $execution */
            $execution = $executions->get($activityId);
            $activities[] = self::activityState($state, $execution, $attemptsByActivityId[$activityId] ?? []);
        }

        usort($activities, static function (array $left, array $right): int {
            $leftSequence = is_int($left['sequence'] ?? null) ? $left['sequence'] : PHP_INT_MAX;
            $rightSequence = is_int($right['sequence'] ?? null) ? $right['sequence'] : PHP_INT_MAX;

            if ($leftSequence !== $rightSequence) {
                return $leftSequence <=> $rightSequence;
            }

            $leftCreatedAt = self::timestampToMilliseconds($left['created_at'] ?? null);
            $rightCreatedAt = self::timestampToMilliseconds($right['created_at'] ?? null);

            if ($leftCreatedAt !== $rightCreatedAt) {
                return $leftCreatedAt <=> $rightCreatedAt;
            }

            return ($left['id'] ?? '') <=> ($right['id'] ?? '');
        });

        return array_values($activities);
    }

    /**
     * @return iterable<WorkflowHistoryEvent>
     */
    private static function activityEvents(WorkflowRun $run): iterable
    {
        return $run->historyEvents
            ->filter(static fn (WorkflowHistoryEvent $event): bool => in_array($event->event_type, [
                HistoryEventType::ActivityScheduled,
                HistoryEventType::ActivityStarted,
                HistoryEventType::ActivityHeartbeatRecorded,
                HistoryEventType::ActivityRetryScheduled,
                HistoryEventType::ActivityCompleted,
                HistoryEventType::ActivityFailed,
            ], true))
            ->sortBy('sequence');
    }

    /**
     * @param array<string, mixed> $state
     * @param list<array<string, mixed>> $attemptStates
     * @return array<string, mixed>
     */
    private static function activityState(
        array $state,
        ?ActivityExecution $execution = null,
        array $attemptStates = [],
    ): array
    {
        return [
            'id' => $state['id'] ?? null,
            'idempotency_key' => $state['idempotency_key'] ?? $state['id'] ?? null,
            'sequence' => $state['sequence'] ?? null,
            'activity_type' => $state['type'] ?? null,
            'activity_class' => $state['class'] ?? null,
            'status' => $state['status'] ?? 'pending',
            'connection' => $state['connection'] ?? null,
            'queue' => $state['queue'] ?? null,
            'retry_policy' => $state['retry_policy'] ?? ($execution?->retry_policy ?? null),
            'attempt_count' => $state['attempt_count'] ?? 0,
            'current_attempt_id' => $state['attempt_id'] ?? $execution?->current_attempt_id,
            'arguments' => $state['arguments'] ?? null,
            'result' => $state['result'] ?? null,
            'created_at' => self::timestamp($state['created_at'] ?? null),
            'started_at' => self::timestamp($state['started_at'] ?? null),
            'last_heartbeat_at' => self::timestamp($state['last_heartbeat_at'] ?? null),
            'closed_at' => self::timestamp($state['closed_at'] ?? null),
            'attempts' => self::activityAttempts($state, $execution, $attemptStates),
        ];
    }

    /**
     * @param array<string, mixed> $state
     * @param list<array<string, mixed>> $attemptStates
     * @return list<array<string, mixed>>
     */
    private static function activityAttempts(
        array $state,
        ?ActivityExecution $execution,
        array $attemptStates,
    ): array
    {
        $attempts = array_map(
            static fn (array $attempt): array => self::activityAttemptSnapshot($attempt, $execution),
            $attemptStates,
        );

        $syntheticAttempt = self::syntheticActivityAttempt($state, $execution);

        if (
            $syntheticAttempt !== null
            && ! collect($attempts)->contains(static fn (array $attempt): bool => ($attempt['id'] ?? null) === $syntheticAttempt['id'])
        ) {
            $attempts[] = $syntheticAttempt;
        }

        usort($attempts, static function (array $left, array $right): int {
            $leftAttemptNumber = is_int($left['attempt_number'] ?? null) ? $left['attempt_number'] : PHP_INT_MAX;
            $rightAttemptNumber = is_int($right['attempt_number'] ?? null) ? $right['attempt_number'] : PHP_INT_MAX;

            if ($leftAttemptNumber !== $rightAttemptNumber) {
                return $leftAttemptNumber <=> $rightAttemptNumber;
            }

            $leftStartedAt = self::timestampToMilliseconds($left['started_at'] ?? null);
            $rightStartedAt = self::timestampToMilliseconds($right['started_at'] ?? null);

            if ($leftStartedAt !== $rightStartedAt) {
                return $leftStartedAt <=> $rightStartedAt;
            }

            return ($left['id'] ?? '') <=> ($right['id'] ?? '');
        });

        return array_values($attempts);
    }

    /**
     * @param array<string, mixed> $attempt
     * @return array<string, mixed>
     */
    private static function activityAttemptSnapshot(array $attempt, ?ActivityExecution $execution): array
    {
        return [
            'id' => $attempt['id'] ?? null,
            'activity_execution_id' => $attempt['activity_execution_id'] ?? ($execution?->id),
            'workflow_task_id' => $attempt['workflow_task_id'] ?? null,
            'attempt_number' => $attempt['attempt_number'] ?? null,
            'status' => $attempt['status'] ?? null,
            'lease_owner' => $attempt['lease_owner'] ?? null,
            'started_at' => self::timestamp($attempt['started_at'] ?? null),
            'last_heartbeat_at' => self::timestamp($attempt['last_heartbeat_at'] ?? null),
            'lease_expires_at' => self::timestamp($attempt['lease_expires_at'] ?? null),
            'closed_at' => self::timestamp($attempt['closed_at'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>|null
     */
    private static function syntheticActivityAttempt(array $state, ?ActivityExecution $execution): ?array
    {
        $attemptId = self::stringValue($state['attempt_id'] ?? null)
            ?? self::stringValue($execution?->current_attempt_id);
        $attemptNumber = self::intValue($state['attempt_count'] ?? null)
            ?? self::executionAttemptCount($execution);

        if ($attemptId === null || $attemptNumber === null || $attemptNumber <= 0) {
            return null;
        }

        return [
            'id' => $attemptId,
            'activity_execution_id' => $state['id'] ?? $execution?->id,
            'workflow_task_id' => null,
            'attempt_number' => $attemptNumber,
            'status' => $state['status'] ?? $execution?->status?->value ?? null,
            'lease_owner' => null,
            'started_at' => self::timestamp($state['started_at'] ?? $execution?->started_at),
            'last_heartbeat_at' => self::timestamp($state['last_heartbeat_at'] ?? $execution?->last_heartbeat_at),
            'lease_expires_at' => null,
            'closed_at' => self::timestamp($state['closed_at'] ?? $execution?->closed_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function activity(array $activity): array
    {
        return [
            'id' => $activity['id'] ?? null,
            'idempotency_key' => $activity['idempotency_key'] ?? null,
            'sequence' => $activity['sequence'] ?? null,
            'activity_type' => $activity['activity_type'] ?? null,
            'activity_class' => $activity['activity_class'] ?? null,
            'status' => $activity['status'] ?? null,
            'connection' => $activity['connection'] ?? null,
            'queue' => $activity['queue'] ?? null,
            'retry_policy' => $activity['retry_policy'] ?? null,
            'attempt_count' => $activity['attempt_count'] ?? 0,
            'current_attempt_id' => $activity['current_attempt_id'] ?? null,
            'arguments' => $activity['arguments'] ?? null,
            'result' => $activity['result'] ?? null,
            'created_at' => self::timestamp($activity['created_at'] ?? null),
            'started_at' => self::timestamp($activity['started_at'] ?? null),
            'last_heartbeat_at' => self::timestamp($activity['last_heartbeat_at'] ?? null),
            'closed_at' => self::timestamp($activity['closed_at'] ?? null),
            'attempts' => is_array($activity['attempts'] ?? null) ? $activity['attempts'] : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function timer(array $timer): array
    {
        return [
            'id' => $timer['id'] ?? null,
            'sequence' => $timer['sequence'] ?? null,
            'status' => $timer['status'] ?? null,
            'delay_seconds' => $timer['delay_seconds'] ?? null,
            'fire_at' => self::timestamp($timer['fire_at'] ?? null),
            'fired_at' => self::timestamp($timer['fired_at'] ?? null),
            'created_at' => self::timestamp($timer['created_at'] ?? null),
            'timer_kind' => $timer['timer_kind'] ?? null,
            'condition_wait_id' => $timer['condition_wait_id'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function failure(array $failure): array
    {
        return [
            'id' => $failure['id'] ?? null,
            'source_kind' => $failure['source_kind'] ?? null,
            'source_id' => $failure['source_id'] ?? null,
            'propagation_kind' => $failure['propagation_kind'] ?? null,
            'handled' => (bool) ($failure['handled'] ?? false),
            'exception_type' => $failure['exception_type'] ?? null,
            'exception_class' => $failure['exception_class'] ?? null,
            'exception_resolved_class' => $failure['exception_resolved_class'] ?? null,
            'exception_resolution_source' => $failure['exception_resolution_source'] ?? null,
            'exception_resolution_error' => $failure['exception_resolution_error'] ?? null,
            'exception_replay_blocked' => $failure['exception_replay_blocked'] ?? false,
            'message' => $failure['message'] ?? null,
            'file' => $failure['file'] ?? null,
            'line' => $failure['line'] ?? null,
            'trace_preview' => $failure['trace_preview'] ?? null,
            'created_at' => self::timestamp($failure['created_at'] ?? null),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function parentLinks(WorkflowRun $run): array
    {
        return collect(RunLineageView::parentsForRun($run))
            ->map(static fn (array $entry): array => [
                'id' => $entry['id'] ?? null,
                'type' => $entry['link_type'] ?? null,
                'parent_workflow_instance_id' => $entry['parent_workflow_id']
                    ?? $entry['workflow_instance_id']
                    ?? null,
                'parent_workflow_run_id' => $entry['parent_workflow_run_id']
                    ?? $entry['workflow_run_id']
                    ?? null,
                'child_workflow_instance_id' => $run->workflow_instance_id,
                'child_workflow_run_id' => $run->id,
                'child_call_id' => $entry['child_call_id'] ?? null,
                'sequence' => $entry['sequence'] ?? null,
                'is_primary_parent' => (bool) ($entry['is_primary_parent'] ?? false),
                'created_at' => self::lineageLinkCreatedAt($run, $entry, 'parent'),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function childLinks(WorkflowRun $run): array
    {
        return collect(RunLineageView::continuedWorkflowsForRun($run))
            ->map(static fn (array $entry): array => [
                'id' => $entry['id'] ?? null,
                'type' => $entry['link_type'] ?? null,
                'parent_workflow_instance_id' => $run->workflow_instance_id,
                'parent_workflow_run_id' => $run->id,
                'child_workflow_instance_id' => $entry['child_workflow_id']
                    ?? $entry['workflow_instance_id']
                    ?? null,
                'child_workflow_run_id' => $entry['child_workflow_run_id']
                    ?? $entry['workflow_run_id']
                    ?? null,
                'child_call_id' => $entry['child_call_id'] ?? null,
                'sequence' => $entry['sequence'] ?? null,
                'is_primary_parent' => (bool) ($entry['is_primary_parent'] ?? false),
                'created_at' => self::lineageLinkCreatedAt($run, $entry, 'child'),
            ])
            ->values()
            ->all();
    }

    private static function lineageLinkCreatedAt(WorkflowRun $run, array $entry, string $direction): ?string
    {
        $entryId = self::stringValue($entry['id'] ?? null);
        $entryType = self::stringValue($entry['link_type'] ?? null);
        $entryRunId = self::stringValue($entry['workflow_run_id'] ?? null);

        $links = $direction === 'parent'
            ? $run->parentLinks
            : $run->childLinks;

        foreach ($links as $link) {
            if ($entryId !== null && $link->id === $entryId) {
                return self::timestamp($link->created_at);
            }

            if ($entryType !== null && $link->link_type !== $entryType) {
                continue;
            }

            $linkRunId = $direction === 'parent'
                ? $link->parent_workflow_run_id
                : $link->child_workflow_run_id;

            if ($entryRunId !== null && $linkRunId === $entryRunId) {
                return self::timestamp($link->created_at);
            }
        }

        return null;
    }

    private static function timestamp(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->toJSON();
        }

        return is_string($value) && $value !== ''
            ? $value
            : null;
    }

    private static function timestampToMilliseconds(mixed $value): int
    {
        if ($value instanceof CarbonInterface) {
            return $value->getTimestampMs();
        }

        if (is_string($value) && $value !== '') {
            return Carbon::parse($value)->getTimestampMs();
        }

        return PHP_INT_MAX;
    }

    private static function stringValue(mixed $value): ?string
    {
        return is_string($value) && $value !== ''
            ? $value
            : null;
    }

    private static function intValue(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && is_numeric($value)
            ? (int) $value
            : null;
    }

    private static function executionAttemptCount(?ActivityExecution $execution): ?int
    {
        $attemptCount = is_int($execution?->attempt_count) ? $execution->attempt_count : 0;

        return $attemptCount > 0 ? $attemptCount : null;
    }
}
