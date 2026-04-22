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
        'activity_options' => 'array',
        'parallel_group_path' => 'array',
        'started_at' => 'datetime',
        'closed_at' => 'datetime',
        'last_heartbeat_at' => 'datetime',
        'schedule_deadline_at' => 'datetime',
        'close_deadline_at' => 'datetime',
        'schedule_to_close_deadline_at' => 'datetime',
        'heartbeat_deadline_at' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(ConfiguredV2Models::resolve('run_model', WorkflowRun::class), 'workflow_run_id');
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
        $arguments = $this->unserializeWithRowCodec($this->arguments);

        return $arguments;
    }

    public function activityResult(): mixed
    {
        if ($this->result === null) {
            return null;
        }

        return $this->unserializeWithRowCodec($this->result);
    }

    /**
     * Decode an activity payload with the codec persisted beside the row.
     * Null is kept as a defensive fallback for rows written before this
     * column existed in unreleased v2 builds.
     */
    private function unserializeWithRowCodec(string $blob): mixed
    {
        if (is_string($this->payload_codec) && $this->payload_codec !== '') {
            return Serializer::unserializeWithCodec($this->payload_codec, $blob);
        }

        return Serializer::unserialize($blob);
    }
}
