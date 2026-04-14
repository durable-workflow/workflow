<?php

declare(strict_types=1);

namespace Workflow\V2\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Workflow\V2\Enums\ScheduleStatus;
use Workflow\V2\Support\ConfiguredV2Models;

class WorkflowSchedule extends Model
{
    use HasUlids;

    public $incrementing = false;

    protected $table = 'workflow_schedules';

    protected $guarded = [];

    protected $keyType = 'string';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $casts = [
        'status' => ScheduleStatus::class,
        'workflow_arguments' => 'array',
        'memo' => 'array',
        'search_attributes' => 'array',
        'visibility_labels' => 'array',
        'overlap_policy' => 'string',
        'last_triggered_at' => 'datetime',
        'next_run_at' => 'datetime',
        'paused_at' => 'datetime',
        'deleted_at' => 'datetime',
        'jitter_seconds' => 'integer',
        'max_runs' => 'integer',
        'total_runs' => 'integer',
        'remaining_actions' => 'integer',
    ];

    public function latestInstance(): BelongsTo
    {
        return $this->belongsTo(
            ConfiguredV2Models::resolve('instance_model', WorkflowInstance::class),
            'latest_workflow_instance_id',
        );
    }
}
