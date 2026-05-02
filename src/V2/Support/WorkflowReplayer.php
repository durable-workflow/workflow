<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Workflow\Serializers\CodecRegistry;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTimer;

/**
 * @api Stable v2 debugging API for replaying live runs or HistoryExport bundles.
 */
final class WorkflowReplayer
{
    public function __construct(
        private readonly QueryStateReplayer $replayer = new QueryStateReplayer(),
    ) {
    }

    public function replay(WorkflowRun|array $history): ReplayState
    {
        return $this->replayer->replayState(
            $history instanceof WorkflowRun ? $history : $this->runFromHistoryExport($history),
        );
    }

    /**
     * @param array<string, mixed> $historyExport
     */
    public function replayExport(array $historyExport): ReplayState
    {
        return $this->replay($historyExport);
    }

    /**
     * @param array<string, mixed> $historyExport
     */
    public function runFromHistoryExport(array $historyExport): WorkflowRun
    {
        $this->assertHistoryExport($historyExport);

        $workflow = self::arrayValue($historyExport['workflow'] ?? null);
        $payloads = self::arrayValue($historyExport['payloads'] ?? null);
        $arguments = self::arrayValue($payloads['arguments'] ?? null);
        $output = self::arrayValue($payloads['output'] ?? null);
        $codec = self::stringValue($payloads['codec'] ?? null) ?? CodecRegistry::defaultCodec();
        $runId = self::requiredString($workflow, 'run_id');
        $instanceId = self::requiredString($workflow, 'instance_id');

        /** @var WorkflowRun $run */
        $run = $this->existingModel(WorkflowRun::class, [
            'id' => $runId,
            'workflow_instance_id' => $instanceId,
            'run_number' => self::intValue($workflow['run_number'] ?? null) ?? 1,
            'workflow_type' => self::requiredString($workflow, 'workflow_type'),
            'workflow_class' => self::requiredString($workflow, 'workflow_class'),
            'business_key' => self::stringValue($workflow['business_key'] ?? null),
            'visibility_labels' => self::jsonValue(self::arrayValue($workflow['visibility_labels'] ?? null)),
            'status' => self::stringValue($workflow['status'] ?? null) ?? 'running',
            'closed_reason' => self::stringValue($workflow['closed_reason'] ?? null),
            'compatibility' => self::stringValue($workflow['compatibility'] ?? null),
            'connection' => self::stringValue($workflow['connection'] ?? null),
            'queue' => self::stringValue($workflow['queue'] ?? null),
            'last_history_sequence' => self::intValue($workflow['last_history_sequence'] ?? null)
                ?? count(self::arrayValue($historyExport['history_events'] ?? null)),
            'arguments' => ($arguments['available'] ?? false) === true
                ? self::requiredString($arguments, 'data', 'payloads.arguments.data')
                : null,
            'output' => ($output['available'] ?? false) === true
                ? self::requiredString($output, 'data', 'payloads.output.data')
                : null,
            'payload_codec' => $codec,
            'started_at' => self::timestamp($workflow['started_at'] ?? null),
            'closed_at' => self::timestamp($workflow['closed_at'] ?? null),
            'archived_at' => self::timestamp($workflow['archived_at'] ?? null),
            'last_progress_at' => self::timestamp($workflow['last_progress_at'] ?? null),
        ]);

        /** @var WorkflowInstance $instance */
        $instance = $this->existingModel(WorkflowInstance::class, [
            'id' => $instanceId,
            'workflow_type' => $run->workflow_type,
            'workflow_class' => $run->workflow_class,
            'business_key' => $run->business_key,
            'visibility_labels' => self::jsonValue(self::arrayValue($workflow['visibility_labels'] ?? null)),
            'memo' => self::jsonValue(self::arrayValue($workflow['memo'] ?? null)),
            'current_run_id' => $run->id,
            'status' => $run->status?->value ?? $run->status,
        ]);

        $run->setRelation('instance', $instance);
        $instance->setRelation('currentRun', $run);
        $instance->setRelation('runs', collect([$run]));

        $run->setRelation('historyEvents', $this->historyEvents($historyExport, $run));
        $run->setRelation('commands', $this->commands($historyExport, $run));
        $run->setRelation('activityExecutions', $this->activities($historyExport, $run));
        $run->setRelation('timers', $this->timers($historyExport, $run));
        $run->setRelation('failures', $this->failures($historyExport, $run));

        foreach (['tasks', 'signals', 'updates', 'childLinks', 'parentLinks'] as $relation) {
            $run->setRelation($relation, collect());
        }

        return $run;
    }

