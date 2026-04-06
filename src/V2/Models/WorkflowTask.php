<?php

declare(strict_types=1);

namespace Workflow\V2\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;

class WorkflowTask extends Model
{
    use HasUlids;

    public $incrementing = false;

    protected $table = 'workflow_tasks';

    protected $guarded = [];

    protected $keyType = 'string';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $casts = [
        'task_type' => TaskType::class,
        'status' => TaskStatus::class,
        'payload' => 'array',
        'repair_count' => 'integer',
        'available_at' => 'datetime',
        'leased_at' => 'datetime',
        'lease_expires_at' => 'datetime',
        'last_dispatch_attempt_at' => 'datetime',
        'last_dispatched_at' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(WorkflowRun::class, 'workflow_run_id');
    }
}
