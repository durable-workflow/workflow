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
     * Decode an activity payload. Activity executions do not carry their own
     * payload_codec column because the scheduling path may fall back from
     * Avro to the legacy Y codec when arguments carry PHP-only values (see
     * WorkflowExecutor::scheduleActivity and #429). The blob is
     * self-describing — Avro's base64-plus-prefix envelope and PHP
     * serialize's `O:`/`a:`/… header byte are disjoint, so the legacy
     * sniff-based unserialize path picks the right codec regardless of
     * which one was chosen at write time.
     *
     * The run's `payload_codec` remains the authority for the rest of the
     * run state (command payloads, history, etc.) — only the activity
     * arguments/result blob gets the sniff treatment.
     */
    private function unserializeWithRunCodec(string $blob): mixed
    {
        return Serializer::unserialize($blob);
    }
}