    /**
     * @param array<string, mixed> $historyExport
     * @return Collection<int, WorkflowHistoryEvent>
     */
    private function historyEvents(array $historyExport, WorkflowRun $run): Collection
    {
        return collect(self::arrayValue($historyExport['history_events'] ?? null))
            ->filter(static fn (mixed $item): bool => is_array($item))
            ->map(function (array $event) use ($run): WorkflowHistoryEvent {
                $payload = self::arrayValue($event['payload'] ?? null);
                $type = self::requiredString($event, 'type', 'history_events[].type');

                /** @var WorkflowHistoryEvent $model */
                $model = $this->existingModel(WorkflowHistoryEvent::class, [
                    'id' => self::requiredString($event, 'id', 'history_events[].id'),
                    'workflow_run_id' => $run->id,
                    'sequence' => self::intValue($event['sequence'] ?? null) ?? 0,
                    'event_type' => $type,
                    'workflow_task_id' => self::stringValue($event['workflow_task_id'] ?? null),
                    'workflow_command_id' => self::stringValue($event['workflow_command_id'] ?? null),
                    'payload' => self::jsonValue($payload),
                    'recorded_at' => self::timestamp($event['recorded_at'] ?? null),
                ]);

                $model->setRelation('run', $run);

                return $model;
            })
            ->sortBy('sequence')
            ->values();
    }

    /**
     * @param array<string, mixed> $historyExport
     * @return Collection<int, WorkflowCommand>
     */
    private function commands(array $historyExport, WorkflowRun $run): Collection
    {
        return collect(self::arrayValue($historyExport['commands'] ?? null))
            ->filter(static fn (mixed $item): bool => is_array($item))
            ->map(function (array $command) use ($run): WorkflowCommand {
                /** @var WorkflowCommand $model */
                $model = $this->existingModel(WorkflowCommand::class, [
                    'id' => self::requiredString($command, 'id', 'commands[].id'),
                    'workflow_instance_id' => $run->workflow_instance_id,
                    'workflow_run_id' => $run->id,
                    'workflow_type' => $run->workflow_type,
                    'workflow_class' => $run->workflow_class,
                    'command_sequence' => self::intValue($command['sequence'] ?? null),
                    'command_type' => self::stringValue($command['type'] ?? null),
                    'target_scope' => self::stringValue($command['target_scope'] ?? null),
                    'requested_workflow_run_id' => self::stringValue($command['requested_run_id'] ?? null),
                    'resolved_workflow_run_id' => self::stringValue($command['resolved_run_id'] ?? null),
                    'payload_codec' => self::stringValue($command['payload_codec'] ?? null),
                    'payload' => self::stringValue($command['payload'] ?? null),
                    'source' => self::stringValue($command['source'] ?? null),
                    'context' => self::jsonValue(self::arrayValue($command['context'] ?? null)),
                    'status' => self::stringValue($command['status'] ?? null),
                    'outcome' => self::stringValue($command['outcome'] ?? null),
                    'rejection_reason' => self::stringValue($command['rejection_reason'] ?? null),
                    'accepted_at' => self::timestamp($command['accepted_at'] ?? null),
                    'applied_at' => self::timestamp($command['applied_at'] ?? null),
                    'rejected_at' => self::timestamp($command['rejected_at'] ?? null),
                ]);

                $model->setRelation('run', $run);

                return $model;
            })
            ->sortBy('command_sequence')
            ->values();
    }

    /**
     * @param array<string, mixed> $historyExport
     * @return Collection<int, ActivityExecution>
     */
    private function activities(array $historyExport, WorkflowRun $run): Collection
    {
        return collect(self::arrayValue($historyExport['activities'] ?? null))
            ->filter(static fn (mixed $item): bool => is_array($item))
            ->map(function (array $activity) use ($run): ActivityExecution {
                /** @var ActivityExecution $model */
                $model = $this->existingModel(ActivityExecution::class, [
                    'id' => self::requiredString($activity, 'id', 'activities[].id'),
                    'workflow_run_id' => $run->id,
                    'sequence' => self::intValue($activity['sequence'] ?? null),
                    'activity_type' => self::stringValue($activity['activity_type'] ?? null),
                    'activity_class' => self::stringValue($activity['activity_class'] ?? null),
                    'status' => self::stringValue($activity['source_status'] ?? null)
                        ?? self::stringValue($activity['status'] ?? null),
                    'arguments' => self::stringValue($activity['arguments'] ?? null),
                    'result' => self::stringValue($activity['result'] ?? null),
                    'connection' => self::stringValue($activity['connection'] ?? null),
                    'queue' => self::stringValue($activity['queue'] ?? null),
                    'retry_policy' => self::jsonValue(self::arrayValue($activity['retry_policy'] ?? null)),
                    'attempt_count' => self::intValue($activity['attempt_count'] ?? null) ?? 0,
                    'started_at' => self::timestamp($activity['started_at'] ?? null),
                    'closed_at' => self::timestamp($activity['closed_at'] ?? null),
                    'last_heartbeat_at' => self::timestamp($activity['last_heartbeat_at'] ?? null),
                ]);

                $model->setRelation('run', $run);
                $model->setRelation('attempts', collect());

                return $model;
            })
            ->sortBy('sequence')
            ->values();
    }

