<?php

declare(strict_types=1);

namespace Workflow\V2\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Workflow\V2\Support\ConfiguredV2Models;

class WorkflowRunTimerEntry extends Model
{
    public const CURRENT_SCHEMA_VERSION = 1;

    public $incrementing = false;

    protected $table = 'workflow_run_timer_entries';

    protected $guarded = [];

    protected $keyType = 'string';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $casts = [
        'schema_version' => 'integer',
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
        return $this->belongsTo(
            ConfiguredV2Models::resolve('run_model', WorkflowRun::class),
            'workflow_run_id',
            'id',
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toTimerPayload(): array
    {
        $payload = is_array($this->payload) ? $this->payload : [];
        $status = self::stringValue($payload['status'] ?? $this->status);
        $sourceStatus = self::stringValue($payload['source_status'] ?? $this->source_status)
            ?? $status;
        $historyAuthority = self::stringValue($payload['history_authority'] ?? $this->history_authority);
        $historyUnsupportedReason = self::stringValue(
            $payload['history_unsupported_reason'] ?? $this->history_unsupported_reason
        );

        $payload['id'] = $this->timer_id;
        $payload['sequence'] = $this->sequence;
        $payload['status'] = $status;
        $payload['source_status'] = $sourceStatus;
        $payload['delay_seconds'] = $this->delay_seconds;
        $payload['fire_at'] = $this->fire_at;
        $payload['fired_at'] = $this->fired_at;
        $payload['cancelled_at'] = $this->cancelled_at;
        $payload['timer_kind'] = $this->timer_kind;
        $payload['condition_wait_id'] = $this->condition_wait_id;
        $payload['condition_key'] = $this->condition_key;
        $payload['condition_definition_fingerprint'] = $this->condition_definition_fingerprint;
        $payload['history_authority'] = $historyAuthority;
        $payload['history_unsupported_reason'] = $historyUnsupportedReason;
        $payload['row_status'] = self::rowStatus($payload['row_status'] ?? null);
        $payload['diagnostic_only'] = self::diagnosticOnly($historyAuthority);
        $payload['created_at'] = self::timestamp($payload['created_at'] ?? null);

        return $payload;
    }

    public function schemaVersion(): ?int
    {
        return is_int($this->schema_version)
            ? $this->schema_version
            : null;
    }

    public function usesCurrentSchema(): bool
    {
        return $this->schemaVersion() === self::CURRENT_SCHEMA_VERSION;
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

    private static function diagnosticOnly(mixed $historyAuthority): bool
    {
        return is_string($historyAuthority)
            && $historyAuthority !== ''
            && $historyAuthority !== 'typed_history';
    }

    private static function rowStatus(mixed $value): ?string
    {
        return self::stringValue($value);
    }

    private static function stringValue(mixed $value): ?string
    {
        return is_string($value) && $value !== ''
            ? $value
            : null;
    }
}
