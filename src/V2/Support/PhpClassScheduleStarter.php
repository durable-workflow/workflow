<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use DateTimeInterface;
use LogicException;
use Workflow\V2\Contracts\ScheduleWorkflowStarter;
use Workflow\V2\Models\WorkflowSchedule;
use Workflow\V2\StartOptions;
use Workflow\V2\WorkflowStub;
use Workflow\WorkflowOptions;

/**
 * Default starter: resolves the workflow class from `action.workflow_class`
 * and starts it in-process via {@see WorkflowStub::make()}.
 */
final class PhpClassScheduleStarter implements ScheduleWorkflowStarter
{
    public function start(
        WorkflowSchedule $schedule,
        ?DateTimeInterface $occurrenceTime,
        string $outcome,
        ?string $effectiveOverlapPolicy = null,
    ): ScheduleStartResult {
        $action = WorkflowSchedule::normalizeActionTimeouts(is_array($schedule->action) ? $schedule->action : []);
        $workflowClass = $action['workflow_class'] ?? null;

        if (! is_string($workflowClass) || $workflowClass === '') {
            throw new LogicException(sprintf(
                'Schedule [%s] action missing workflow_class.',
                $schedule->schedule_id,
            ));
        }

        $suffix = $occurrenceTime !== null
            ? sprintf('backfill:%s', $occurrenceTime->getTimestamp())
            : (string) now()
                ->getTimestampMs();

        $stub = WorkflowStub::make($workflowClass, self::instanceId($schedule, $suffix), $schedule->namespace);

        $startOptions = new StartOptions(
            labels: is_array($schedule->visibility_labels) ? $schedule->visibility_labels : [],
            memo: is_array($schedule->memo) ? $schedule->memo : [],
            searchAttributes: is_array($schedule->search_attributes) ? $schedule->search_attributes : [],
            executionTimeoutSeconds: $action['execution_timeout_seconds'] ?? null,
            runTimeoutSeconds: $action['run_timeout_seconds'] ?? null,
        );

        $arguments = array_values(is_array($action['input'] ?? null) ? $action['input'] : []);

        $scheduleConnection = $schedule->connection;
        $scheduleQueue = $schedule->queue;

        if ($scheduleConnection !== null || $scheduleQueue !== null) {
            $arguments[] = new WorkflowOptions($scheduleConnection, $scheduleQueue);
        }

        $arguments[] = $startOptions;

        $result = $stub->start(...$arguments);

        return new ScheduleStartResult(instanceId: $result->instanceId(), runId: $result->runId());
    }

    /**
     * Workflow instance IDs are global, but schedule IDs are unique only per
     * namespace (#874). Every new scheduled start uses
     *
     *   schedule.ns:{namespace sha256, 32 hex}:{byte length}:{schedule_id}:{suffix}
     *
     * independent of the configured default namespace, so changing that
     * default cannot make two namespaces share an ID. Instances started under
     * the original `schedule:{schedule_id}:{suffix}` form stay readable; the
     * `schedule.` prefix can never equal one of them. The length prefix makes
     * the schedule ID boundary unambiguous. A schedule ID that would exceed
     * the instance key bound or use characters outside the instance ID
     * alphabet is replaced by `h:{sha256}`; `h` cannot be a length.
     */
    public static function instanceId(WorkflowSchedule $schedule, string $suffix): string
    {
        $namespace = is_string($schedule->namespace) ? $schedule->namespace : '';
        $scheduleId = (string) $schedule->schedule_id;
        $prefix = sprintf('schedule.ns:%s:', substr(hash('sha256', $namespace), 0, 32));
        $instanceId = sprintf('%s%d:%s:%s', $prefix, strlen($scheduleId), $scheduleId, $suffix);

        if (! WorkflowInstanceId::isValid($instanceId)) {
            $instanceId = sprintf('%sh:%s:%s', $prefix, hash('sha256', $scheduleId), $suffix);
        }

        return $instanceId;
    }
}
