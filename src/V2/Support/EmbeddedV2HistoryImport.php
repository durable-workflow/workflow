<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;
use Workflow\Serializers\CodecRegistry;
use Workflow\V2\Enums\ActivityAttemptStatus;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\CommandStatus;
use Workflow\V2\Enums\CommandType;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\SignalStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Enums\TimerStatus;
use Workflow\V2\Enums\UpdateStatus;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowMemo;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowSearchAttribute;
use Workflow\V2\Models\WorkflowSignal;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimer;
use Workflow\V2\Models\WorkflowUpdate;

/**
 * @api Stable v2 server/operator import entry point for embedded history bundles.
 */
final class EmbeddedV2HistoryImport
{
    /**
     * Import one embedded v2 history-export bundle into the current v2 store.
     *
     * @param array<string, mixed> $bundle
     * @param array{
     *     dry_run?: bool,
     *     namespace?: string|null,
     *     import_id?: string|null,
     *     signing_key?: string|null,
     *     require_signature?: bool,
     *     allow_open_leases?: bool
     * } $options
     *
     * @return array<string, mixed>
     */
    public static function import(array $bundle, array $options = []): array
    {
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $workflow = self::workflowSummary($bundle, self::stringValue($options['namespace'] ?? null));
        $integrity = BundleIntegrityVerifier::verify($bundle, self::stringValue($options['signing_key'] ?? null));
        $eligibility = self::eligibility($bundle, $integrity, $workflow, $options);
        $base = self::baseReport($bundle, $workflow, $integrity, $eligibility, $dryRun);

        if (! $eligibility['eligible']) {
            return [
                ...$base,
                'status' => 'rejected',
                'rows' => self::emptyRows(),
            ];
        }

        $existing = self::existingRunOutcome($workflow['run_id'], self::stringValue($bundle['dedupe_key'] ?? null));

        if ($existing['status'] === 'already_imported') {
            return [
                ...$base,
                'status' => 'already_imported',
                'rows' => self::emptyRows(),
            ];
        }

        if ($existing['status'] === 'conflict') {
            return [
                ...$base,
                'status' => 'rejected',
                'eligibility' => self::withEligibilityError(
                    $eligibility,
                    'target.run_id_conflict',
                    'A workflow run with this run_id already exists and was not imported from the same bundle.',
                ),
                'rows' => self::emptyRows(),
            ];
        }

        if ($dryRun) {
            return [
                ...$base,
                'status' => 'dry_run',
                'rows' => self::estimatedRows($bundle),
            ];
        }

        $importId = self::stringValue($options['import_id'] ?? null) ?? (string) Str::ulid();
        $importedAt = now();

        try {
            $rows = DB::transaction(static function () use ($bundle, $workflow, $importId, $importedAt): array {
                return self::writeBundle($bundle, $workflow, $importId, $importedAt);
            });
        } catch (Throwable $throwable) {
            return [
                ...$base,
                'status' => 'rejected',
                'eligibility' => self::withEligibilityError(
                    $eligibility,
                    'target.write_failed',
                    $throwable->getMessage(),
                ),
                'rows' => self::emptyRows(),
            ];
        }

        return [
            ...$base,
            'status' => 'imported',
            'import_id' => $importId,
            'imported_at' => $importedAt->toJSON(),
            'rows' => $rows,
        ];
    }

