<?php

declare(strict_types=1);

namespace Workflow\V2\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Support\ConfiguredV2Models;
use Workflow\V2\Support\RepairBlockedReason;
use Workflow\V2\Support\WorkflowTaskProblem;

class WorkflowRunSummary extends Model
{
    public $incrementing = false;

    protected $table = 'workflow_run_summaries';

    protected $guarded = [];

    protected $keyType = 'string';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $appends = [
        'instance_id',
        'selected_run_id',
        'run_id',
        'exceptions_count',
        'is_terminal',
        'repair_blocked',
        'task_problem_badge',
    ];

    protected $casts = [
        'is_current_run' => 'bool',
        'repair_attention' => 'bool',
        'task_problem' => 'bool',
        'visibility_labels' => 'array',
        'search_attributes' => 'array',
        'declared_contract_backfill_needed' => 'bool',
        'declared_contract_backfill_available' => 'bool',
        'projection_schema_version' => 'integer',
        'history_event_count' => 'integer',
        'history_size_bytes' => 'integer',
        'continue_as_new_recommended' => 'bool',
        'started_at' => 'datetime',
        'sort_timestamp' => 'datetime',
        'closed_at' => 'datetime',
        'archived_at' => 'datetime',
        'wait_started_at' => 'datetime',
        'wait_deadline_at' => 'datetime',
        'next_task_at' => 'datetime',
        'next_task_lease_expires_at' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(ConfiguredV2Models::resolve('run_model', WorkflowRun::class), 'id', 'id');
    }

    /**
     * Typed search attributes relationship.
     *
     * Enables efficient Waterline visibility filtering via JOIN:
     * WorkflowRunSummary::whereHas('searchAttributes', fn($q) =>
     *     $q->where('key', 'customer_id')->where('value_keyword', 'cust_123')
     * )->get();
     */
    public function searchAttributes(): HasMany
    {
        return $this->hasMany(
            ConfiguredV2Models::resolve('search_attribute_model', WorkflowSearchAttribute::class),
            'workflow_run_id',
            'id',
        );
    }

    public function getInstanceIdAttribute(): string
    {
        return $this->workflow_instance_id;
    }

    public function getSelectedRunIdAttribute(): string
    {
        return $this->id;
    }

    public function getRunIdAttribute(): string
    {
        return $this->id;
    }

    public function getExceptionsCountAttribute(): int
    {
        return (int) $this->exception_count;
    }

    public function getIsTerminalAttribute(): bool
    {
        return RunStatus::from($this->status)->isTerminal();
    }

    /**
     * @return array{
     *     code: string,
     *     label: string,
     *     description: string,
     *     tone: string,
     *     badge_visible: bool
     * }|null
     */
    public function getRepairBlockedAttribute(): ?array
    {
        return RepairBlockedReason::metadata(
            is_string($this->repair_blocked_reason) ? $this->repair_blocked_reason : null,
        );
    }

    /**
     * @return array{
     *     code: string,
     *     label: string,
     *     description: string,
     *     tone: string,
     *     badge_visible: bool
     * }|null
     */
    public function getTaskProblemBadgeAttribute(): ?array
    {
        return WorkflowTaskProblem::metadata(
            (bool) $this->task_problem,
            is_string($this->liveness_state) ? $this->liveness_state : null,
            is_string($this->wait_kind) ? $this->wait_kind : null,
        );
    }

    /**
     * Get search attributes with dual-read fallback.
     *
     * Phase 1 dual-read: prefer typed table when available, fall back to JSON blob.
     * This enables gradual migration without breaking existing Waterline queries.
     *
     * @return array<string, mixed> Key-value pairs
     */
    public function getTypedSearchAttributes(): array
    {
        // Try typed table first (optimal for new runs)
        if ($this->relationLoaded('searchAttributes')) {
            $typed = $this->searchAttributes->mapWithKeys(function (WorkflowSearchAttribute $attr) {
                return [$attr->key => $attr->getValue()];
            })->toArray();

            if (! empty($typed)) {
                return $typed;
            }
        }

        // Fallback to JSON blob (for old runs or if typed storage failed)
        return is_array($this->search_attributes) ? $this->search_attributes : [];
    }

    /**
     * Scope query to runs with specific search attribute value.
     *
     * Efficient filtering using typed table indexes.
     *
     * Example:
     * WorkflowRunSummary::withSearchAttribute('customer_id', 'cust_123')->get();
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $key Attribute key
     * @param mixed $value Attribute value
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithSearchAttribute($query, string $key, mixed $value)
    {
        return $query->whereHas('searchAttributes', function ($q) use ($key, $value) {
            $q->where('key', $key);

            // Route to appropriate typed column
            if (is_bool($value)) {
                $q->where('value_bool', $value);
            } elseif (is_int($value)) {
                $q->where('value_int', $value);
            } elseif (is_float($value)) {
                $q->where('value_float', $value);
            } elseif ($value instanceof \DateTimeInterface) {
                $q->where('value_datetime', $value);
            } elseif (is_string($value) && mb_strlen($value) <= 255) {
                $q->where('value_keyword', $value);
            } else {
                $q->where('value_string', $value);
            }
        });
    }
}
