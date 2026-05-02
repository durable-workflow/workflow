<?php

declare(strict_types=1);

namespace Workflow\V2\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Workflow\Serializers\CodecRegistry;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Support\ConfiguredV2Models;

class WorkflowRun extends Model
{
    use HasUlids;

    public $incrementing = false;

    protected $table = 'workflow_runs';

    protected $guarded = [];

    protected $keyType = 'string';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $casts = [
        'status' => RunStatus::class,
        'visibility_labels' => 'array',
        'last_command_sequence' => 'integer',
        'message_cursor_position' => 'integer',
        'run_timeout_seconds' => 'integer',
        'execution_deadline_at' => 'datetime',
        'run_deadline_at' => 'datetime',
        'started_at' => 'datetime',
        'closed_at' => 'datetime',
        'archived_at' => 'datetime',
        'last_progress_at' => 'datetime',
    ];

    public function instance(): BelongsTo
    {
        return $this->belongsTo(
            ConfiguredV2Models::resolve('instance_model', WorkflowInstance::class),
            'workflow_instance_id',
        );
    }

    public function historyEvents(): HasMany
    {
        return $this->hasMany(
            ConfiguredV2Models::resolve('history_event_model', WorkflowHistoryEvent::class),
            'workflow_run_id',
        )
            ->orderBy('sequence');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(ConfiguredV2Models::resolve('task_model', WorkflowTask::class), 'workflow_run_id')
            ->orderBy('available_at');
    }

    public function commands(): HasMany
    {
        return $this->hasMany(
            ConfiguredV2Models::resolve('command_model', WorkflowCommand::class),
            'workflow_run_id',
        )
            ->orderBy('command_sequence')
            ->oldest('created_at')
            ->oldest('id');
    }

    public function updates(): HasMany
    {
        return $this->hasMany(
            ConfiguredV2Models::resolve('update_model', WorkflowUpdate::class),
            'workflow_run_id',
        )
            ->orderBy('command_sequence')
            ->oldest('accepted_at')
            ->oldest('created_at')
            ->oldest('id');
    }

    public function outgoingServiceCalls(): HasMany
    {
        return $this->hasMany(
            ConfiguredV2Models::resolve('service_call_model', WorkflowServiceCall::class),
            'caller_workflow_run_id',
        )
            ->oldest('accepted_at')
            ->oldest('created_at')
            ->oldest('id');
    }

    public function linkedServiceCalls(): HasMany
    {
        return $this->hasMany(
            ConfiguredV2Models::resolve('service_call_model', WorkflowServiceCall::class),
            'linked_workflow_run_id',
        )
            ->oldest('accepted_at')
            ->oldest('created_at')
            ->oldest('id');
    }

    public function signals(): HasMany
    {
        return $this->hasMany(
            ConfiguredV2Models::resolve('signal_model', WorkflowSignal::class),
            'workflow_run_id',
        )
            ->orderBy('command_sequence')
            ->oldest('received_at')
            ->oldest('created_at')
            ->oldest('id');
    }

    public function activityExecutions(): HasMany
    {
        return $this->hasMany(
            ConfiguredV2Models::resolve('activity_execution_model', ActivityExecution::class),
            'workflow_run_id',
        )
            ->orderBy('sequence');
    }

    public function activityAttempts(): HasMany
    {
        return $this->hasMany(
            ConfiguredV2Models::resolve('activity_attempt_model', ActivityAttempt::class),
            'workflow_run_id',
        )
            ->orderBy('attempt_number')
            ->oldest('started_at')
            ->oldest('id');
    }

    public function timers(): HasMany
    {
        return $this->hasMany(ConfiguredV2Models::resolve('timer_model', WorkflowTimer::class), 'workflow_run_id')
            ->orderBy('sequence');
    }

    public function failures(): HasMany
    {
        return $this->hasMany(
            ConfiguredV2Models::resolve('failure_model', WorkflowFailure::class),
            'workflow_run_id',
        )
            ->latest('created_at');
    }

    public function summary(): HasOne
    {
        return $this->hasOne(
            ConfiguredV2Models::resolve('run_summary_model', WorkflowRunSummary::class),
            'id',
            'id',
        );
    }

    public function searchAttributes(): HasMany
    {
        return $this->hasMany(
            ConfiguredV2Models::resolve('search_attribute_model', WorkflowSearchAttribute::class),
            'workflow_run_id',
            'id',
        );
    }