    /**
     * @param array<string, mixed> $bundle
     * @param array<string, mixed> $workflow
     * @return array<string, int>
     */
    private static function writeBundle(
        array $bundle,
        array $workflow,
        string $importId,
        CarbonInterface $importedAt,
    ): array {
        $rows = self::emptyRows();
        $namespace = self::stringValue($workflow['namespace'] ?? null);
        $runStatus = RunStatus::from($workflow['status']);
        $runId = $workflow['run_id'];
        $instanceId = $workflow['instance_id'];
        $runNumber = self::intValue($workflow['run_number'] ?? null) ?? 1;
        $historySequence = max(
            self::intValue($workflow['last_history_sequence'] ?? null) ?? 0,
            self::maxSequence(self::listValue($bundle['history_events'] ?? null), 'sequence'),
        );
        $lastCommandSequence = self::maxSequence(self::listValue($bundle['commands'] ?? null), 'sequence');
        $messageCursor = self::maxSequence(self::listValue($bundle['commands'] ?? null), 'message_sequence');
        $payloads = self::arrayValue($bundle['payloads'] ?? null);
        $payloadCodec = self::stringValue($payloads['codec'] ?? null) ?? CodecRegistry::defaultCodec();
        $outputPayload = self::arrayValue($payloads['output'] ?? null);
        $outputPayloadCodec = ($outputPayload['available'] ?? false) === true
            ? self::stringValue($outputPayload['codec'] ?? null)
                ?? self::payloadEnvelopeCodec($outputPayload['data'] ?? null)
                ?? self::workflowOutputCodec($bundle)
            : null;

        /** @var WorkflowInstance|null $instance */
        $instance = WorkflowInstance::query()
            ->lockForUpdate()
            ->find($instanceId);

        if (! $instance instanceof WorkflowInstance) {
            /** @var WorkflowInstance $instance */
            $instance = WorkflowInstance::query()->create([
                'id' => $instanceId,
                'workflow_class' => $workflow['workflow_class'],
                'workflow_type' => $workflow['workflow_type'],
                'namespace' => $namespace,
                'business_key' => self::stringValue($workflow['business_key'] ?? null),
                'visibility_labels' => self::arrayValue($workflow['visibility_labels'] ?? null) ?? [],
                'memo' => self::arrayValue($workflow['memo'] ?? null) ?? [],
                'current_run_id' => null,
                'run_count' => $runNumber,
                'last_message_sequence' => $messageCursor,
                'started_at' => self::timestamp($workflow['started_at'] ?? null),
                'created_at' => self::timestamp($workflow['started_at'] ?? null) ?? $importedAt,
                'updated_at' => $importedAt,
            ]);
            $rows['workflow_instances']++;
        }

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->create([
            'id' => $runId,
            'workflow_instance_id' => $instanceId,
            'run_number' => $runNumber,
            'workflow_class' => $workflow['workflow_class'],
            'workflow_type' => $workflow['workflow_type'],
            'namespace' => $namespace,
            'business_key' => self::stringValue($workflow['business_key'] ?? null),
            'visibility_labels' => self::arrayValue($workflow['visibility_labels'] ?? null) ?? [],
            'status' => $runStatus->value,
            'closed_reason' => self::stringValue($workflow['closed_reason'] ?? null),
            'compatibility' => self::stringValue($workflow['compatibility'] ?? null),
            'payload_codec' => $payloadCodec,
            'output_payload_codec' => $outputPayloadCodec,
            'arguments' => self::payloadData($payloads, 'arguments'),
            'output' => self::payloadData($payloads, 'output'),
            'connection' => self::stringValue($workflow['connection'] ?? null),
            'queue' => self::stringValue($workflow['queue'] ?? null),
            'last_history_sequence' => $historySequence,
            'last_command_sequence' => $lastCommandSequence,
            'message_cursor_position' => $messageCursor,
            'started_at' => self::timestamp($workflow['started_at'] ?? null),
            'closed_at' => self::timestamp($workflow['closed_at'] ?? null),
            'archived_at' => self::timestamp($workflow['archived_at'] ?? null),
            'archive_command_id' => self::stringValue($workflow['archive_command_id'] ?? null),
            'archive_reason' => self::stringValue($workflow['archive_reason'] ?? null),
            'last_progress_at' => self::timestamp($workflow['last_progress_at'] ?? null) ?? $importedAt,
            'import_source' => EmbeddedV2ImportContract::IMPORT_SOURCE,
            'import_id' => $importId,
            'import_dedupe_key' => self::stringValue($bundle['dedupe_key'] ?? null),
            'import_contract_version' => EmbeddedV2ImportContract::VERSION,
            'imported_at' => $importedAt,
            'created_at' => self::timestamp($workflow['started_at'] ?? null) ?? $importedAt,
            'updated_at' => self::timestamp($workflow['last_progress_at'] ?? null) ?? $importedAt,
        ]);
        $rows['workflow_runs']++;

        $shouldBeCurrent = (bool) ($workflow['is_current_run'] ?? false)
            || ($instance->current_run_id === null && $runStatus->isTerminal());
        $instance->forceFill([
            'current_run_id' => $shouldBeCurrent ? $runId : $instance->current_run_id,
            'run_count' => max((int) $instance->run_count, $runNumber),
            'last_message_sequence' => max((int) $instance->last_message_sequence, $messageCursor),
            'updated_at' => $importedAt,
        ])->save();

        $rows['workflow_history_events'] += self::writeHistoryEvents($bundle, $runId);
        $rows['workflow_commands'] += self::writeCommands($bundle, $instanceId, $runId, $workflow);
        $rows['workflow_signal_records'] += self::writeSignals($bundle, $instanceId, $runId);
        $rows['workflow_updates'] += self::writeUpdates($bundle, $instanceId, $runId);
        $rows['workflow_tasks'] += self::writeTasks($bundle, $runId, $namespace);
        $rows['activity_executions'] += self::writeActivities($bundle, $runId, $payloadCodec);
        $rows['activity_attempts'] += self::writeActivityAttempts($bundle, $runId);
        $rows['workflow_run_timers'] += self::writeTimers($bundle, $runId);
        $rows['workflow_failures'] += self::writeFailures($bundle, $runId);
        $rows['workflow_links'] += self::writeLinks($bundle, $runId, $instanceId);
        $rows['workflow_memos'] += self::writeMemos($workflow, $runId, $instanceId, $historySequence);
        $rows['workflow_search_attributes'] += self::writeSearchAttributes(
            $workflow,
            $runId,
            $instanceId,
            $historySequence
        );

        RunSummaryProjector::project($run->fresh([
            'instance',
            'tasks',
            'activityExecutions',
            'timers',
            'failures',
            'historyEvents',
            'commands',
            'signals',
            'updates',
            'childLinks.childRun.instance.currentRun',
            'childLinks.childRun.failures',
        ]) ?? $run);

        $rows['workflow_run_summaries'] = 1;

        return $rows;
    }

    private static function writeHistoryEvents(array $bundle, string $runId): int
    {
        $count = 0;

        foreach (self::listValue($bundle['history_events'] ?? null) as $event) {
            $eventType = self::enumString(HistoryEventType::class, $event['type'] ?? null);

            if ($eventType === null) {
                continue;
            }

            WorkflowHistoryEvent::query()->create([
                'id' => self::stringValue($event['id'] ?? null) ?? (string) Str::ulid(),
                'workflow_run_id' => $runId,
                'sequence' => self::intValue($event['sequence'] ?? null) ?? 0,
                'event_type' => $eventType,
                'payload' => self::arrayValue($event['payload'] ?? null) ?? [],
                'workflow_task_id' => self::stringValue($event['workflow_task_id'] ?? null),
                'workflow_command_id' => self::stringValue($event['workflow_command_id'] ?? null),
                'recorded_at' => self::timestamp($event['recorded_at'] ?? null),
                'created_at' => self::timestamp($event['recorded_at'] ?? null) ?? now(),
                'updated_at' => self::timestamp($event['recorded_at'] ?? null) ?? now(),
            ]);
            $count++;
        }

        return $count;
    }

