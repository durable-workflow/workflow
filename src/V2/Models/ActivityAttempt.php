<?php

declare(strict_types=1);

namespace Workflow\V2\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Workflow\V2\Enums\ActivityAttemptStatus;
use Workflow\V2\Support\ConfiguredV2Models;

class ActivityAttempt extends Model
{
    use HasUlids;

    public $incrementing = false;

    protected $table = 'activity_attempts';

    protected $guarded = [];

    protected $keyType = 'string';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $casts = [
        'status' => ActivityAttemptStatus::class,
        'attempt_number' => 'integer',
        'started_at' => 'datetime',
        'last_heartbeat_at' => 'datetime',
        'lease_expires_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function execution(): BelongsTo
    {
        return $this->belongsTo(
            ConfiguredV2Models::resolve('activity_execution_model', ActivityExecution::class),
            'activity_execution_id',
        );
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(ConfiguredV2Models::resolve('run_model', WorkflowRun::class), 'workflow_run_id');
    }
}
