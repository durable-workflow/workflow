<?php

declare(strict_types=1);

namespace Workflow\V2\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Workflow\V2\Support\ConfiguredV2Models;

class WorkflowRunLineageEntry extends Model
{
    public $incrementing = false;

    protected $table = 'workflow_run_lineage_entries';

    protected $guarded = [];

    protected $keyType = 'string';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $casts = [
        'position' => 'integer',
        'sequence' => 'integer',
        'is_primary_parent' => 'bool',
        'related_run_number' => 'integer',
        'payload' => 'array',
        'linked_at' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(
            ConfiguredV2Models::resolve('run_model', WorkflowRun::class),
            'workflow_run_id',
            'id',
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toLineagePayload(): array
    {
        $payload = is_array($this->payload) ? $this->payload : [];

        $payload['id'] = $this->lineage_id;
        $payload['link_type'] = $this->link_type;
        $payload['child_call_id'] = $this->child_call_id;
        $payload['sequence'] = $this->sequence;
        $payload['is_primary_parent'] = (bool) $this->is_primary_parent;
        $payload['workflow_instance_id'] = $this->related_workflow_instance_id;
        $payload['workflow_run_id'] = $this->related_workflow_run_id;
        $payload['run_number'] = $this->related_run_number;
        $payload['workflow_type'] = $this->related_workflow_type;
        $payload['class'] = $this->related_workflow_class;
        $payload['status'] = $this->status;
        $payload['status_bucket'] = $this->status_bucket;
        $payload['closed_reason'] = $this->closed_reason;
        $payload['created_at'] = $this->linked_at?->toJSON();
        $payload['history_authority'] = $payload['history_authority'] ?? null;
        $payload['diagnostic_only'] = (bool) ($payload['diagnostic_only'] ?? false);

        if ($this->direction === 'parent') {
            $payload['parent_workflow_id'] = $this->related_workflow_instance_id;
            $payload['parent_workflow_run_id'] = $this->related_workflow_run_id;
        } elseif ($this->direction === 'child') {
            $payload['child_workflow_id'] = $this->related_workflow_instance_id;
            $payload['child_workflow_run_id'] = $this->related_workflow_run_id;
        }

        return $payload;
    }
}
