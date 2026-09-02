<?php

declare(strict_types=1);

namespace Workflow\Models;

use Illuminate\Database\Eloquent\Model;
use Workflow\Traits\ResolvesStorageConnection;

class StoredWorkflowLog extends Model
{
    use ResolvesStorageConnection;

    public const UPDATED_AT = null;

    /**
     * @var string
     */
    protected $table = 'workflow_logs';

    /**
     * @var mixed[]
     */
    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    /**
     * @var array<string, class-string<\datetime>>
     */
    protected $casts = [
        'now' => 'datetime',
    ];
}
