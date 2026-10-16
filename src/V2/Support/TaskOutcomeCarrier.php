<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\Context\Context;
use Throwable;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;

/** @internal Optional observations of physical outcomes, never completion authority. */
final class TaskOutcomeCarrier
{
    public const EVENTS = ['ActivityCompleted', 'ActivityFailed', 'ActivityTimedOut', 'TimerFired',
        'WorkflowCompleted', 'WorkflowFailed'];

    private const SCHEMA = 'durable-workflow.task-outcomes';

    public static function capture(SpanInterface $span): ?array
    {
        if (! $span->isRecording()) {
            return null;
        }
        $headers = [];
        // The official propagator reads the SDK span directly; application hooks are not invoked.
        TraceContextPropagator::getInstance()->inject($headers, context: $span->storeInContext(Context::getRoot()));

        return TraceCarrier::normalize([
            'schema' => 'durable-workflow.trace-context',
            'version' => 1,
            'carrier' => $headers,
        ]);
    }

    public static function identity(WorkflowHistoryEvent $event): array
    {
        return match ($event->event_type->value) {
            'ActivityCompleted', 'ActivityFailed', 'ActivityTimedOut' => [
                'activity_id' => $event->payload['activity_execution_id'] ?? null,
                'activity_attempt_id' => $event->payload['activity_attempt_id'] ?? null,
            ],
            'TimerFired' => [
                'timer_id' => $event->payload['timer_id'] ?? null,
            ],
            default => [],
        };
    }

    public static function fromTask(WorkflowTask $task): ?array
    {
        $attributes = $task->getAttributes();
        $value = $attributes['observability_context'] ?? null;
        if (is_string($value)) {
            if (strlen($value) > 4096) {
                return null;
            }
            try {
                $value = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                return null;
            }
        }
        if (! is_array($value) || ($value['schema'] ?? null) !== self::SCHEMA || ($value['version'] ?? null) !== 1
            || ($value['task_id'] ?? null) !== ($attributes['id'] ?? null)
            || ($value['run_id'] ?? null) !== ($attributes['workflow_run_id'] ?? null)
            || ($value['namespace'] ?? null) !== ($attributes['namespace'] ?? null)
            || ! self::identifier($value['task_id'] ?? null) || ! self::identifier($value['run_id'] ?? null)
            || ! self::identifier($value['namespace'] ?? null)
            || array_diff(
                array_keys($value),
                ['schema', 'version', 'task_id', 'run_id', 'namespace', 'outcomes']
            ) !== []
            || ! is_array($value['outcomes'] ?? null) || ! array_is_list($value['outcomes'])
            || count($value['outcomes']) > 32) {
            return null;
        }
        foreach ($value['outcomes'] as $index => $entry) {
            $entry = self::entry($entry);
            if ($entry === null) {
                return null;
            }
            $value['outcomes'][$index] = $entry;
        }

        return strlen(json_encode($value, JSON_THROW_ON_ERROR)) <= 4096 ? $value : null;
    }

    public static function append(WorkflowTask $task, array $entries): void
    {
        $attributes = $task->getAttributes();
        if ($entries === [] || ! array_key_exists('observability_context', $attributes)) {
            return;
        }
        $stored = $attributes['observability_context'] === null ? [
            'schema' => self::SCHEMA,
            'version' => 1,
            'task_id' => $attributes['id'],
            'run_id' => $attributes['workflow_run_id'],
            'namespace' => $attributes['namespace'],
            'outcomes' => [],
        ] : self::fromTask($task);
        if ($stored === null) {
            return;
        }
        $seen = array_fill_keys(array_column($stored['outcomes'], 'event_id'), true);
        $changed = false;
        foreach ($entries as $entry) {
            $entry = self::entry($entry);
            if ($entry === null || isset($seen[$entry['event_id']]) || count($stored['outcomes']) >= 32) {
                continue;
            }
            $next = $stored;
            $next['outcomes'][] = $entry;
            if (strlen(json_encode($next, JSON_THROW_ON_ERROR)) <= 4096) {
                $stored = $next;
                $seen[$entry['event_id']] = true;
                $changed = true;
            }
        }
        if ($changed) {
            // Preserve normal task casts and business attributes without invoking a telemetry setter.
            $attributes['observability_context'] = json_encode($stored, JSON_THROW_ON_ERROR);
            $task->setRawAttributes($attributes);
        }
    }

