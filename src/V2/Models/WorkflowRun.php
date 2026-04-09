<?php

declare(strict_types=1);

namespace Workflow\V2\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\RunStatus;

class WorkflowRun extends Model
{
    use HasUlids;

    public $incrementing = false;

    protected $table = 'workflow_runs';

    protected $guarded = [];

    protected $keyType = 'string';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $casts = [
        'status' => RunStatus::class,
        'last_command_sequence' => 'integer',
        'started_at' => 'datetime',
        'closed_at' => 'datetime',
        'last_progress_at' => 'datetime',
    ];

    public function instance(): BelongsTo
    {
        return $this->belongsTo(WorkflowInstance::class, 'workflow_instance_id');
    }

    public function historyEvents(): HasMany
    {
        return $this->hasMany(WorkflowHistoryEvent::class)
            ->orderBy('sequence');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(WorkflowTask::class)
            ->orderBy('available_at');
    }

    public function commands(): HasMany
    {
        return $this->hasMany(WorkflowCommand::class, 'workflow_run_id')
            ->orderBy('command_sequence')
            ->oldest('created_at')
            ->oldest('id');
    }

    public function updates(): HasMany
    {
        return $this->hasMany(WorkflowUpdate::class, 'workflow_run_id')
            ->orderBy('command_sequence')
            ->oldest('accepted_at')
            ->oldest('created_at')
            ->oldest('id');
    }

    public function signals(): HasMany
    {
        return $this->hasMany(WorkflowSignal::class, 'workflow_run_id')
            ->orderBy('command_sequence')
            ->oldest('received_at')
            ->oldest('created_at')
            ->oldest('id');
    }

    public function activityExecutions(): HasMany
    {
        return $this->hasMany(ActivityExecution::class)
            ->orderBy('sequence');
    }

    public function activityAttempts(): HasMany
    {
        return $this->hasMany(ActivityAttempt::class, 'workflow_run_id')
            ->orderBy('attempt_number')
            ->oldest('started_at')
            ->oldest('id');
    }

    public function timers(): HasMany
    {
        return $this->hasMany(WorkflowTimer::class)
            ->orderBy('sequence');
    }

    public function failures(): HasMany
    {
        return $this->hasMany(WorkflowFailure::class)
            ->latest('created_at');
    }

    public function summary(): HasOne
    {
        return $this->hasOne(WorkflowRunSummary::class, 'id', 'id');
    }

    public function parentLinks(): HasMany
    {
        return $this->hasMany(WorkflowLink::class, 'child_workflow_run_id')
            ->oldest('created_at');
    }

    public function childLinks(): HasMany
    {
        return $this->hasMany(WorkflowLink::class, 'parent_workflow_run_id')
            ->oldest('created_at');
    }

    /**
     * @return array<int, mixed>
     */
    public function workflowArguments(): array
    {
        if ($this->arguments === null) {
            return [];
        }

        /** @var array<int, mixed> $arguments */
        $arguments = Serializer::unserialize($this->arguments);

        return $arguments;
    }

    public function workflowOutput(): mixed
    {
        if ($this->output === null) {
            return null;
        }

        return Serializer::unserialize($this->output);
    }
}
