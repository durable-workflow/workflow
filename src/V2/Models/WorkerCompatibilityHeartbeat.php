<?php

declare(strict_types=1);

namespace Workflow\V2\Models;

use Illuminate\Database\Eloquent\Model;

class WorkerCompatibilityHeartbeat extends Model
{
    protected $table = 'workflow_worker_compatibility_heartbeats';

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $casts = [
        'supported' => 'array',
        'recorded_at' => 'datetime',
        'expires_at' => 'datetime',
    ];
}
