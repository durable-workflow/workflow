<?php

declare(strict_types=1);

namespace Workflow\V2\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Support\ConfiguredV2Models;

class ActivityExecution extends Model
{
    use HasUlids;

    public $incrementing = false;

    protected $table = 'activity_executions';

    protected $guarded = [];

    protected $keyType = 'string';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $casts = [
        'status' => ActivityStatus::class,
        'retry_policy' => 'array',
        'parallel_group_path' => 'array',
        'started_at' => 'datetime',
        'closed_at' => 'datetime',
        'last_heartbeat_at' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(
            ConfiguredV2Models::resolve('run_model', WorkflowRun::class),
            'workflow_run_id',
        );
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(
            ConfiguredV2Models::resolve('activity_attempt_model', ActivityAttempt::class),
            'activity_execution_id',
        )
            ->orderBy('attempt_number')
            ->oldest('started_at')
            ->oldest('id');
    }

    /**
     * @return array<int, mixed>
     */
    public function activityArguments(): array
    {
        if ($this->arguments === null) {
            return [];
        }

        /** @var array<int, mixed> $arguments */
        $arguments = Serializer::unserialize($this->arguments);

        return $arguments;
    }

    public function activityResult(): mixed
    {
        if ($this->result === null) {
            return null;
        }

        return Serializer::unserialize($this->result);
    }
}
