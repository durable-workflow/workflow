<?php

declare(strict_types=1);

namespace Workflow\V2\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class WorkflowRunTimerEntry extends Model
{
    public $incrementing = false;

    protected $table = 'workflow_run_timer_entries';

    protected $guarded = [];

    protected $keyType = 'string';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $casts = [
        'position' => 'integer',
        'sequence' => 'integer',
        'delay_seconds' => 'integer',
        'payload' => 'array',
        'fire_at' => 'datetime',
        'fired_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(WorkflowRun::class, 'workflow_run_id', 'id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toTimerPayload(): array
    {
        $payload = is_array($this->payload) ? $this->payload : [];

        $payload['id'] = $this->timer_id;
        $payload['sequence'] = $this->sequence;
        $payload['status'] = $this->status;
        $payload['source_status'] = $this->source_status;
        $payload['delay_seconds'] = $this->delay_seconds;
        $payload['fire_at'] = $this->fire_at;
        $payload['fired_at'] = $this->fired_at;
        $payload['cancelled_at'] = $this->cancelled_at;
        $payload['timer_kind'] = $this->timer_kind;
        $payload['condition_wait_id'] = $this->condition_wait_id;
        $payload['condition_key'] = $this->condition_key;
        $payload['condition_definition_fingerprint'] = $this->condition_definition_fingerprint;
        $payload['history_authority'] = $this->history_authority;
        $payload['history_unsupported_reason'] = $this->history_unsupported_reason;
        $payload['created_at'] = self::timestamp($payload['created_at'] ?? null);

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
