<?php

declare(strict_types=1);

namespace Workflow\V2\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Workflow\V2\Support\ConfiguredV2Models;

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
        'memo' => 'array',
        'last_message_sequence' => 'integer',
    ];

    public function currentRun(): BelongsTo
    {
        return $this->belongsTo(ConfiguredV2Models::resolve('run_model', WorkflowRun::class), 'current_run_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(ConfiguredV2Models::resolve('run_model', WorkflowRun::class));
    }

    public function commands(): HasMany
    {
        return $this->hasMany(ConfiguredV2Models::resolve('command_model', WorkflowCommand::class))
            ->oldest('created_at');
    }

    public function updates(): HasMany
    {
        return $this->hasMany(ConfiguredV2Models::resolve('update_model', WorkflowUpdate::class))
            ->orderBy('command_sequence')
            ->oldest('accepted_at')
            ->oldest('created_at')
            ->oldest('id');
    }

    public function outgoingServiceCalls(): HasMany
    {
        return $this->hasMany(
            ConfiguredV2Models::resolve('service_call_model', WorkflowServiceCall::class),
            'caller_workflow_instance_id',
        )
            ->oldest('accepted_at')
            ->oldest('created_at')
            ->oldest('id');
    }

    public function linkedServiceCalls(): HasMany
    {
        return $this->hasMany(
            ConfiguredV2Models::resolve('service_call_model', WorkflowServiceCall::class),
            'linked_workflow_instance_id',
        )
            ->oldest('accepted_at')
            ->oldest('created_at')
            ->oldest('id');
    }
}
