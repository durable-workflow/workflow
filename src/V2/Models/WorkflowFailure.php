<?php

declare(strict_types=1);

namespace Workflow\V2\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Workflow\Traits\ResolvesStorageConnection;
use Workflow\V2\Enums\FailureCategory;
use Workflow\V2\Support\ConfiguredV2Models;

class WorkflowFailure extends Model
{
    use ResolvesStorageConnection;

    use HasUlids;

    private const LEGACY_MESSAGE_LIMIT_BYTES = 65_535;

    private const OVERFLOW_MARKER = "\n[full message stored separately]";

    public $incrementing = false;

    protected $table = 'workflow_failures';

    protected $guarded = [];

    protected $hidden = ['message_full'];

    protected $keyType = 'string';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $casts = [
        'handled' => 'bool',
        'non_retryable' => 'bool',
        'failure_category' => FailureCategory::class,
    ];

    /**
     * @return Attribute<string|null, string|null>
     */
    protected function message(): Attribute
    {
        return Attribute::make(
            get: static fn (?string $value, array $attributes): ?string =>
                is_string($attributes['message_full'] ?? null) ? $attributes['message_full'] : $value,
            set: static function (?string $value): array {
                if ($value === null || strlen($value) <= self::LEGACY_MESSAGE_LIMIT_BYTES) {
                    return [
                        'message' => $value,
                        'message_full' => null,
                    ];
                }

                // Keep the canonical value intact. Older nodes can still read
                // a valid TEXT preview without a copying column alteration.
                return [
                    'message' => mb_strcut(
                        $value,
                        0,
                        self::LEGACY_MESSAGE_LIMIT_BYTES - strlen(self::OVERFLOW_MARKER),
                        'UTF-8',
                    ) . self::OVERFLOW_MARKER,
                    'message_full' => $value,
                ];
            },
        );
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(ConfiguredV2Models::resolve('run_model', WorkflowRun::class), 'workflow_run_id');
    }
}
