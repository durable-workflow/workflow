<?php

declare(strict_types=1);

namespace Workflow\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Arr;
use Workflow\States\HasStates;
use Workflow\States\WorkflowContinuedStatus;
use Workflow\States\WorkflowStatus;
use Workflow\WorkflowMetadata;
use Workflow\WorkflowOptions;
use Workflow\WorkflowStub;

class StoredWorkflow extends Model
{
    use HasStates;
    use Prunable;

    /**
     * @var int
     */
    public const CONTINUE_PARENT_INDEX = PHP_INT_MAX;

    /**
     * @var int
     */
    public const ACTIVE_WORKFLOW_INDEX = PHP_INT_MAX - 1;

    /**
     * @var string
     */
    protected $table = 'workflows';

    /**
     * @var mixed[]
     */
    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    /**
     * @var array<string, class-string<\Workflow\States\WorkflowStatus>>
     */
    protected $casts = [
        'status' => WorkflowStatus::class,
    ];

    public function toWorkflow()
    {
        return WorkflowStub::fromStoredWorkflow($this);
    }

    public function workflowMetadata(): WorkflowMetadata
    {
        $arguments = $this->arguments;

        if ($arguments === null) {
            return new WorkflowMetadata([]);
        }

        return WorkflowMetadata::fromSerializedArguments(
            \Workflow\Serializers\Serializer::unserialize($arguments)
        );
    }

    /**
     * @return array<int, mixed>
     */
    public function workflowArguments(): array
    {
        return $this->workflowMetadata()
->arguments;
    }

    public function workflowOptions(): WorkflowOptions
    {
        return $this->workflowMetadata()
->options;
    }

    public function effectiveConnection(): ?string
    {
        $connection = $this->workflowOptions()
->connection;

        if ($connection !== null) {
            return $connection;
        }

        if (! is_string($this->class) || $this->class === '') {
            return null;
        }

        return Arr::get(WorkflowStub::getDefaultProperties($this->class), 'connection');
    }

    public function effectiveQueue(): ?string
    {
        $queue = $this->workflowOptions()
->queue;

        if ($queue !== null) {
            return $queue;
        }

        if (! is_string($this->class) || $this->class === '') {
            return null;
        }

        $connection = $this->effectiveConnection() ?? config('queue.default');

        return Arr::get(WorkflowStub::getDefaultProperties($this->class), 'queue')
            ?? config('queue.connections.' . $connection . '.queue', 'default');
    }

    public function logs(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(config('workflows.stored_workflow_log_model', StoredWorkflowLog::class))
            ->orderBy('id');
    }

    public function findLogByIndex(int $index, bool $fresh = false): ?StoredWorkflowLog
    {
        if ($fresh) {
            $log = $this->logs()
                ->whereIndex($index)
                ->first();

            if ($this->relationLoaded('logs') && $log !== null) {
                /** @var Collection<int, StoredWorkflowLog> $logs */
                $logs = $this->getRelation('logs');
                if (! $logs->contains('id', $log->id)) {
                    $this->setRelation('logs', $logs->push($log)->sortBy('id')->values());
                }
            }

            return $log;
        }

        if ($this->relationLoaded('logs')) {
            /** @var Collection<int, StoredWorkflowLog> $logs */
            $logs = $this->getRelation('logs');
            return $logs->first(static fn (StoredWorkflowLog $log): bool => self::modelHasIndex($log, $index));
        }

        return $this->logs()
            ->whereIndex($index)
            ->first();
    }

    public function hasLogByIndex(int $index): bool
    {
        if ($this->relationLoaded('logs')) {
            return $this->findLogByIndex($index) !== null;
        }

        return $this->logs()
            ->whereIndex($index)
            ->exists();
    }

    public function createLog(array $attributes): StoredWorkflowLog
    {
        /** @var StoredWorkflowLog $log */
        $log = $this->logs()
            ->create($attributes);

        if ($this->relationLoaded('logs')) {
            /** @var Collection<int, StoredWorkflowLog> $logs */
            $logs = $this->getRelation('logs');
            $this->setRelation('logs', $logs->push($log)->sortBy('id')->values());
        }

        return $log;
    }

    public function signals(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(config('workflows.stored_workflow_signal_model', StoredWorkflowSignal::class))
            ->orderBy('id');
    }

    public function timers(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(config('workflows.stored_workflow_timer_model', StoredWorkflowTimer::class))
            ->orderBy('id');
    }

