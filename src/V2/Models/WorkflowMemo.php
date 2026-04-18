<?php

declare(strict_types=1);

namespace Workflow\V2\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;
use Workflow\V2\Support\ConfiguredV2Models;

class WorkflowMemo extends Model
{
    // Size and count limits (Phase 1 structural limits)
    public const MAX_MEMOS_PER_RUN = 100;

    public const MAX_VALUE_SIZE_BYTES = 10240; // 10KB per memo

    public const MAX_TOTAL_SIZE_BYTES = 65536; // 64KB total per run

    public $incrementing = true;

    protected $table = 'workflow_memos';

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $casts = [
        'value' => 'array',
        'upserted_at_sequence' => 'integer',
        'inherited_from_parent' => 'boolean',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(ConfiguredV2Models::resolve('run_model', WorkflowRun::class), 'workflow_run_id');
    }

    /**
     * Get the memo value.
     *
     * @return mixed JSON-decodable value
     */
    public function getValue(): mixed
    {
        return $this->value;
    }

    /**
     * Set the memo value with size validation.
     *
     * @param mixed $value JSON-encodable value
     */
    public function setValue(mixed $value): void
    {
        $encoded = json_encode($value, JSON_THROW_ON_ERROR);
        $sizeBytes = strlen($encoded);

        if ($sizeBytes > self::MAX_VALUE_SIZE_BYTES) {
            throw new InvalidArgumentException(sprintf(
                'Memo value for key "%s" exceeds maximum size of %d bytes (actual: %d bytes)',
                $this->key,
                self::MAX_VALUE_SIZE_BYTES,
                $sizeBytes,
            ));
        }

        $this->value = $value;
    }

    /**
     * Validate total memo size for a run.
     *
     * @param string $runId The workflow run ID
     */
    public static function validateTotalSize(string $runId): void
    {
        $memos = static::where('workflow_run_id', $runId)->get();

        $totalSize = $memos->sum(static function (self $memo): int {
            return strlen(json_encode($memo->value, JSON_THROW_ON_ERROR));
        });

        if ($totalSize > self::MAX_TOTAL_SIZE_BYTES) {
            throw new InvalidArgumentException(sprintf(
                'Total memo size for run %s exceeds maximum of %d bytes (actual: %d bytes)',
                $runId,
                self::MAX_TOTAL_SIZE_BYTES,
                $totalSize,
            ));
        }
    }

    /**
     * Validate memo count for a run.
     *
     * @param string $runId The workflow run ID
     */
    public static function validateCount(string $runId): void
    {
        $count = static::where('workflow_run_id', $runId)->count();

        if ($count > self::MAX_MEMOS_PER_RUN) {
            throw new InvalidArgumentException(sprintf(
                'Memo count for run %s exceeds maximum of %d (actual: %d)',
                $runId,
                self::MAX_MEMOS_PER_RUN,
                $count,
            ));
        }
    }
}
