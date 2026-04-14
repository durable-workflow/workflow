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
        $action = is_array($schedule->action) ? $schedule->action : [];
        $workflowClass = $action['workflow_class'] ?? null;

        if (! is_string($workflowClass) || $workflowClass === '') {
            throw new LogicException(sprintf(
                'Schedule [%s] action missing workflow_class.',
                $schedule->schedule_id,
            ));
        }

        $suffix = $occurrenceTime !== null
            ? sprintf('backfill:%s', $occurrenceTime->getTimestamp())
            : (string) now()->getTimestampMs();

        $stub = WorkflowStub::make(
            $workflowClass,
            sprintf('schedule:%s:%s', $schedule->schedule_id, $suffix),
        );

        $startOptions = new StartOptions(
            labels: is_array($schedule->visibility_labels) ? $schedule->visibility_labels : [],
            memo: is_array($schedule->memo) ? $schedule->memo : [],
            searchAttributes: is_array($schedule->search_attributes) ? $schedule->search_attributes : [],
        );

        $arguments = array_values(is_array($action['input'] ?? null) ? $action['input'] : []);

        $scheduleConnection = $schedule->connection;
        $scheduleQueue = $schedule->queue;

        if ($scheduleConnection !== null || $scheduleQueue !== null) {
            $arguments[] = new WorkflowOptions($scheduleConnection, $scheduleQueue);
        }

        $arguments[] = $startOptions;

        $result = $stub->start(...$arguments);

        return new ScheduleStartResult(
            instanceId: $result->instanceId(),
            runId: $result->runId(),
        );
    }
}
