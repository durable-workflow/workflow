<?php

declare(strict_types=1);

namespace Workflow\V2\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Workflow\Traits\ResolvesStorageConnection;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Support\ConfiguredV2Models;
use Workflow\V2\Support\TaskSchedulingFields;

class WorkflowTask extends Model
{
    use ResolvesStorageConnection;

    use HasUlids;

    public $incrementing = false;

    protected $table = 'workflow_tasks';

    protected $guarded = [];

    protected $hidden = ['observability_context', 'queue_delivery_reference'];

    protected $keyType = 'string';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $casts = [
        'observability_context' => 'array',
        'task_type' => TaskType::class,
        'status' => TaskStatus::class,
        'payload' => 'array',
        'queue_delivery_reference' => 'array',
        'repair_count' => 'integer',
        'priority' => 'integer',
        'fairness_weight' => 'integer',
        'sticky_until' => 'datetime',
        'sticky_claimed_at' => 'datetime',
        'available_at' => 'datetime',
        'leased_at' => 'datetime',
        'lease_expires_at' => 'datetime',
        'last_dispatch_attempt_at' => 'datetime',
        'last_dispatched_at' => 'datetime',
        'last_claim_failed_at' => 'datetime',
        'repair_available_at' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(ConfiguredV2Models::resolve('run_model', WorkflowRun::class), 'workflow_run_id');
    }

    /**
     * Registered in boot() rather than booted() so a configured task model
     * that defines its own booted() callbacks keeps run-scheduling inheritance.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(static function (self $task): void {
            $task->inheritRunScheduling();
        });
    }

    /**
     * Give a new task its run's priority and fairness when the creator did
     * not set them, so resume, timer, retry and repair tasks keep the run's
     * scheduling class. Activity tasks keep their per-call overrides.
     */
    private function inheritRunScheduling(): void
    {
        $missing = array_diff(TaskSchedulingFields::FIELDS, array_keys($this->getAttributes()));
        $runId = $this->getAttribute('workflow_run_id');

        if ($missing === [] || ! is_string($runId) || $runId === '') {
            return;
        }

        // The relation resolves on this task's storage connection.
        $run = $this->run()
            ->first();

        if (! $run instanceof WorkflowRun) {
            return;
        }

        $fields = $this->task_type === TaskType::Activity
            ? TaskSchedulingFields::forActivity($run, $this->activityExecution($run))
            : TaskSchedulingFields::forRun($run);

        foreach ($missing as $field) {
            $this->setAttribute($field, $fields[$field]);
        }
    }

    /**
     * The activity execution named by the payload, only when it belongs to
     * the same run; another run's per-call options never apply.
     */
    private function activityExecution(WorkflowRun $run): ?ActivityExecution
    {
        $payload = is_array($this->payload) ? $this->payload : [];
        $executionId = $payload['activity_execution_id'] ?? null;

        if (! is_string($executionId) || $executionId === '') {
            return null;
        }

        $execution = $this->newRelatedInstance(
            ConfiguredV2Models::resolve('activity_execution_model', ActivityExecution::class)
        )
            ->newQuery()
            ->whereKey($executionId)
            ->where('workflow_run_id', $run->getKey())
            ->first();

        return $execution instanceof ActivityExecution ? $execution : null;
    }
}
