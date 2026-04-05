<?php

declare(strict_types=1);

namespace Workflow\V2\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Workflow\V2\Enums\TimerStatus;

class WorkflowTimer extends Model
{
    use HasUlids;

    public $incrementing = false;

    protected $table = 'workflow_run_timers';

    protected $guarded = [];

    protected $keyType = 'string';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $casts = [
        'status' => TimerStatus::class,
        'fire_at' => 'datetime',
        'fired_at' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(WorkflowRun::class, 'workflow_run_id');
    }
}
