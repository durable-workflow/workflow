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
        $arguments = $this->unserializeWithRunCodec($this->arguments);

        return $arguments;
    }

    public function activityResult(): mixed
    {
        if ($this->result === null) {
            return null;
        }

        return $this->unserializeWithRunCodec($this->result);
    }

    /**
     * Decode an activity payload using the parent run's payload_codec when
     * available. Activity executions inherit the run's codec — they do not
     * carry their own payload_codec column. Falls back to the legacy
     * codec-blind unserialize path when the run row is unreachable so that
     * pre-codec-pinned rows still decode.
     */
    private function unserializeWithRunCodec(string $blob): mixed
    {
        $codec = $this->run?->payload_codec;

        if (is_string($codec) && $codec !== '') {
            return Serializer::unserializeWithCodec($codec, $blob);
        }

        return Serializer::unserialize($blob);
    }
}
