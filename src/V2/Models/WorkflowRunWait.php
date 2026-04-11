<?php

declare(strict_types=1);

namespace Workflow\V2\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Workflow\V2\Support\ConfiguredV2Models;

class WorkflowRunWait extends Model
{
    public $incrementing = false;

    protected $table = 'workflow_run_waits';

    protected $guarded = [];

    protected $keyType = 'string';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $casts = [
        'position' => 'integer',
        'sequence' => 'integer',
        'task_backed' => 'bool',
        'external_only' => 'bool',
        'command_sequence' => 'integer',
        'payload' => 'array',
        'opened_at' => 'datetime',
        'deadline_at' => 'datetime',
        'resolved_at' => 'datetime',
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
    public function toWaitPayload(): array
    {
        $payload = is_array($this->payload) ? $this->payload : [];

        $payload['id'] = $this->wait_id;
        $payload['kind'] = $this->kind;
        $payload['sequence'] = $this->sequence;
        $payload['status'] = $this->status;
        $payload['source_status'] = $this->source_status;
        $payload['summary'] = $this->summary;
        $payload['opened_at'] = $this->opened_at;
        $payload['deadline_at'] = $this->deadline_at;
        $payload['resolved_at'] = $this->resolved_at;
        $payload['target_name'] = $this->target_name;
        $payload['target_type'] = $this->target_type;
        $payload['task_backed'] = (bool) $this->task_backed;
        $payload['external_only'] = (bool) $this->external_only;
        $payload['resume_source_kind'] = $this->resume_source_kind;
        $payload['resume_source_id'] = $this->resume_source_id;
        $payload['task_id'] = $this->task_id;
        $payload['task_type'] = $this->task_type;
        $payload['task_status'] = $this->task_status;
        $payload['command_id'] = $this->command_id;
        $payload['command_sequence'] = $this->command_sequence;
        $payload['command_status'] = $this->command_status;
        $payload['command_outcome'] = $this->command_outcome;
        $payload['history_authority'] = $this->history_authority;
        $payload['history_unsupported_reason'] = $this->history_unsupported_reason;
        $payload['timeout_fired_at'] = self::timestamp($payload['timeout_fired_at'] ?? null);

        return $payload;
    }

    private static function timestamp(mixed $value): ?CarbonInterface
    {
        if ($value instanceof CarbonInterface) {
            return $value;
        }

        return is_string($value) && $value !== ''
            ? Carbon::parse($value)
            : null;
    }
}
