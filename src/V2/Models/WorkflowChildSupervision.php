<?php

declare(strict_types=1);

namespace Workflow\V2\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Workflow\Traits\ResolvesStorageConnection;
use Workflow\V2\Support\ConfiguredV2Models;

/**
 * Effective supervision of an open child that an earlier run of a
 * continue-as-new chain started.
 *
 * The child link keeps its original parent run, sequence and parent-close
 * policy. This row records which later run of the chain now applies that
 * policy when it closes.
 */
class WorkflowChildSupervision extends Model
{
    use ResolvesStorageConnection;

    use HasUlids;

    public $incrementing = false;

    protected $table = 'workflow_child_supervisions';

    protected $guarded = [];

    protected $keyType = 'string';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $casts = [
        'sequence' => 'integer',
    ];

    public function link(): BelongsTo
    {
        return $this->belongsTo(ConfiguredV2Models::resolve('link_model', WorkflowLink::class), 'workflow_link_id');
    }

    public function supervisingRun(): BelongsTo
    {
        return $this->belongsTo(
            ConfiguredV2Models::resolve('run_model', WorkflowRun::class),
            'supervising_workflow_run_id',
        );
    }

    public function parentRun(): BelongsTo
    {
        return $this->belongsTo(
            ConfiguredV2Models::resolve('run_model', WorkflowRun::class),
            'parent_workflow_run_id',
        );
    }
}
