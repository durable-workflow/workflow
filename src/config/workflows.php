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
