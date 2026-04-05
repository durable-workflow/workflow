<?php

declare(strict_types=1);

namespace Workflow\V2\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Workflow\V2\Enums\HistoryEventType;

class WorkflowHistoryEvent extends Model
{
    use HasUlids;

    public $incrementing = false;

    protected $table = 'workflow_history_events';

    protected $guarded = [];

    protected $keyType = 'string';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $casts = [
        'event_type' => HistoryEventType::class,
        'payload' => 'array',
        'recorded_at' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(WorkflowRun::class, 'workflow_run_id');
    }

    public function command(): BelongsTo
    {
        return $this->belongsTo(WorkflowCommand::class, 'workflow_command_id');
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function record(
        WorkflowRun $run,
        HistoryEventType $eventType,
        array $payload = [],
        ?string $taskId = null,
        ?string $commandId = null,
    ): self {
        $sequence = $run->last_history_sequence + 1;

        /** @var self $event */
        $event = self::query()->create([
            'workflow_run_id' => $run->id,
            'sequence' => $sequence,
            'event_type' => $eventType->value,
            'payload' => $payload,
            'workflow_task_id' => $taskId,
            'workflow_command_id' => $commandId,
            'recorded_at' => now(),
        ]);

        $run->forceFill([
            'last_history_sequence' => $sequence,
            'last_progress_at' => now(),
        ])->save();

        return $event;
    }
}
