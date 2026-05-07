<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

/**
 * Published runtime contract for v2 local activities.
 */
final class LocalActivityContract
{
    public const SCHEMA = 'durable-workflow.v2.local-activity.contract';

    public const VERSION = 1;

    /**
     * @return array<string, mixed>
     */
    public static function manifest(): array
    {
        return [
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'api' => [
                'functions' => ['Workflow\\V2\\localActivity'],
                'workflow_facade' => [
                    'Workflow\\V2\\Workflow::localActivity',
                    'Workflow\\V2\\Workflow::executeLocalActivity',
                ],
                'options' => LocalActivityOptions::class,
            ],
            'execution' => [
                'mode' => LocalActivityRuntime::EXECUTION_MODE,
                'same_process' => true,
                'ordinary_activity_task_created' => false,
                'history_events' => [
                    'ActivityScheduled',
                    'ActivityStarted',
                    'ActivityHeartbeatRecorded',
                    'ActivityRetryScheduled',
                    'ActivityCompleted',
                    'ActivityFailed',
                    'ActivityTimedOut',
                    'ActivityCancelled',
                ],
                'history_marker' => [
                    'execution_mode' => LocalActivityRuntime::EXECUTION_MODE,
                    'local_activity' => true,
                ],
            ],
            'heartbeating' => [
                'lease' => 'workflow_task',
                'automatic_points' => ['before_attempt_start', 'after_activity_heartbeat'],
                'long_running_requirement' => 'activity_code_calls_heartbeat_before_the_workflow_task_lease_can_expire',
            ],
            'cancellation' => [
                'preemption' => 'cooperative',
                'observed_at' => ['heartbeat', 'timeout_enforcement', 'attempt_completion'],
                'terminal_event' => 'ActivityCancelled',
            ],
            'shutdown' => [
                'graceful' => 'worker_stops_claiming_new_workflow_tasks_and_allows_in_process_attempt_to_finish_or_heartbeat',
                'crash' => 'workflow_task_lease_expires_and_task_repair_reclaims_the_workflow_task',
                'cold_replay' => 'committed_terminal_event_is_replayed_or_started_attempt_without_terminal_event_schedules_local_retry',
            ],
            'routing' => [
                'admission' => 'activity_class_must_resolve_in_the_workflow_worker_process',
                'queue_bypassed' => true,
                'rejected_options' => ['connection', 'queue', 'worker_session', 'schedule_to_start_timeout'],
            ],
            'retry' => [
                'new_attempt_when' => [
                    'a local attempt starts',
                    'a retry workflow task replays after backoff',
                    'cold replay finds a previously started local attempt without a terminal event',
                ],
                'backoff' => 'durable_workflow_task_available_at',
                'cold_replay_reason' => LocalActivityRuntime::RETRY_REASON_COLD_REPLAY,
            ],
            'timeouts' => [
                'start_to_close_timeout' => 'one_local_attempt',
                'schedule_to_close_timeout' => 'entire_local_execution_including_retries',
                'heartbeat_timeout' => 'gap_between_recorded_local_activity_heartbeats',
                'enforcement' => 'cooperative_at_heartbeat_attempt_start_and_attempt_completion_boundaries',
            ],
            'visibility' => [
                'activity_execution_marker' => 'activity_options.execution_mode',
                'history_marker' => 'payload.execution_mode',
                'metrics_marker' => 'activities.local_*',
                'history_export' => 'same_activity_event_payloads_with_execution_mode_local',
            ],
        ];
    }
}
