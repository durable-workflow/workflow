<?php

declare(strict_types=1);

return [
    'workflows_folder' => 'Workflows',

    'stored_workflow_model' => Workflow\Models\StoredWorkflow::class,

    'stored_workflow_exception_model' => Workflow\Models\StoredWorkflowException::class,

    'stored_workflow_log_model' => Workflow\Models\StoredWorkflowLog::class,

    'stored_workflow_signal_model' => Workflow\Models\StoredWorkflowSignal::class,

    'stored_workflow_timer_model' => Workflow\Models\StoredWorkflowTimer::class,

    'workflow_relationships_table' => 'workflow_relationships',

    'v2' => [
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
        'compatibility' => [
            'current' => env('WORKFLOW_V2_CURRENT_COMPATIBILITY'),
            'supported' => env('WORKFLOW_V2_SUPPORTED_COMPATIBILITIES'),
            'namespace' => env('WORKFLOW_V2_COMPATIBILITY_NAMESPACE'),
            'heartbeat_ttl_seconds' => (int) env('WORKFLOW_V2_COMPATIBILITY_HEARTBEAT_TTL', 30),
        ],
        'history_budget' => [
            'continue_as_new_event_threshold' => (int) env('WORKFLOW_V2_CONTINUE_AS_NEW_EVENT_THRESHOLD', 10000),
            'continue_as_new_size_bytes_threshold' => (int) env(
                'WORKFLOW_V2_CONTINUE_AS_NEW_SIZE_BYTES_THRESHOLD',
                5242880
            ),
        ],
        'history_export' => [
            'redactor' => null,
            'signing_key' => env('WORKFLOW_V2_HISTORY_EXPORT_SIGNING_KEY'),
            'signing_key_id' => env('WORKFLOW_V2_HISTORY_EXPORT_SIGNING_KEY_ID'),
        ],
        'update_wait' => [
            'completion_timeout_seconds' => (int) env('WORKFLOW_V2_UPDATE_WAIT_COMPLETION_TIMEOUT_SECONDS', 10),
            'poll_interval_milliseconds' => (int) env('WORKFLOW_V2_UPDATE_WAIT_POLL_INTERVAL_MS', 50),
        ],
        'task_repair' => [
            'redispatch_after_seconds' => (int) env('WORKFLOW_V2_TASK_REPAIR_REDISPATCH_AFTER_SECONDS', 3),
            'loop_throttle_seconds' => (int) env('WORKFLOW_V2_TASK_REPAIR_LOOP_THROTTLE_SECONDS', 5),
            'scan_limit' => (int) env('WORKFLOW_V2_TASK_REPAIR_SCAN_LIMIT', 25),
            'failure_backoff_max_seconds' => (int) env('WORKFLOW_V2_TASK_REPAIR_FAILURE_BACKOFF_MAX_SECONDS', 60),
        ],
    ],

    'serializer' => Workflow\Serializers\Y::class,

    'prune_age' => '1 month',

    'webhooks_route' => env('WORKFLOW_WEBHOOKS_ROUTE', 'webhooks'),

    'webhook_auth' => [
        'method' => env('WORKFLOW_WEBHOOKS_AUTH_METHOD', 'none'),

        'signature' => [
            'header' => env('WORKFLOW_WEBHOOKS_SIGNATURE_HEADER', 'X-Signature'),
            'secret' => env('WORKFLOW_WEBHOOKS_SECRET'),
        ],

        'token' => [
            'header' => env('WORKFLOW_WEBHOOKS_TOKEN_HEADER', 'Authorization'),
            'token' => env('WORKFLOW_WEBHOOKS_TOKEN'),
        ],

        'custom' => [
            'class' => env('WORKFLOW_WEBHOOKS_CUSTOM_AUTH_CLASS', null),
        ],
    ],
];