    /**
     * @param array<string, mixed> $workflow
     */
    private static function writeCommands(array $bundle, string $instanceId, string $runId, array $workflow): int
    {
        $count = 0;

        foreach (self::listValue($bundle['commands'] ?? null) as $command) {
            $type = self::enumString(CommandType::class, $command['type'] ?? null);
            $status = self::enumString(CommandStatus::class, $command['status'] ?? null);

            if ($type === null || $status === null) {
                continue;
            }

            $acceptedAt = self::timestamp($command['accepted_at'] ?? null);
            WorkflowCommand::query()->create([
                'id' => self::stringValue($command['id'] ?? null) ?? (string) Str::ulid(),
                'workflow_instance_id' => $instanceId,
                'workflow_run_id' => $runId,
                'requested_workflow_run_id' => self::stringValue($command['requested_run_id'] ?? null),
                'resolved_workflow_run_id' => self::stringValue($command['resolved_run_id'] ?? null) ?? $runId,
                'command_type' => $type,
                'target_scope' => self::stringValue($command['target_scope'] ?? null) ?? 'instance',
                'source' => self::stringValue($command['source'] ?? null) ?? 'embedded_v2_import',
                'context' => self::arrayValue($command['context'] ?? null) ?? [],
                'status' => $status,
                'outcome' => self::stringValue($command['outcome'] ?? null),
                'workflow_class' => self::stringValue($workflow['workflow_class'] ?? null),
                'workflow_type' => self::stringValue($workflow['workflow_type'] ?? null),
                'payload_codec' => self::stringValue($command['payload_codec'] ?? null),
                'payload' => self::payloadRowValue($command['payload'] ?? null),
                'rejection_reason' => self::stringValue($command['rejection_reason'] ?? null),
                'command_sequence' => self::intValue($command['sequence'] ?? null),
                'message_sequence' => self::intValue($command['message_sequence'] ?? null),
                'accepted_at' => $acceptedAt,
                'applied_at' => self::timestamp($command['applied_at'] ?? null),
                'rejected_at' => self::timestamp($command['rejected_at'] ?? null),
                'created_at' => $acceptedAt ?? now(),
                'updated_at' => self::timestamp($command['applied_at'] ?? null)
                    ?? self::timestamp($command['rejected_at'] ?? null)
                    ?? $acceptedAt
                    ?? now(),
            ]);
            $count++;
        }

        return $count;
    }

    private static function writeSignals(array $bundle, string $instanceId, string $runId): int
    {
        $count = 0;

        foreach (self::listValue($bundle['signals'] ?? null) as $signal) {
            $status = self::enumString(SignalStatus::class, $signal['status'] ?? null);

            if ($status === null) {
                continue;
            }

            $receivedAt = self::timestamp($signal['received_at'] ?? null);
            WorkflowSignal::query()->create([
                'id' => self::stringValue($signal['id'] ?? null) ?? (string) Str::ulid(),
                'workflow_command_id' => self::stringValue($signal['command_id'] ?? null) ?? (string) Str::ulid(),
                'workflow_instance_id' => $instanceId,
                'workflow_run_id' => $runId,
                'target_scope' => self::stringValue($signal['target_scope'] ?? null) ?? 'instance',
                'requested_workflow_run_id' => self::stringValue($signal['requested_run_id'] ?? null),
                'resolved_workflow_run_id' => self::stringValue($signal['resolved_run_id'] ?? null) ?? $runId,
                'signal_name' => self::stringValue($signal['name'] ?? null) ?? 'imported-signal',
                'signal_wait_id' => self::stringValue($signal['signal_wait_id'] ?? null),
                'status' => $status,
                'outcome' => self::stringValue($signal['outcome'] ?? null),
                'rejection_reason' => self::stringValue($signal['rejection_reason'] ?? null),
                'validation_errors' => self::arrayValue($signal['validation_errors'] ?? null) ?? [],
                'command_sequence' => self::intValue($signal['command_sequence'] ?? null),
                'workflow_sequence' => self::intValue($signal['workflow_sequence'] ?? null),
                'payload_codec' => self::stringValue($signal['payload_codec'] ?? null),
                'arguments' => self::payloadRowValue($signal['arguments'] ?? null),
                'received_at' => $receivedAt,
                'applied_at' => self::timestamp($signal['applied_at'] ?? null),
                'rejected_at' => self::timestamp($signal['rejected_at'] ?? null),
                'closed_at' => self::timestamp($signal['closed_at'] ?? null),
                'created_at' => $receivedAt ?? now(),
                'updated_at' => self::timestamp($signal['closed_at'] ?? null) ?? $receivedAt ?? now(),
            ]);
            $count++;
        }

        return $count;
    }