    public static function entry(mixed $value): ?array
    {
        if (! is_array($value) || ! self::identifier($value['event_id'] ?? null)
            || ! is_int($value['sequence'] ?? null) || $value['sequence'] < 1
            || ! in_array($value['event_type'] ?? null, self::EVENTS, true)
            || array_diff(array_keys($value), ['event_id', 'sequence', 'event_type', 'activity_id',
                'activity_attempt_id', 'timer_id', 'context']) !== []
            || ! is_array($value['context'] ?? null)
            || array_diff(array_keys($value['context']), ['schema', 'version', 'carrier']) !== []
            || ! is_array($value['context']['carrier'] ?? null)
            || array_diff(array_keys($value['context']['carrier']), ['traceparent', 'tracestate']) !== []) {
            return null;
        }
        $identityKeys = match ($value['event_type']) {
            'ActivityCompleted', 'ActivityFailed', 'ActivityTimedOut' => ['activity_id', 'activity_attempt_id'],
            'TimerFired' => ['timer_id'],
            default => [],
        };
        foreach (['activity_id', 'activity_attempt_id', 'timer_id'] as $key) {
            if (in_array($key, $identityKeys, true) ? ! self::identifier($value[$key] ?? null) : array_key_exists(
                $key,
                $value
            )) {
                return null;
            }
        }
        $context = TraceCarrier::normalize($value['context']);

        return $context === null ? null : array_replace($value, [
            'context' => $context,
        ]);
    }

    public static function matchesEvent(array $entry, WorkflowHistoryEvent $event): bool
    {
        if (($entry['event_id'] ?? null) !== $event->id || ($entry['sequence'] ?? null) !== (int) $event->sequence
            || ($entry['event_type'] ?? null) !== $event->event_type->value) {
            return false;
        }
        foreach (self::identity($event) as $key => $value) {
            if (($entry[$key] ?? null) !== $value) {
                return false;
            }
        }

        return true;
    }

    public static function forRun(WorkflowRun $run, WorkflowTask $claim): array
    {
        try {
            if (! array_key_exists('observability_context', $claim->getRawOriginal())) {
                return [];
            }
            $events = ConfiguredV2Models::query('history_event_model', WorkflowHistoryEvent::class)
                ->where('workflow_run_id', $run->id)
                ->whereIn('event_type', self::EVENTS)
                ->whereNotNull('workflow_task_id')
                ->orderByDesc('sequence')
                ->limit(32)
                ->get(['id', 'workflow_run_id', 'workflow_task_id', 'sequence', 'event_type']);
            if ($events->isEmpty()) {
                return [];
            }
            $tasks = ConfiguredV2Models::query('task_model', WorkflowTask::class)
                ->whereKey($events->pluck('workflow_task_id')->unique())
                ->where('workflow_run_id', $run->id)
                ->where('namespace', $run->namespace)
                ->get(['id', 'workflow_run_id', 'namespace', 'observability_context'])->keyBy('id');
            $result = [];
            foreach ($events as $event) {
                $task = $tasks->get($event->workflow_task_id);
                if (! $task instanceof WorkflowTask) {
                    continue;
                }
                $envelope = self::fromTask($task);
                foreach ($envelope['outcomes'] ?? [] as $entry) {
                    if ($entry['event_id'] === $event->id && $entry['sequence'] === (int) $event->sequence
                        && $entry['event_type'] === $event->event_type->value) {
                        $result[$event->id] = $entry + [
                            'task_id' => $task->id,
                        ];
                    }
                }
            }

            return $result;
        } catch (Throwable) {
            // A missing legacy column or unavailable observation cannot replace workflow execution.
            return [];
        }
    }

    private static function identifier(mixed $value): bool
    {
        return is_string($value) && $value !== '' && strlen($value) <= 512 && ! preg_match('/[\x00-\x1f\x7f]/', $value);
    }
}
