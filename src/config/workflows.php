<?php

declare(strict_types=1);

use Workflow\Support\Env;

/*
|--------------------------------------------------------------------------
| DW_* environment variable resolution
|--------------------------------------------------------------------------
|
| Operator-facing env vars read by this package follow the DW_* contract
| first, falling back to the legacy WORKFLOW_* / WORKFLOW_V2_* names so
| existing deployments keep working during the deprecation window. The
| DW_* names are documented in the server image's
| `config/dw-contract.php`. Prefer the DW_* name when wiring new
| environments.
*/

return [
    'workflows_folder' => 'Workflows',

    'stored_workflow_model' => Workflow\Models\StoredWorkflow::class,

    'stored_workflow_exception_model' => Workflow\Models\StoredWorkflowException::class,

    'stored_workflow_log_model' => Workflow\Models\StoredWorkflowLog::class,

    'stored_workflow_signal_model' => Workflow\Models\StoredWorkflowSignal::class,

    'stored_workflow_timer_model' => Workflow\Models\StoredWorkflowTimer::class,

    'workflow_relationships_table' => 'workflow_relationships',

    'v2' => [
        // Optional. When null, workflow instances are not scoped to a namespace and
        // are visible to every consumer. Set to a string (e.g. "production") to
        // isolate multi-namespace deployments.
        'namespace' => Env::dw('DW_V2_NAMESPACE', 'WORKFLOW_V2_NAMESPACE', null),

        'instance_model' => Workflow\V2\Models\WorkflowInstance::class,
        'run_model' => Workflow\V2\Models\WorkflowRun::class,
        'history_event_model' => Workflow\V2\Models\WorkflowHistoryEvent::class,
        'task_model' => Workflow\V2\Models\WorkflowTask::class,
        'command_model' => Workflow\V2\Models\WorkflowCommand::class,
        'link_model' => Workflow\V2\Models\WorkflowLink::class,
        'activity_execution_model' => Workflow\V2\Models\ActivityExecution::class,
        'activity_attempt_model' => Workflow\V2\Models\ActivityAttempt::class,
        'timer_model' => Workflow\V2\Models\WorkflowTimer::class,
        'failure_model' => Workflow\V2\Models\WorkflowFailure::class,
        'run_summary_model' => Workflow\V2\Models\WorkflowRunSummary::class,
        'run_wait_model' => Workflow\V2\Models\WorkflowRunWait::class,
        'run_timeline_entry_model' => Workflow\V2\Models\WorkflowTimelineEntry::class,
        'run_timer_entry_model' => Workflow\V2\Models\WorkflowRunTimerEntry::class,
        'run_lineage_entry_model' => Workflow\V2\Models\WorkflowRunLineageEntry::class,
        'schedule_model' => Workflow\V2\Models\WorkflowSchedule::class,
        'schedule_history_event_model' => Workflow\V2\Models\WorkflowScheduleHistoryEvent::class,
        'types' => [
            'workflows' => [
                // 'billing.invoice-sync' => App\Workflows\InvoiceSyncWorkflow::class,
            ],
            'activities' => [
                // 'payments.capture' => App\Activities\CapturePaymentActivity::class,
            ],
            'exceptions' => [
                // 'billing.invoice-declined' => App\Exceptions\InvoiceDeclined::class,
            ],
            'exception_class_aliases' => [
                // App\Exceptions\LegacyInvoiceDeclined::class => App\Exceptions\InvoiceDeclined::class,
            ],
        ],
        // Worker-compatibility markers let you pin workflow runs to a specific
        // worker build and block incompatible workers from claiming tasks. All
        // three keys default to null ("no marker required"), which is the right
        // value for single-fleet deployments.
        //
        // Set DW_V2_CURRENT_COMPATIBILITY to the marker this worker advertises
        // (e.g. "build-2026-04-17"). Set DW_V2_SUPPORTED_COMPATIBILITIES to a
        // comma-separated list or "*" to accept any marker. Set
        // DW_V2_COMPATIBILITY_NAMESPACE when multiple apps share one workflow
        // database but maintain independent compatibility fleets.
        'compatibility' => [
            'current' => Env::dw('DW_V2_CURRENT_COMPATIBILITY', 'WORKFLOW_V2_CURRENT_COMPATIBILITY', null),
            'supported' => Env::dw('DW_V2_SUPPORTED_COMPATIBILITIES', 'WORKFLOW_V2_SUPPORTED_COMPATIBILITIES', null),
            'namespace' => Env::dw('DW_V2_COMPATIBILITY_NAMESPACE', 'WORKFLOW_V2_COMPATIBILITY_NAMESPACE', null),
            'heartbeat_ttl_seconds' => (int) Env::dw(
                'DW_V2_COMPATIBILITY_HEARTBEAT_TTL',
                'WORKFLOW_V2_COMPATIBILITY_HEARTBEAT_TTL',
                30
            ),
            // When true (the default), in-flight runs resolve their workflow
            // class from the `workflow_definition_fingerprint` recorded in
            // their WorkflowStarted history event instead of the live
            // `workflow_runs.workflow_class` column. This keeps a run pinned
            // to the definition snapshot it started under even after a deploy
            // swaps the class pointer for the same workflow_type.
            //
            // Set to false only if your deploy intentionally hot-swaps
            // workflow classes mid-run and wants the replacement class to
            // execute against the existing history from the next task
            // forward.
            'pin_to_recorded_fingerprint' => (bool) Env::dw(
                'DW_V2_PIN_TO_RECORDED_FINGERPRINT',
                'WORKFLOW_V2_PIN_TO_RECORDED_FINGERPRINT',
                true
            ),
        ],
        'history_budget' => [
            'continue_as_new_event_threshold' => (int) Env::dw(
                'DW_V2_CONTINUE_AS_NEW_EVENT_THRESHOLD',
                'WORKFLOW_V2_CONTINUE_AS_NEW_EVENT_THRESHOLD',
                10000
            ),
            'continue_as_new_size_bytes_threshold' => (int) Env::dw(
                'DW_V2_CONTINUE_AS_NEW_SIZE_BYTES_THRESHOLD',
                'WORKFLOW_V2_CONTINUE_AS_NEW_SIZE_BYTES_THRESHOLD',
                5242880
            ),
        ],
        // History export signing is opt-in. When signing_key is null, exports
        // are emitted unsigned. Provide a key (and optional key id for rotation)
        // only if you need the export to be authenticated at the receiver.
        'history_export' => [
            'redactor' => null,
            'signing_key' => Env::dw(
                'DW_V2_HISTORY_EXPORT_SIGNING_KEY',
                'WORKFLOW_V2_HISTORY_EXPORT_SIGNING_KEY',
                null
            ),
            'signing_key_id' => Env::dw(
                'DW_V2_HISTORY_EXPORT_SIGNING_KEY_ID',
                'WORKFLOW_V2_HISTORY_EXPORT_SIGNING_KEY_ID',
                null
            ),
        ],
        'update_wait' => [
            'completion_timeout_seconds' => (int) Env::dw(
                'DW_V2_UPDATE_WAIT_COMPLETION_TIMEOUT_SECONDS',
                'WORKFLOW_V2_UPDATE_WAIT_COMPLETION_TIMEOUT_SECONDS',
                10
            ),
            'poll_interval_milliseconds' => (int) Env::dw(
                'DW_V2_UPDATE_WAIT_POLL_INTERVAL_MS',
                'WORKFLOW_V2_UPDATE_WAIT_POLL_INTERVAL_MS',
                50
            ),
        ],
        'guardrails' => [
            'boot' => env('DW_V2_GUARDRAILS_BOOT', 'warn'),
        ],
        'structural_limits' => [
            'pending_activity_count' => (int) Env::dw(
                'DW_V2_LIMIT_PENDING_ACTIVITIES',
                'WORKFLOW_V2_LIMIT_PENDING_ACTIVITIES',
                2000
            ),
            'pending_child_count' => (int) Env::dw(
                'DW_V2_LIMIT_PENDING_CHILDREN',
                'WORKFLOW_V2_LIMIT_PENDING_CHILDREN',
                1000
            ),
            'pending_timer_count' => (int) Env::dw(
                'DW_V2_LIMIT_PENDING_TIMERS',
                'WORKFLOW_V2_LIMIT_PENDING_TIMERS',
                2000
            ),
            'pending_signal_count' => (int) Env::dw(
                'DW_V2_LIMIT_PENDING_SIGNALS',
                'WORKFLOW_V2_LIMIT_PENDING_SIGNALS',
                5000
            ),
            'pending_update_count' => (int) Env::dw(
                'DW_V2_LIMIT_PENDING_UPDATES',
                'WORKFLOW_V2_LIMIT_PENDING_UPDATES',
                500
            ),
            'command_batch_size' => (int) Env::dw(
                'DW_V2_LIMIT_COMMAND_BATCH_SIZE',
                'WORKFLOW_V2_LIMIT_COMMAND_BATCH_SIZE',
                1000
            ),
            'payload_size_bytes' => (int) Env::dw(
                'DW_V2_LIMIT_PAYLOAD_SIZE_BYTES',
                'WORKFLOW_V2_LIMIT_PAYLOAD_SIZE_BYTES',
                2097152
            ),
            'memo_size_bytes' => (int) Env::dw(
                'DW_V2_LIMIT_MEMO_SIZE_BYTES',
                'WORKFLOW_V2_LIMIT_MEMO_SIZE_BYTES',
                262144
            ),
            'search_attribute_size_bytes' => (int) Env::dw(
                'DW_V2_LIMIT_SEARCH_ATTRIBUTE_SIZE_BYTES',
                'WORKFLOW_V2_LIMIT_SEARCH_ATTRIBUTE_SIZE_BYTES',
                40960
            ),
            'history_transaction_size' => (int) Env::dw(
                'DW_V2_LIMIT_HISTORY_TRANSACTION_SIZE',
                'WORKFLOW_V2_LIMIT_HISTORY_TRANSACTION_SIZE',
                5000
            ),
            'warning_threshold_percent' => (int) Env::dw(
                'DW_V2_LIMIT_WARNING_THRESHOLD_PERCENT',
                'WORKFLOW_V2_LIMIT_WARNING_THRESHOLD_PERCENT',
                80
            ),
        ],
        'task_dispatch_mode' => Env::dw('DW_V2_TASK_DISPATCH_MODE', 'WORKFLOW_V2_TASK_DISPATCH_MODE', 'queue'),

        // Matching role configuration. The matching role is defined by
        // docs/architecture/task-matching.md. By default every Laravel queue
        // worker also runs the repair / broad-poll pass on every Looping
        // event, which is how the in-worker library shape of the matching
        // role runs today. Setting queue_wake_enabled to false disables the
        // on-poll wake so this node never runs the broad repair sweep,
        // leaving the sweep to a dedicated process invoking
        // `php artisan workflow:v2:repair-pass`. This is how an operator
        // opts a fleet into the "dedicated matching role shape" without
        // otherwise changing task execution.
        'matching_role' => [
            'queue_wake_enabled' => (bool) Env::dw(
                'DW_V2_MATCHING_ROLE_QUEUE_WAKE',
                'WORKFLOW_V2_MATCHING_ROLE_QUEUE_WAKE',
                true
            ),
        ],

        'task_repair' => [
            'redispatch_after_seconds' => (int) Env::dw(
                'DW_V2_TASK_REPAIR_REDISPATCH_AFTER_SECONDS',
                'WORKFLOW_V2_TASK_REPAIR_REDISPATCH_AFTER_SECONDS',
                3
            ),
            'loop_throttle_seconds' => (int) Env::dw(
                'DW_V2_TASK_REPAIR_LOOP_THROTTLE_SECONDS',
                'WORKFLOW_V2_TASK_REPAIR_LOOP_THROTTLE_SECONDS',
                5
            ),
            'scan_limit' => (int) Env::dw('DW_V2_TASK_REPAIR_SCAN_LIMIT', 'WORKFLOW_V2_TASK_REPAIR_SCAN_LIMIT', 25),
            'failure_backoff_max_seconds' => (int) Env::dw(
                'DW_V2_TASK_REPAIR_FAILURE_BACKOFF_MAX_SECONDS',
                'WORKFLOW_V2_TASK_REPAIR_FAILURE_BACKOFF_MAX_SECONDS',
                60
            ),
        ],

        'long_poll' => [
            // Whether this deployment has multiple server nodes.
            // When true, validates that cache backend supports cross-node coordination.
            'multi_node' => (bool) Env::dw('DW_V2_MULTI_NODE', 'WORKFLOW_V2_MULTI_NODE', false),

            // Whether to validate cache backend on boot.
            // Set to false to disable boot-time validation (not recommended for production).
            'validate_cache_backend' => (bool) Env::dw(
                'DW_V2_VALIDATE_CACHE_BACKEND',
                'WORKFLOW_V2_VALIDATE_CACHE_BACKEND',
                true
            ),

            // How to handle validation failures: '\''fail'\'' (throw exception), '\''warn'\'' (log warning), '\''silent'\'' (no action)
            'validation_mode' => Env::dw('DW_V2_CACHE_VALIDATION_MODE', 'WORKFLOW_V2_CACHE_VALIDATION_MODE', 'warn'),
        ],
    ],

    // Payload codec diagnostic input. Final v2 always uses "avro" as the
    // default codec for new typed binary payloads with cross-language type
    // fidelity (int stays int, float stays float).
    //
    // DW_SERIALIZER is still read so `workflow:v2:doctor` can flag stale
    // v1/custom settings during migration without rebuilding the image or
    // mounting a config override file, but it cannot change the new-run v2
    // default away from Avro.
    //
    // Legacy PHP-only codecs ("workflow-serializer-y",
    // "workflow-serializer-base64") remain supported for reading v1 history.
    // Setting this to a legacy codec, JSON, or a removed custom serializer will
    // be flagged by `workflow:v2:doctor`; JSON is not a registered v2 codec.
    'serializer' => Env::dw('DW_SERIALIZER', 'WORKFLOW_SERIALIZER', 'avro'),

    'prune_age' => '1 month',

    'webhooks_route' => Env::dw('DW_WEBHOOKS_ROUTE', 'WORKFLOW_WEBHOOKS_ROUTE', 'webhooks'),

    'webhook_auth' => [
        'method' => Env::dw('DW_WEBHOOKS_AUTH_METHOD', 'WORKFLOW_WEBHOOKS_AUTH_METHOD', 'none'),

        'signature' => [
            'header' => Env::dw('DW_WEBHOOKS_SIGNATURE_HEADER', 'WORKFLOW_WEBHOOKS_SIGNATURE_HEADER', 'X-Signature'),
            'secret' => Env::dw('DW_WEBHOOKS_SECRET', 'WORKFLOW_WEBHOOKS_SECRET', null),
        ],

        'token' => [
            'header' => Env::dw('DW_WEBHOOKS_TOKEN_HEADER', 'WORKFLOW_WEBHOOKS_TOKEN_HEADER', 'Authorization'),
            'token' => Env::dw('DW_WEBHOOKS_TOKEN', 'WORKFLOW_WEBHOOKS_TOKEN', null),
        ],

        'custom' => [
            'class' => Env::dw('DW_WEBHOOKS_CUSTOM_AUTH_CLASS', 'WORKFLOW_WEBHOOKS_CUSTOM_AUTH_CLASS', null),
        ],
    ],
];
