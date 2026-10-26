<?php

declare(strict_types=1);

namespace Workflow\V2\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Workflow\Traits\ResolvesStorageConnection;
use Workflow\V2\Support\ConfiguredV2Models;
use Workflow\V2\Support\UtcScheduleTimestamp;

class WorkflowRunWait extends Model
{
    use ResolvesStorageConnection;

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
        'opened_at' => UtcScheduleTimestamp::class,
        'deadline_at' => UtcScheduleTimestamp::class,
        'resolved_at' => UtcScheduleTimestamp::class,
    ];

    /**
     * @param string $key
     */
    public function originalIsEquivalent($key): bool
    {
        if (parent::originalIsEquivalent($key)) {
            return true;
        }

        if (! array_key_exists($key, $this->original)) {
            return false;
        }

        if ($key === 'payload' && ($this->getCasts()[$key] ?? null) === 'array') {
            // MySQL normalizes JSON object key order. Lists and scalar types
            // still carry meaning, so compare those without coercion.
            return self::canonicalPayload($this->getAttribute($key))
                === self::canonicalPayload($this->getOriginal($key));
        }

        if (in_array($key, ['opened_at', 'deadline_at', 'resolved_at'], true)
            && ($this->getCasts()[$key] ?? null) === UtcScheduleTimestamp::class) {
            $current = $this->getAttribute($key);
            $original = $this->getOriginal($key);

            // PostgreSQL can return a zero-fraction timestamp without its
            // trailing digits. Its instant has not changed.
            return $current instanceof CarbonInterface
                && $original instanceof CarbonInterface
                && $current->equalTo($original);
        }

        return false;
    }

    public function scopeInDisplayOrder(Builder $query): Builder
    {
        $status = $query->getQuery()
            ->getGrammar()
            ->wrap($this->qualifyColumn('status'));

        return $query->orderByRaw(
            "CASE {$status} WHEN 'open' THEN 0 WHEN 'resolved' THEN 1 WHEN 'cancelled' THEN 2 ELSE 99 END",
        )->orderBy($this->qualifyColumn('position'))
            ->orderBy($this->qualifyColumn('wait_id'));
    }

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

    private static function canonicalPayload(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::canonicalPayload(...), $value);
    }
}
