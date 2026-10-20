<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;

/** @internal One active SDK scope. It grants no task or history authority. */
final class TaskOutcomeTraceState
{
    private array $local = [];

    private array $entries = [];

    private ?string $connection = null;

    public function __construct(
        private readonly string $kind,
        private readonly array $fields,
        private readonly array $context
    ) {
    }

    public function rememberLocal(array $fields, array $context): void
    {
        foreach (['workflow_run_id', 'namespace', 'task_id'] as $key) {
            if (! isset($fields[$key]) || $fields[$key] !== ($this->fields[$key] ?? null)) {
                return;
            }
        }
        $id = $fields['activity_attempt_id'] ?? null;
        if (is_string($id) && count($this->local) < 32) {
            $this->local[$id] ??= [
                'fields' => $fields,
                'context' => $context,
            ];
        }
    }

    public function observe(WorkflowRun $run, WorkflowTask $task, WorkflowHistoryEvent $event): void
    {
        if (! $this->owns($task) || $event->workflow_task_id !== $task->id || $event->workflow_run_id !== $run->id
            || $run->id !== $task->workflow_run_id || $run->namespace !== $task->namespace
            || ! in_array($event->event_type->value, TaskOutcomeCarrier::EVENTS, true)) {
            return;
        }
        $identity = TaskOutcomeCarrier::identity($event);
        $source = $this->source($event, $identity);
        if ($source === null) {
            return;
        }
        foreach ($identity as $key => $value) {
            if ($value === null || ($source['fields'][$key] ?? null) !== $value) {
                return;
            }
        }
        $entry = TaskOutcomeCarrier::entry([
            'event_id' => $event->id,
            'sequence' => (int) $event->sequence,
            'event_type' => $event->event_type->value,
            ...$identity,
            'context' => $source['context'],
        ]);
        if ($entry !== null && count($this->entries) < 32) {
            $this->connection = $task->getConnection()
                ->getName();
            $this->entries[$event->id] ??= $entry;
        }
    }

    public function apply(WorkflowTask $task): void
    {
        if ($this->owns($task)) {
            TaskOutcomeCarrier::append($task, $this->entries);
        }
    }

    public function discard(string $connection): void
    {
        if ($this->connection === $connection) {
            $this->entries = [];
        }
    }

    private function owns(WorkflowTask $task): bool
    {
        return ($this->fields['task_id'] ?? null) === $task->id
            && ($this->fields['workflow_run_id'] ?? null) === $task->workflow_run_id
            && ($this->fields['namespace'] ?? null) === $task->namespace;
    }

    private function source(WorkflowHistoryEvent $event, array $identity): ?array
    {
        if (isset($identity['activity_attempt_id'])) {
            if ($this->kind === 'durable.activity.attempt') {
                return [
                    'fields' => $this->fields,
                    'context' => $this->context,
                ];
            }
            return $this->local[$identity['activity_attempt_id']] ?? null;
        }
        if (($event->event_type->value === 'TimerFired' && $this->kind === 'durable.timer.task')
            || (in_array($event->event_type->value, ['WorkflowCompleted', 'WorkflowFailed'], true)
                && $this->kind === 'durable.workflow.task')) {
            return [
                'fields' => $this->fields,
                'context' => $this->context,
            ];
        }

        return null;
    }
}