    private static function writeUpdates(array $bundle, string $instanceId, string $runId): int
    {
        $count = 0;

        foreach (self::listValue($bundle['updates'] ?? null) as $update) {
            $status = self::enumString(UpdateStatus::class, $update['status'] ?? null);

            if ($status === null) {
                continue;
            }

            $acceptedAt = self::timestamp($update['accepted_at'] ?? null);
            WorkflowUpdate::query()->create([
                'id' => self::stringValue($update['id'] ?? null) ?? (string) Str::ulid(),
                'workflow_command_id' => self::stringValue($update['command_id'] ?? null) ?? (string) Str::ulid(),
                'workflow_instance_id' => $instanceId,
                'workflow_run_id' => $runId,
                'target_scope' => self::stringValue($update['target_scope'] ?? null) ?? 'instance',
                'requested_workflow_run_id' => self::stringValue($update['requested_run_id'] ?? null),
                'resolved_workflow_run_id' => self::stringValue($update['resolved_run_id'] ?? null) ?? $runId,
                'update_name' => self::stringValue($update['name'] ?? null) ?? 'imported-update',
                'status' => $status,
                'outcome' => self::stringValue($update['outcome'] ?? null),
                'command_sequence' => self::intValue($update['command_sequence'] ?? null),
                'workflow_sequence' => self::intValue($update['workflow_sequence'] ?? null),
                'payload_codec' => self::stringValue($update['payload_codec'] ?? null),
                'arguments' => self::payloadRowValue($update['arguments'] ?? null),
                'result' => self::payloadRowValue($update['result'] ?? null),
                'validation_errors' => self::arrayValue($update['validation_errors'] ?? null) ?? [],
                'rejection_reason' => self::stringValue($update['rejection_reason'] ?? null),
                'failure_id' => self::stringValue($update['failure_id'] ?? null),
                'accepted_at' => $acceptedAt,
                'applied_at' => self::timestamp($update['applied_at'] ?? null),
                'rejected_at' => self::timestamp($update['rejected_at'] ?? null),
                'closed_at' => self::timestamp($update['closed_at'] ?? null),
                'created_at' => $acceptedAt ?? now(),
                'updated_at' => self::timestamp($update['closed_at'] ?? null) ?? $acceptedAt ?? now(),
            ]);
            $count++;
        }

        return $count;
    }

    private static function writeTasks(array $bundle, string $runId, ?string $namespace): int
    {
        $count = 0;

        foreach (self::listValue($bundle['tasks'] ?? null) as $task) {
            $type = self::enumString(TaskType::class, $task['type'] ?? null);
            $status = self::enumString(TaskStatus::class, $task['status'] ?? null);

            if ($type === null || $status === null) {
                continue;
            }

            $availableAt = self::timestamp($task['available_at'] ?? null);
            WorkflowTask::query()->create([
                'id' => self::stringValue($task['id'] ?? null) ?? (string) Str::ulid(),
                'workflow_run_id' => $runId,
                'namespace' => $namespace,
                'task_type' => $type,
                'status' => $status,
                'payload' => self::arrayValue($task['payload'] ?? null) ?? [],
                'connection' => self::stringValue($task['connection'] ?? null),
                'queue' => self::stringValue($task['queue'] ?? null),
                'compatibility' => self::stringValue($task['compatibility'] ?? null),
                'available_at' => $availableAt,
                'leased_at' => self::timestamp($task['leased_at'] ?? null),
                'lease_owner' => self::stringValue($task['lease_owner'] ?? null),
                'lease_expires_at' => self::timestamp($task['lease_expires_at'] ?? null),
                'attempt_count' => self::intValue($task['attempt_count'] ?? null) ?? 0,
                'repair_count' => self::intValue($task['repair_count'] ?? null) ?? 0,
                'last_dispatch_attempt_at' => self::timestamp($task['last_dispatch_attempt_at'] ?? null),
                'last_dispatched_at' => self::timestamp($task['last_dispatched_at'] ?? null),
                'last_dispatch_error' => self::stringValue($task['last_dispatch_error'] ?? null),
                'last_claim_failed_at' => self::timestamp($task['last_claim_failed_at'] ?? null),
                'last_claim_error' => self::stringValue($task['last_claim_error'] ?? null),
                'repair_available_at' => self::timestamp($task['repair_available_at'] ?? null),
                'created_at' => $availableAt ?? now(),
                'updated_at' => self::timestamp($task['last_dispatched_at'] ?? null) ?? $availableAt ?? now(),
            ]);
            $count++;
        }

        return $count;
    }

    private static function writeActivities(array $bundle, string $runId, string $runCodec): int
    {
        $count = 0;

        foreach (self::listValue($bundle['activities'] ?? null) as $activity) {
            $status = self::enumString(ActivityStatus::class, $activity['status'] ?? null);
            $activityId = self::stringValue($activity['id'] ?? null);

            if ($activityId === null || $status === null) {
                continue;
            }

            $createdAt = self::timestamp($activity['created_at'] ?? null);
            ActivityExecution::query()->create([
                'id' => $activityId,
                'workflow_run_id' => $runId,
                'sequence' => self::intValue($activity['sequence'] ?? null) ?? 0,
                'activity_class' => self::stringValue($activity['activity_class'] ?? null)
                    ?? self::stringValue($activity['activity_type'] ?? null)
                    ?? 'imported-activity',
                'activity_type' => self::stringValue($activity['activity_type'] ?? null)
                    ?? self::stringValue($activity['activity_class'] ?? null)
                    ?? 'imported-activity',
                'status' => $status,
                'payload_codec' => self::stringValue($activity['payload_codec'] ?? null) ?? $runCodec,
                'arguments' => self::payloadRowValue($activity['arguments'] ?? null),
                'result' => self::payloadRowValue($activity['result'] ?? null),
                'exception' => self::stringValue($activity['exception'] ?? null),
                'connection' => self::stringValue($activity['connection'] ?? null),
                'queue' => self::stringValue($activity['queue'] ?? null),
                'attempt_count' => self::intValue($activity['attempt_count'] ?? null) ?? 0,
                'current_attempt_id' => self::stringValue($activity['current_attempt_id'] ?? null),
                'retry_policy' => self::arrayValue($activity['retry_policy'] ?? null) ?? [],
                'parallel_group_path' => self::arrayValue($activity['parallel_group_path'] ?? null) ?? [],
                'started_at' => self::timestamp($activity['started_at'] ?? null),
                'closed_at' => self::timestamp($activity['closed_at'] ?? null),
                'last_heartbeat_at' => self::timestamp($activity['last_heartbeat_at'] ?? null),
                'created_at' => $createdAt ?? now(),
                'updated_at' => self::timestamp($activity['closed_at'] ?? null) ?? $createdAt ?? now(),
            ]);
            $count++;
        }

        return $count;
    }

