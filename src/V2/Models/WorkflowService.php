<?php

declare(strict_types=1);

namespace Workflow\V2\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Workflow\V2\Support\ConfiguredV2Models;

class WorkflowService extends Model
{
    use HasUlids;

    public $incrementing = false;

    protected $table = 'workflow_services';

    protected $guarded = [];

    protected $keyType = 'string';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $casts = [
        'metadata' => 'array',
    ];

    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(
            ConfiguredV2Models::resolve('service_endpoint_model', WorkflowServiceEndpoint::class),
            'workflow_service_endpoint_id',
        );
    }

    public function operations(): HasMany
    {
        return $this->hasMany(
            ConfiguredV2Models::resolve('service_operation_model', WorkflowServiceOperation::class),
            'workflow_service_id',
        )
            ->oldest('created_at')
            ->oldest('id');
    }

    public function serviceCalls(): HasMany
    {
        return $this->hasMany(
            ConfiguredV2Models::resolve('service_call_model', WorkflowServiceCall::class),
            'workflow_service_id',
        )
            ->oldest('accepted_at')
            ->oldest('created_at')
            ->oldest('id');
    }
}
