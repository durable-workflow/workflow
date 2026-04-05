<?php

declare(strict_types=1);

namespace Workflow\V2\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowRunSummary extends Model
{
    public $incrementing = false;

    protected $table = 'workflow_run_summaries';

    protected $guarded = [];

    protected $keyType = 'string';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $casts = [
        'is_current_run' => 'bool',
        'started_at' => 'datetime',
        'closed_at' => 'datetime',
        'wait_started_at' => 'datetime',
        'wait_deadline_at' => 'datetime',
        'next_task_at' => 'datetime',
        'next_task_lease_expires_at' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(WorkflowRun::class, 'id', 'id');
    }
}
