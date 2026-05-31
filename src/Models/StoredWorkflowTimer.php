<?php

declare(strict_types=1);

namespace Workflow\Models;

use Illuminate\Database\Eloquent\Model;
use Workflow\Traits\ResolvesStorageConnection;

class StoredWorkflowTimer extends Model
{
    use ResolvesStorageConnection;

    public const UPDATED_AT = null;

    /**
     * @var string
     */
    protected $table = 'workflow_timers';

    /**
     * @var mixed[]
     */
    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    /**
     * @var array<string, class-string<\datetime>>
     */
    protected $casts = [
        'stop_at' => 'datetime:Y-m-d H:i:s.u',
    ];
}
