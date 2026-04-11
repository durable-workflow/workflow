<?php

declare(strict_types=1);

namespace Workflow\V2\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Workflow\V2\Support\ConfiguredV2Models;

class WorkflowLink extends Model
{
    use HasUlids;

    public $incrementing = false;

    protected $table = 'workflow_links';

    protected $guarded = [];

    protected $keyType = 'string';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $casts = [
        'sequence' => 'integer',
        'is_primary_parent' => 'boolean',
        'parallel_group_path' => 'array',
    ];

    public function parentRun(): BelongsTo
    {
        return $this->belongsTo(
            ConfiguredV2Models::resolve('run_model', WorkflowRun::class),
            'parent_workflow_run_id',
        );
    }

    public function childRun(): BelongsTo
    {
        return $this->belongsTo(
            ConfiguredV2Models::resolve('run_model', WorkflowRun::class),
            'child_workflow_run_id',
        );
    }
}