    /**
     * @param array<string, mixed> $historyExport
     * @return Collection<int, WorkflowTimer>
     */
    private function timers(array $historyExport, WorkflowRun $run): Collection
    {
        return collect(self::arrayValue($historyExport['timers'] ?? null))
            ->filter(static fn (mixed $item): bool => is_array($item))
            ->map(function (array $timer) use ($run): WorkflowTimer {
                /** @var WorkflowTimer $model */
                $model = $this->existingModel(WorkflowTimer::class, [
                    'id' => self::requiredString($timer, 'id', 'timers[].id'),
                    'workflow_run_id' => $run->id,
                    'sequence' => self::intValue($timer['sequence'] ?? null),
                    'status' => self::stringValue($timer['source_status'] ?? null)
                        ?? self::stringValue($timer['status'] ?? null),
                    'delay_seconds' => self::intValue($timer['delay_seconds'] ?? null),
                    'timer_kind' => self::stringValue($timer['timer_kind'] ?? null),
                    'condition_wait_id' => self::stringValue($timer['condition_wait_id'] ?? null),
                    'condition_key' => self::stringValue($timer['condition_key'] ?? null),
                    'condition_definition_fingerprint' => self::stringValue(
                        $timer['condition_definition_fingerprint'] ?? null,
                    ),
                    'signal_wait_id' => self::stringValue($timer['signal_wait_id'] ?? null),
                    'signal_name' => self::stringValue($timer['signal_name'] ?? null),
                    'fire_at' => self::timestamp($timer['fire_at'] ?? null),
                    'fired_at' => self::timestamp($timer['fired_at'] ?? null),
                ]);

                $model->setRelation('run', $run);

                return $model;
            })
            ->sortBy('sequence')
            ->values();
    }

    /**
     * @param array<string, mixed> $historyExport
     * @return Collection<int, WorkflowFailure>
     */
    private function failures(array $historyExport, WorkflowRun $run): Collection
    {
        return collect(self::arrayValue($historyExport['failures'] ?? null))
            ->filter(static fn (mixed $item): bool => is_array($item))
            ->map(function (array $failure) use ($run): WorkflowFailure {
                /** @var WorkflowFailure $model */
                $model = $this->existingModel(WorkflowFailure::class, [
                    'id' => self::requiredString($failure, 'id', 'failures[].id'),
                    'workflow_run_id' => $run->id,
                    'source_kind' => self::stringValue($failure['source_kind'] ?? null),
                    'source_id' => self::stringValue($failure['source_id'] ?? null),
                    'failure_category' => self::stringValue($failure['failure_category'] ?? null),
                    'exception_type' => self::stringValue($failure['exception_type'] ?? null),
                    'exception_class' => self::stringValue($failure['exception_class'] ?? null),
                    'message' => self::stringValue($failure['message'] ?? null),
                    'created_at' => self::timestamp($failure['created_at'] ?? null),
                ]);

                $model->setRelation('run', $run);

                return $model;
            })
            ->values();
    }

    /**
     * @param class-string<Model> $class
     * @param array<string, mixed> $attributes
     */
    private function existingModel(string $class, array $attributes): Model
    {
        /** @var Model $model */
        $model = new $class();
        $model->setRawAttributes(array_filter(
            $attributes,
            static fn (mixed $value): bool => $value !== null,
        ), true);
        $model->exists = true;

        return $model;
    }

    /**
     * @param array<string, mixed> $historyExport
     */
    private function assertHistoryExport(array $historyExport): void
    {
        if (($historyExport['schema'] ?? null) !== HistoryExport::SCHEMA) {
            throw new InvalidArgumentException(sprintf(
                'Workflow history replay expects a %s export bundle.',
                HistoryExport::SCHEMA,
            ));
        }

        if (($historyExport['schema_version'] ?? null) !== HistoryExport::SCHEMA_VERSION) {
            throw new InvalidArgumentException(sprintf(
                'Workflow history replay supports %s schema_version %d.',
                HistoryExport::SCHEMA,
                HistoryExport::SCHEMA_VERSION,
            ));
        }
    }

    /**
     * @param array<string, mixed> $values
     */
    private static function requiredString(array $values, string $key, ?string $path = null): string
    {
        $value = self::stringValue($values[$key] ?? null);

        if ($value === null) {
            throw new InvalidArgumentException(sprintf(
                'History export field [%s] must be a non-empty string.',
                $path ?? $key
            ));
        }

        return $value;
    }

    private static function stringValue(mixed $value): ?string
    {
        return is_string($value) && $value !== ''
            ? $value
            : null;
    }

    private static function intValue(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function arrayValue(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * @param array<mixed> $value
     */
    private static function jsonValue(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR);
    }

    private static function timestamp(mixed $value): ?Carbon
    {
        return is_string($value) && $value !== ''
            ? Carbon::parse($value)
            : null;
    }
}
