<?php

declare(strict_types=1);

namespace Workflow\V2\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowTimelineEntry extends Model
{
    public $incrementing = false;

    protected $table = 'workflow_run_timeline_entries';

    protected $guarded = [];

    protected $keyType = 'string';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $casts = [
        'sequence' => 'integer',
        'command_sequence' => 'integer',
        'payload' => 'array',
        'recorded_at' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(WorkflowRun::class, 'workflow_run_id', 'id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toTimelinePayload(): array
    {
        $payload = is_array($this->payload) ? $this->payload : [];

        $payload['id'] = $this->history_event_id;
        $payload['sequence'] = $this->sequence;
        $payload['type'] = $this->type;
        $payload['kind'] = $this->kind;
        $payload['entry_kind'] = $this->entry_kind;
        $payload['source_kind'] = $this->source_kind;
        $payload['source_id'] = $this->source_id;
        $payload['summary'] = $this->summary;
        $payload['recorded_at'] = self::timestamp($this->recorded_at);
        $payload['command_id'] = $this->command_id;
        $payload['command_sequence'] = $this->command_sequence;
        $payload['task_id'] = $this->task_id;
        $payload['activity_execution_id'] = $this->activity_execution_id;
        $payload['timer_id'] = $this->timer_id;
        $payload['failure_id'] = $this->failure_id;

        return $payload;
    }

    private static function timestamp(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->toJSON();
        }

        return is_string($value) && $value !== ''
            ? $value
            : null;
    }
}
