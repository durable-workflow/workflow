<?php

declare(strict_types=1);

namespace Workflow\V2\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Workflow\V2\Support\ConfiguredV2Models;

class WorkflowServiceCall extends Model
{
    use HasUlids;

    public $incrementing = false;

    protected $table = 'workflow_service_calls';

    protected $guarded = [];

    protected $keyType = 'string';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $casts = [
        'deadline_policy' => 'array',
        'idempotency_policy' => 'array',
        'cancellation_policy' => 'array',
        'retry_policy' => 'array',
        'boundary_policy' => 'array',
        'metadata' => 'array',
        'accepted_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(
            ConfiguredV2Models::resolve('service_endpoint_model', WorkflowServiceEndpoint::class),
            'workflow_service_endpoint_id',
        );
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(
            ConfiguredV2Models::resolve('service_model', WorkflowService::class),
            'workflow_service_id',
        );
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(
            ConfiguredV2Models::resolve('service_operation_model', WorkflowServiceOperation::class),
            'workflow_service_operation_id',
        );
    }

    public function callerInstance(): BelongsTo
    {
        return $this->belongsTo(
            ConfiguredV2Models::resolve('instance_model', WorkflowInstance::class),
            'caller_workflow_instance_id',
        );
    }

    public function callerRun(): BelongsTo
    {
        return $this->belongsTo(
            ConfiguredV2Models::resolve('run_model', WorkflowRun::class),
            'caller_workflow_run_id',
        );
    }

    public function linkedInstance(): BelongsTo
    {
        return $this->belongsTo(
            ConfiguredV2Models::resolve('instance_model', WorkflowInstance::class),
            'linked_workflow_instance_id',
        );
    }

    public function linkedRun(): BelongsTo
    {
        return $this->belongsTo(
            ConfiguredV2Models::resolve('run_model', WorkflowRun::class),
            'linked_workflow_run_id',
        );
    }

    public function linkedUpdate(): BelongsTo
    {
        return $this->belongsTo(
            ConfiguredV2Models::resolve('update_model', WorkflowUpdate::class),
            'linked_workflow_update_id',
        );
    }
}
