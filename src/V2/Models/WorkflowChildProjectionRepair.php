<?php

declare(strict_types=1);

namespace Workflow\V2\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Workflow\Traits\ResolvesStorageConnection;
use Workflow\V2\Support\ConfiguredV2Models;

class WorkflowChildProjectionRepair extends Model
{
    use ResolvesStorageConnection;

    public $incrementing = false;

    protected $table = 'workflow_child_projection_repairs';

    protected $primaryKey = 'workflow_history_event_id';

    protected $guarded = [];

    protected $keyType = 'string';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $casts = [
        'history_sequence' => 'integer',
        'failed_child_counted_at' => 'immutable_datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(
            ConfiguredV2Models::resolve('run_model', WorkflowRun::class),
            'workflow_run_id',
            'id',
        );
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(
            ConfiguredV2Models::resolve('task_model', WorkflowTask::class),
            'workflow_task_id',
            'id',
        );
    }

    public function historyEvent(): BelongsTo
    {
        return $this->belongsTo(
            ConfiguredV2Models::resolve('history_event_model', WorkflowHistoryEvent::class),
            'workflow_history_event_id',
            'id',
        );
    }
}
