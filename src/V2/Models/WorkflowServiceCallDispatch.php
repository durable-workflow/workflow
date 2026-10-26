<?php

declare(strict_types=1);

namespace Workflow\V2\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Workflow\Traits\ResolvesStorageConnection;
use Workflow\V2\Support\ConfiguredV2Models;
use Workflow\V2\Support\UtcScheduleTimestamp;

class WorkflowServiceCallDispatch extends Model
{
    use ResolvesStorageConnection;

    public $incrementing = false;

    protected $table = 'workflow_service_call_dispatches';

    protected $primaryKey = 'service_call_id';

    protected $guarded = [];

    protected $keyType = 'string';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $casts = [
        'dispatch_options' => 'array',
        'attempt' => 'integer',
        'max_attempts' => 'integer',
        'claim_generation' => 'integer',
        'available_at' => UtcScheduleTimestamp::class,
        'claimed_at' => 'datetime',
        'claim_expires_at' => UtcScheduleTimestamp::class,
        'dispatch_attempted_at' => 'datetime',
        'repair_available_at' => UtcScheduleTimestamp::class,
        'completed_at' => 'datetime',
    ];

    public function call(): BelongsTo
    {
        return $this->belongsTo(
            ConfiguredV2Models::resolve('service_call_model', WorkflowServiceCall::class),
            'service_call_id',
        );
    }
}
