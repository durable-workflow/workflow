<?php

declare(strict_types=1);

namespace Workflow\V2\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkflowInstance extends Model
{
    use HasUlids;

    public $incrementing = false;

    protected $table = 'workflow_instances';

    protected $guarded = [];

    protected $keyType = 'string';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $casts = [
        'visibility_labels' => 'array',
    ];

    public function currentRun(): BelongsTo
    {
        return $this->belongsTo(WorkflowRun::class, 'current_run_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(WorkflowRun::class);
    }

    public function commands(): HasMany
    {
        return $this->hasMany(WorkflowCommand::class)
            ->oldest('created_at');
    }

    public function updates(): HasMany
    {
        return $this->hasMany(WorkflowUpdate::class)
            ->orderBy('command_sequence')
            ->oldest('accepted_at')
            ->oldest('created_at')
            ->oldest('id');
    }
}
