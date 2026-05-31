<?php

declare(strict_types=1);

namespace Workflow\Models;

use Illuminate\Database\Eloquent\Model;
use Workflow\Traits\ResolvesStorageConnection;

class StoredWorkflowSignal extends Model
{
    use ResolvesStorageConnection;

    public const UPDATED_AT = null;

    /**
     * @var string
     */
    protected $table = 'workflow_signals';

    /**
     * @var mixed[]
     */
    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.u';
}