    public function findTimerByIndex(int $index): ?StoredWorkflowTimer
    {
        if ($this->relationLoaded('timers')) {
            /** @var Collection<int, StoredWorkflowTimer> $timers */
            $timers = $this->getRelation('timers');
            return $timers->first(
                static fn (StoredWorkflowTimer $timer): bool => self::modelHasIndex($timer, $index)
            );
        }

        return $this->timers()
            ->whereIndex($index)
            ->first();
    }

    public function createTimer(array $attributes): StoredWorkflowTimer
    {
        /** @var StoredWorkflowTimer $timer */
        $timer = $this->timers()
            ->create($attributes);

        if ($this->relationLoaded('timers')) {
            /** @var Collection<int, StoredWorkflowTimer> $timers */
            $timers = $this->getRelation('timers');
            $this->setRelation('timers', $timers->push($timer)->sortBy('id')->values());
        }

        return $timer;
    }

    public function orderedSignals(): Collection
    {
        if ($this->relationLoaded('signals')) {
            /** @var Collection<int, StoredWorkflowSignal> $signals */
            $signals = $this->getRelation('signals');
            return $signals->sortBy('created_at')
                ->values();
        }

        return $this->signals()
            ->orderBy('created_at')
            ->get();
    }

    public function exceptions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(config('workflows.stored_workflow_exception_model', StoredWorkflowException::class))
            ->orderBy('id');
    }

    public function parents(): BelongsToMany
    {
        return $this->belongsToMany(
            config('workflows.stored_workflow_model', self::class),
            config('workflows.workflow_relationships_table', 'workflow_relationships'),
            'child_workflow_id',
            'parent_workflow_id'
        )->withPivot(['parent_index', 'parent_now']);
    }

    public function children(): BelongsToMany
    {
        return $this->belongsToMany(
            config('workflows.stored_workflow_model', self::class),
            config('workflows.workflow_relationships_table', 'workflow_relationships'),
            'parent_workflow_id',
            'child_workflow_id'
        )->withPivot(['parent_index', 'parent_now']);
    }

    public function continuedWorkflows(): BelongsToMany
    {
        return $this->belongsToMany(
            config('workflows.stored_workflow_model', self::class),
            config('workflows.workflow_relationships_table', 'workflow_relationships'),
            'parent_workflow_id',
            'child_workflow_id'
        )->wherePivot('parent_index', self::CONTINUE_PARENT_INDEX)
            ->withPivot(['parent_index', 'parent_now'])
            ->orderBy('child_workflow_id');
    }

    public function activeWorkflow(): BelongsToMany
    {
        return $this->belongsToMany(
            config('workflows.stored_workflow_model', self::class),
            config('workflows.workflow_relationships_table', 'workflow_relationships'),
            'parent_workflow_id',
            'child_workflow_id'
        )->wherePivot('parent_index', self::ACTIVE_WORKFLOW_INDEX)
            ->withPivot(['parent_index', 'parent_now'])
            ->orderBy('child_workflow_id');
    }

    public function active(): self
    {
        $active = $this->fresh();

        if ($active->status::class === WorkflowContinuedStatus::class) {
            $continued = $this->activeWorkflow()
                ->first();

            if ($continued !== null) {
                $active = $continued;
            }
        }

        return $active;
    }

    public function prunable(): Builder
    {
        return static::where('status', 'completed')
            ->where('created_at', '<=', now()->sub(config('workflows.prune_age', '1 month')))
            ->whereDoesntHave('parents');
    }

    protected function pruning(): void
    {
        $this->recursivePrune($this);
    }

    protected function recursivePrune(self $workflow): void
    {
        $workflow->children()
            ->each(function ($child) {
                $this->recursivePrune($child);
            });

        $workflow->parents()
            ->detach();
        $workflow->exceptions()
            ->delete();
        $workflow->logs()
            ->delete();
        $workflow->signals()
            ->delete();
        $workflow->timers()
            ->delete();

        if ($workflow->id !== $this->id) {
            $workflow->delete();
        }
    }

    private static function modelHasIndex(Model $model, int $index): bool
    {
        // Use raw attributes so loaded relations never fall back to Eloquent's magic relation lookup.
        $attributes = $model->getAttributes();

        return array_key_exists('index', $attributes) && (int) $attributes['index'] === $index;
    }
}