    private static function writeActivityAttempts(array $bundle, string $runId): int
    {
        $count = 0;

        foreach (self::listValue($bundle['activities'] ?? null) as $activity) {
            $activityId = self::stringValue($activity['id'] ?? null);

            if ($activityId === null) {
                continue;
            }

            foreach (self::listValue($activity['attempts'] ?? null) as $attempt) {
                $status = self::enumString(ActivityAttemptStatus::class, $attempt['status'] ?? null);

                if ($status === null) {
                    continue;
                }

                $startedAt = self::timestamp($attempt['started_at'] ?? null) ?? now();
                ActivityAttempt::query()->create([
                    'id' => self::stringValue($attempt['id'] ?? null) ?? (string) Str::ulid(),
                    'workflow_run_id' => $runId,
                    'activity_execution_id' => $activityId,
                    'workflow_task_id' => self::stringValue($attempt['workflow_task_id'] ?? null)
                        ?? self::stringValue($attempt['task_id'] ?? null),
                    'attempt_number' => self::intValue($attempt['attempt_number'] ?? null) ?? 1,
                    'status' => $status,
                    'lease_owner' => self::stringValue($attempt['lease_owner'] ?? null),
                    'started_at' => $startedAt,
                    'last_heartbeat_at' => self::timestamp($attempt['last_heartbeat_at'] ?? null),
                    'lease_expires_at' => self::timestamp($attempt['lease_expires_at'] ?? null),
                    'closed_at' => self::timestamp($attempt['closed_at'] ?? null),
                    'created_at' => $startedAt,
                    'updated_at' => self::timestamp($attempt['closed_at'] ?? null) ?? $startedAt,
                ]);
                $count++;
            }
        }

        return $count;
    }

    private static function writeTimers(array $bundle, string $runId): int
    {
        $count = 0;

        foreach (self::listValue($bundle['timers'] ?? null) as $timer) {
            $status = self::enumString(TimerStatus::class, $timer['status'] ?? null);
            $timerId = self::stringValue($timer['id'] ?? null);

            if ($timerId === null || $status === null) {
                continue;
            }

            $createdAt = self::timestamp($timer['created_at'] ?? null);
            WorkflowTimer::query()->create([
                'id' => $timerId,
                'workflow_run_id' => $runId,
                'sequence' => self::intValue($timer['sequence'] ?? null) ?? 0,
                'status' => $status,
                'delay_seconds' => max(0, self::intValue($timer['delay_seconds'] ?? null) ?? 0),
                'fire_at' => self::timestamp($timer['fire_at'] ?? null) ?? $createdAt ?? now(),
                'fired_at' => self::timestamp($timer['fired_at'] ?? null),
                'created_at' => $createdAt ?? now(),
                'updated_at' => self::timestamp($timer['fired_at'] ?? null) ?? $createdAt ?? now(),
            ]);
            $count++;
        }

        return $count;
    }

    private static function writeFailures(array $bundle, string $runId): int
    {
        $count = 0;

        foreach (self::listValue($bundle['failures'] ?? null) as $failure) {
            $createdAt = self::timestamp($failure['created_at'] ?? null);
            WorkflowFailure::query()->create([
                'id' => self::stringValue($failure['id'] ?? null) ?? (string) Str::ulid(),
                'workflow_run_id' => $runId,
                'source_kind' => self::stringValue($failure['source_kind'] ?? null) ?? 'workflow',
                'source_id' => self::stringValue($failure['source_id'] ?? null) ?? $runId,
                'propagation_kind' => self::stringValue($failure['propagation_kind'] ?? null) ?? 'local',
                'failure_category' => self::stringValue($failure['failure_category'] ?? null),
                'non_retryable' => (bool) ($failure['non_retryable'] ?? false),
                'handled' => (bool) ($failure['handled'] ?? false),
                'exception_class' => self::stringValue($failure['exception_class'] ?? null)
                    ?? self::stringValue($failure['exception_type'] ?? null)
                    ?? 'ImportedWorkflowFailure',
                'message' => self::stringValue($failure['message'] ?? null) ?? '',
                'file' => self::stringValue($failure['file'] ?? null) ?? '',
                'line' => self::intValue($failure['line'] ?? null),
                'trace_preview' => self::stringValue($failure['trace_preview'] ?? null),
                'created_at' => $createdAt ?? now(),
                'updated_at' => $createdAt ?? now(),
            ]);
            $count++;
        }

        return $count;
    }

