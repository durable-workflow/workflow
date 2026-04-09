<?php

declare(strict_types=1);

namespace Workflow\V2\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Workflow\V2\Enums\RunStatus;

class WorkflowRunSummary extends Model
{
    public $incrementing = false;

    protected $table = 'workflow_run_summaries';

    protected $guarded = [];

    protected $keyType = 'string';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $appends = ['instance_id', 'selected_run_id', 'run_id', 'exceptions_count', 'is_terminal'];

    protected $casts = [
        'is_current_run' => 'bool',
        'history_event_count' => 'integer',
        'history_size_bytes' => 'integer',
        'continue_as_new_recommended' => 'bool',
        'started_at' => 'datetime',
        'sort_timestamp' => 'datetime',
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

    public function getInstanceIdAttribute(): string
    {
        return $this->workflow_instance_id;
    }

    public function getSelectedRunIdAttribute(): string
    {
        return $this->id;
    }

    public function getRunIdAttribute(): string
    {
        return $this->id;
    }

    public function getExceptionsCountAttribute(): int
    {
        return (int) $this->exception_count;
    }

    public function getIsTerminalAttribute(): bool
    {
        return RunStatus::from($this->status)->isTerminal();
    }
}
