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
        'timer_model' => Workflow\V2\Models\WorkflowTimer::class,
        'failure_model' => Workflow\V2\Models\WorkflowFailure::class,
        'run_summary_model' => Workflow\V2\Models\WorkflowRunSummary::class,
        'types' => [
            'workflows' => [
                // 'billing.invoice-sync' => App\Workflows\InvoiceSyncWorkflow::class,
            ],
            'activities' => [
                // 'payments.capture' => App\Activities\CapturePaymentActivity::class,
            ],
        ],
        'compatibility' => [
            'current' => env('WORKFLOW_V2_CURRENT_COMPATIBILITY'),
            'supported' => env('WORKFLOW_V2_SUPPORTED_COMPATIBILITIES'),
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