    private static function writeLinks(array $bundle, string $runId, string $instanceId): int
    {
        $links = self::arrayValue($bundle['links'] ?? null);
        $count = 0;

        foreach (self::listValue($links['parents'] ?? null) as $link) {
            $parentInstance = self::stringValue($link['parent_workflow_instance_id'] ?? null);
            $parentRun = self::stringValue($link['parent_workflow_run_id'] ?? null);

            if ($parentInstance === null || $parentRun === null) {
                continue;
            }

            WorkflowLink::query()->create([
                'id' => self::ulidValue($link['id'] ?? null) ?? (string) Str::ulid(),
                'link_type' => self::stringValue($link['type'] ?? null) ?? 'parent',
                'sequence' => self::intValue($link['sequence'] ?? null),
                'parent_workflow_instance_id' => $parentInstance,
                'parent_workflow_run_id' => $parentRun,
                'child_workflow_instance_id' => $instanceId,
                'child_workflow_run_id' => $runId,
                'is_primary_parent' => (bool) ($link['is_primary_parent'] ?? false),
                'created_at' => self::timestamp($link['created_at'] ?? null) ?? now(),
                'updated_at' => self::timestamp($link['created_at'] ?? null) ?? now(),
            ]);
            $count++;
        }

        foreach (self::listValue($links['children'] ?? null) as $link) {
            $childInstance = self::stringValue($link['child_workflow_instance_id'] ?? null);
            $childRun = self::stringValue($link['child_workflow_run_id'] ?? null);

            if ($childInstance === null || $childRun === null) {
                continue;
            }

            WorkflowLink::query()->create([
                'id' => self::ulidValue($link['id'] ?? null) ?? (string) Str::ulid(),
                'link_type' => self::stringValue($link['type'] ?? null) ?? 'child',
                'sequence' => self::intValue($link['sequence'] ?? null),
                'parent_workflow_instance_id' => $instanceId,
                'parent_workflow_run_id' => $runId,
                'child_workflow_instance_id' => $childInstance,
                'child_workflow_run_id' => $childRun,
                'is_primary_parent' => (bool) ($link['is_primary_parent'] ?? false),
                'created_at' => self::timestamp($link['created_at'] ?? null) ?? now(),
                'updated_at' => self::timestamp($link['created_at'] ?? null) ?? now(),
            ]);
            $count++;
        }

        return $count;
    }

    /**
     * @param array<string, mixed> $workflow
     */
    private static function writeMemos(array $workflow, string $runId, string $instanceId, int $sequence): int
    {
        $count = 0;

        foreach (self::arrayValue($workflow['memo'] ?? null) ?? [] as $key => $value) {
            if (! is_string($key) || $key === '') {
                continue;
            }

            $memo = new WorkflowMemo([
                'workflow_run_id' => $runId,
                'workflow_instance_id' => $instanceId,
                'key' => $key,
                'upserted_at_sequence' => $sequence,
                'inherited_from_parent' => false,
            ]);
            $memo->setValue($value);
            $memo->save();
            $count++;
        }

        return $count;
    }

    /**
     * @param array<string, mixed> $workflow
     */
    private static function writeSearchAttributes(
        array $workflow,
        string $runId,
        string $instanceId,
        int $sequence
    ): int {
        $count = 0;

        foreach (self::arrayValue($workflow['search_attributes'] ?? null) ?? [] as $key => $value) {
            if (! is_string($key) || $key === '') {
                continue;
            }

            $attribute = new WorkflowSearchAttribute([
                'workflow_run_id' => $runId,
                'workflow_instance_id' => $instanceId,
                'key' => $key,
                'upserted_at_sequence' => $sequence,
                'inherited_from_parent' => false,
            ]);
            $attribute->setTypedValueWithInference($value);
            $attribute->save();
            $count++;
        }

        return $count;
    }

    /**
     * @param array<string, mixed> $bundle
     * @return array<string, mixed>
     */
    private static function workflowSummary(array $bundle, ?string $namespaceOverride): array
    {
        $workflow = self::arrayValue($bundle['workflow'] ?? null) ?? [];
        $status = self::stringValue($workflow['status'] ?? null) ?? RunStatus::Running->value;
        $type = self::stringValue($workflow['workflow_type'] ?? null)
            ?? self::stringValue($workflow['workflow_class'] ?? null)
            ?? 'imported-workflow';

        return [
            ...$workflow,
            'instance_id' => self::stringValue($workflow['instance_id'] ?? null),
            'run_id' => self::stringValue($workflow['run_id'] ?? null),
            'run_number' => self::intValue($workflow['run_number'] ?? null) ?? 1,
            'workflow_type' => $type,
            'workflow_class' => self::stringValue($workflow['workflow_class'] ?? null) ?? $type,
            'namespace' => $namespaceOverride
                ?? self::stringValue($workflow['namespace'] ?? null)
                ?? self::stringValue(config('workflows.v2.namespace')),
            'status' => $status,
        ];
    }