    public function memos(): HasMany
    {
        return $this->hasMany(
            ConfiguredV2Models::resolve('memo_model', WorkflowMemo::class),
            'workflow_run_id',
            'id',
        );
    }

    public function waits(): HasMany
    {
        return $this->hasMany(
            ConfiguredV2Models::resolve('run_wait_model', WorkflowRunWait::class),
            'workflow_run_id',
        )
            ->orderBy('position')
            ->orderBy('wait_id');
    }

    public function timelineEntries(): HasMany
    {
        return $this->hasMany(
            ConfiguredV2Models::resolve('run_timeline_entry_model', WorkflowTimelineEntry::class),
            'workflow_run_id',
        )
            ->orderBy('sequence')
            ->orderBy('history_event_id');
    }

    public function timerEntries(): HasMany
    {
        return $this->hasMany(
            ConfiguredV2Models::resolve('run_timer_entry_model', WorkflowRunTimerEntry::class),
            'workflow_run_id',
        )
            ->orderBy('position')
            ->orderBy('timer_id');
    }

    public function lineageEntries(): HasMany
    {
        return $this->hasMany(
            ConfiguredV2Models::resolve('run_lineage_entry_model', WorkflowRunLineageEntry::class),
            'workflow_run_id',
        )
            ->orderBy('direction')
            ->orderBy('position')
            ->orderBy('lineage_id');
    }

    public function parentLinks(): HasMany
    {
        return $this->hasMany(
            ConfiguredV2Models::resolve('link_model', WorkflowLink::class),
            'child_workflow_run_id',
        )
            ->oldest('created_at');
    }

    public function childLinks(): HasMany
    {
        return $this->hasMany(
            ConfiguredV2Models::resolve('link_model', WorkflowLink::class),
            'parent_workflow_run_id',
        )
            ->oldest('created_at');
    }

    /**
     * @return array<int, mixed>
     */
    public function workflowArguments(): array
    {
        if ($this->arguments === null) {
            return [];
        }

        /** @var array<int, mixed> $arguments */
        $arguments = $this->unserializePayload($this->arguments);

        return $arguments;
    }

    public function workflowOutput(): mixed
    {
        if ($this->output === null) {
            return null;
        }

        return $this->unserializePayload($this->output);
    }

    /**
     * @return array{codec: string, blob: string}|null
     */
    public function argumentsEnvelope(): ?array
    {
        if ($this->arguments === null) {
            return null;
        }

        return [
            'codec' => $this->payload_codec ?? CodecRegistry::defaultCodec(),
            'blob' => $this->arguments,
        ];
    }

    /**
     * @return array{codec: string, blob: string}|null
     */
    public function outputEnvelope(): ?array
    {
        if ($this->output === null) {
            return null;
        }

        return [
            'codec' => $this->payload_codec ?? CodecRegistry::defaultCodec(),
            'blob' => $this->output,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function typedSearchAttributes(): array
    {
        if (! $this->exists) {
            return [];
        }

        $this->loadMissing('searchAttributes');

        /** @var \Illuminate\Database\Eloquent\Collection<int, WorkflowSearchAttribute> $searchAttributes */
        $searchAttributes = $this->getRelation('searchAttributes');

        return $searchAttributes
            ->mapWithKeys(static function (WorkflowSearchAttribute $attribute): array {
                return [
                    $attribute->key => $attribute->getValue(),
                ];
            })
            ->sortKeys()
            ->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function typedMemos(): array
    {
        if (! $this->exists) {
            return [];
        }

        $this->loadMissing('memos');

        /** @var \Illuminate\Database\Eloquent\Collection<int, WorkflowMemo> $memos */
        $memos = $this->getRelation('memos');

        return $memos
            ->mapWithKeys(static function (WorkflowMemo $memo): array {
                return [
                    $memo->key => $memo->getValue(),
                ];
            })
            ->sortKeys()
            ->toArray();
    }

    /**
     * Decode a payload (arguments or output) with the run's pinned codec
     * when available. Falls back to the legacy codec-blind sniffer so rows
     * persisted before payload_codec was populated keep decoding.
     */
    private function unserializePayload(string $blob): mixed
    {
        if (is_string($this->payload_codec) && $this->payload_codec !== '') {
            return Serializer::unserializeWithCodec($this->payload_codec, $blob);
        }

        return Serializer::unserialize($blob);
    }
}