    /**
     * @param array<string, mixed> $integrity
     * @param array<string, mixed> $workflow
     * @param array<string, mixed> $options
     * @return array{eligible: bool, errors: list<array<string, string>>, warnings: list<array<string, string>>}
     */
    private static function eligibility(array $bundle, array $integrity, array $workflow, array $options): array
    {
        $errors = [];
        $warnings = [];
        $runStatus = is_string($workflow['status'] ?? null) ? RunStatus::tryFrom($workflow['status']) : null;

        if (! Schema::hasColumn('workflow_runs', 'import_source')) {
            self::addFinding(
                $errors,
                'target.import_markers_missing',
                'Run import marker columns are missing; run migrations before importing.'
            );
        }

        if (($integrity['status'] ?? null) === BundleIntegrityVerifier::STATUS_FAILED) {
            self::addFinding($errors, 'bundle.integrity_failed', 'Bundle integrity verification failed.');
        } elseif (($integrity['status'] ?? null) === BundleIntegrityVerifier::STATUS_WARNING) {
            self::addFinding($warnings, 'bundle.integrity_warning', 'Bundle integrity verification reported warnings.');
        }

        if ((bool) ($options['require_signature'] ?? false)) {
            $signatureVerified = $integrity['integrity']['signature_verified'] ?? null;

            if ($signatureVerified !== true) {
                self::addFinding(
                    $errors,
                    'bundle.signature_required',
                    'A verified history-export signature is required for this import.'
                );
            }
        }

        if (self::stringValue($workflow['instance_id'] ?? null) === null) {
            self::addFinding($errors, 'workflow.instance_id_missing', 'Bundle workflow.instance_id is required.');
        }

        if (self::stringValue($workflow['run_id'] ?? null) === null) {
            self::addFinding($errors, 'workflow.run_id_missing', 'Bundle workflow.run_id is required.');
        }

        if ($runStatus === null) {
            self::addFinding(
                $errors,
                'workflow.status_unsupported',
                'Bundle workflow.status is not a supported v2 run status.'
            );
        } elseif ($runStatus->isTerminal()) {
            if (($bundle['history_complete'] ?? null) !== true) {
                self::addFinding(
                    $errors,
                    'workflow.terminal_history_incomplete',
                    'Terminal run imports require history_complete=true.'
                );
            }
        } elseif ((bool) ($workflow['is_current_run'] ?? false) !== true) {
            self::addFinding(
                $errors,
                'workflow.non_terminal_not_current',
                'Non-terminal embedded v2 imports must be the current run.'
            );
        }

        if (self::stringValue($workflow['source_runtime'] ?? null) !== EmbeddedV2ImportContract::SOURCE_RUNTIME) {
            self::addFinding(
                $errors,
                'workflow.source_runtime_unsupported',
                'Bundle workflow.source_runtime must be embedded.'
            );
        }

        $redaction = self::arrayValue($bundle['redaction'] ?? null) ?? [];
        if (self::listValue($redaction['paths'] ?? null) !== []) {
            self::addFinding(
                $errors,
                'bundle.redacted_payloads',
                'Redacted history bundles cannot be imported as server-managed state.'
            );
        }

        if (! (bool) ($options['allow_open_leases'] ?? false)) {
            self::rejectOpenLeases($bundle, $errors);
        }

        return [
            'eligible' => $errors === [],
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param list<array<string, string>> $errors
     */
    private static function rejectOpenLeases(array $bundle, array &$errors): void
    {
        foreach (self::listValue($bundle['tasks'] ?? null) as $task) {
            if (($task['status'] ?? null) === TaskStatus::Leased->value) {
                self::addFinding(
                    $errors,
                    'tasks.leased_task_present',
                    'Leased workflow or activity tasks must be completed, failed, or released before import.'
                );

                return;
            }
        }

        foreach (self::listValue($bundle['activities'] ?? null) as $activity) {
            foreach (self::listValue($activity['attempts'] ?? null) as $attempt) {
                if (($attempt['status'] ?? null) === ActivityAttemptStatus::Running->value) {
                    self::addFinding(
                        $errors,
                        'activities.running_attempt_present',
                        'Running activity attempts must be released before import.'
                    );

                    return;
                }
            }
        }
    }

    /**
     * @return array{status: string}
     */
    private static function existingRunOutcome(?string $runId, ?string $dedupeKey): array
    {
        if ($runId === null) {
            return [
                'status' => 'none',
            ];
        }

        /** @var WorkflowRun|null $run */
        $run = WorkflowRun::query()->find($runId);

        if (! $run instanceof WorkflowRun) {
            return [
                'status' => 'none',
            ];
        }

        if (
            $run->import_source === EmbeddedV2ImportContract::IMPORT_SOURCE
            && is_string($run->import_dedupe_key)
            && $run->import_dedupe_key === $dedupeKey
        ) {
            return [
                'status' => 'already_imported',
            ];
        }

        return [
            'status' => 'conflict',
        ];
    }

    /**
     * @param array<string, mixed> $bundle
     * @param array<string, mixed> $workflow
     * @param array<string, mixed> $integrity
     * @param array<string, mixed> $eligibility
     * @return array<string, mixed>
     */
    private static function baseReport(
        array $bundle,
        array $workflow,
        array $integrity,
        array $eligibility,
        bool $dryRun,
    ): array {
        return [
            'schema' => EmbeddedV2ImportContract::REPORT_SCHEMA,
            'schema_version' => EmbeddedV2ImportContract::REPORT_SCHEMA_VERSION,
            'contract_version' => EmbeddedV2ImportContract::VERSION,
            'source' => EmbeddedV2ImportContract::IMPORT_SOURCE,
            'dry_run' => $dryRun,
            'workflow' => [
                'instance_id' => self::stringValue($workflow['instance_id'] ?? null),
                'run_id' => self::stringValue($workflow['run_id'] ?? null),
                'run_number' => self::intValue($workflow['run_number'] ?? null),
                'workflow_type' => self::stringValue($workflow['workflow_type'] ?? null),
                'namespace' => self::stringValue($workflow['namespace'] ?? null),
                'status' => self::stringValue($workflow['status'] ?? null),
                'is_current_run' => (bool) ($workflow['is_current_run'] ?? false),
                'history_complete' => (bool) ($bundle['history_complete'] ?? false),
                'dedupe_key' => self::stringValue($bundle['dedupe_key'] ?? null),
            ],
            'eligibility' => $eligibility,
            'integrity' => $integrity,
            'rollback' => [
                'mode' => 'database_transaction',
                'partial_import_behavior' => 'no_rows_committed_on_failure',
            ],
        ];
    }

    /**
     * @return array<string, int>
     */
    private static function estimatedRows(array $bundle): array
    {
        return [
            ...self::emptyRows(),
            'workflow_instances' => 1,
            'workflow_runs' => 1,
            'workflow_history_events' => count(self::listValue($bundle['history_events'] ?? null)),
            'workflow_commands' => count(self::listValue($bundle['commands'] ?? null)),
            'workflow_signal_records' => count(self::listValue($bundle['signals'] ?? null)),
            'workflow_updates' => count(self::listValue($bundle['updates'] ?? null)),
            'workflow_tasks' => count(self::listValue($bundle['tasks'] ?? null)),
            'activity_executions' => count(self::listValue($bundle['activities'] ?? null)),
            'activity_attempts' => array_sum(array_map(
                static fn (array $activity): int => count(self::listValue($activity['attempts'] ?? null)),
                self::listValue($bundle['activities'] ?? null),
            )),
            'workflow_run_timers' => count(self::listValue($bundle['timers'] ?? null)),
            'workflow_failures' => count(self::listValue($bundle['failures'] ?? null)),
            'workflow_links' => count(
                self::listValue((self::arrayValue($bundle['links'] ?? null) ?? [])['parents'] ?? null)
            )
                + count(self::listValue((self::arrayValue($bundle['links'] ?? null) ?? [])['children'] ?? null)),
            'workflow_run_summaries' => 1,
        ];
    }

    /**
     * @return array<string, int>
     */
    private static function emptyRows(): array
    {
        return [
            'workflow_instances' => 0,
            'workflow_runs' => 0,
            'workflow_history_events' => 0,
            'workflow_commands' => 0,
            'workflow_signal_records' => 0,
            'workflow_updates' => 0,
            'workflow_tasks' => 0,
            'activity_executions' => 0,
            'activity_attempts' => 0,
            'workflow_run_timers' => 0,
            'workflow_failures' => 0,
            'workflow_links' => 0,
            'workflow_memos' => 0,
            'workflow_search_attributes' => 0,
            'workflow_run_summaries' => 0,
        ];
    }

    /**
     * @param array{eligible: bool, errors: list<array<string, string>>, warnings: list<array<string, string>>} $eligibility
     * @return array{eligible: false, errors: list<array<string, string>>, warnings: list<array<string, string>>}
     */
    private static function withEligibilityError(array $eligibility, string $rule, string $message): array
    {
        self::addFinding($eligibility['errors'], $rule, $message);
        $eligibility['eligible'] = false;

        return $eligibility;
    }

    /**
     * @param list<array<string, string>> $findings
     */
    private static function addFinding(array &$findings, string $rule, string $message): void
    {
        $findings[] = [
            'rule' => $rule,
            'message' => $message,
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private static function maxSequence(array $rows, string $key): int
    {
        $max = 0;

        foreach ($rows as $row) {
            $value = self::intValue($row[$key] ?? null);

            if ($value !== null && $value > $max) {
                $max = $value;
            }
        }

        return $max;
    }

    /**
     * @param class-string<\BackedEnum> $enum
     */
    private static function enumString(string $enum, mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return $enum::tryFrom($value)?->value;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function arrayValue(mixed $value): ?array
    {
        return is_array($value) ? $value : null;
    }

    /**
     * @param array<string, mixed> $bundle
     */
    private static function workflowOutputCodec(array $bundle): ?string
    {
        $events = array_reverse(self::listValue($bundle['history_events'] ?? null));

        foreach ($events as $event) {
            if (($event['type'] ?? null) !== HistoryEventType::WorkflowCompleted->value
                || ! is_array($event['payload'] ?? null)
            ) {
                continue;
            }

            return self::stringValue($event['payload']['payload_codec'] ?? null);
        }

        return null;
    }

    private static function payloadEnvelopeCodec(mixed $payload): ?string
    {
        return is_array($payload)
            ? self::stringValue($payload['codec'] ?? null)
            : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function listValue(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn (mixed $row): bool => is_array($row)));
    }

    private static function stringValue(mixed $value): ?string
    {
        return is_string($value) && $value !== ''
            ? $value
            : null;
    }

    private static function ulidValue(mixed $value): ?string
    {
        $value = self::stringValue($value);

        return $value !== null && strlen($value) <= 26
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

    private static function timestamp(mixed $value): ?CarbonInterface
    {
        if ($value instanceof CarbonInterface) {
            return $value;
        }

        return is_string($value) && $value !== ''
            ? Carbon::parse($value)
            : null;
    }

    /**
     * @param array<string, mixed> $payloads
     */
    private static function payloadData(?array $payloads, string $key): ?string
    {
        if ($payloads === null) {
            return null;
        }

        $payload = self::arrayValue($payloads[$key] ?? null);

        if ($payload === null || ($payload['available'] ?? null) !== true) {
            return null;
        }

        return self::payloadRowValue($payload['data'] ?? null);
    }

    private static function payloadRowValue(mixed $value): ?string
    {
        $string = self::stringValue($value);

        if ($string !== null) {
            return $string;
        }

        if (! is_array($value)) {
            return null;
        }

        $blob = self::stringValue($value['blob'] ?? null);

        if ($blob !== null) {
            return $blob;
        }

        if (isset($value['external_storage']) && is_array($value['external_storage'])) {
            return ExternalPayloads::encodeStoredEnvelope($value);
        }

        return null;
    }
}
