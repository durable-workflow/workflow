<?php

declare(strict_types=1);

namespace Workflow\V2\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Workflow\Traits\ResolvesStorageConnection;
use Workflow\V2\Enums\FailureCategory;
use Workflow\V2\Exceptions\FailureMessageStorageException;
use Workflow\V2\Support\ConfiguredV2Models;

class WorkflowFailure extends Model
{
    use ResolvesStorageConnection;

    use HasUlids;

    private const LEGACY_MESSAGE_LIMIT_BYTES = 65_535;

    private const OVERFLOW_MARKER = "\n[full message stored separately]";

    private const FULL_MESSAGE_ENCODING = 'base64:';

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
                ($attributes['message_full'] ?? null) === null
                    ? $value
                    : self::decodeFullMessage($attributes['message_full']),
            set: static function (?string $value): array {
                if ($value === null || (strlen($value) <= self::LEGACY_MESSAGE_LIMIT_BYTES
                        && ! str_contains($value, "\0"))) {
                    return [
                        'message' => $value,
                        'message_full' => null,
                    ];
                }

                // Keep the canonical value intact. Older nodes can still read
                // a valid TEXT preview without a copying column alteration.
                return [
                    'message' => mb_strcut(
                        str_replace("\0", '\\0', $value),
                        0,
                        self::LEGACY_MESSAGE_LIMIT_BYTES - strlen(self::OVERFLOW_MARKER),
                        'UTF-8',
                    ) . self::OVERFLOW_MARKER,
                    // PostgreSQL text parameters cannot carry literal NULs.
                    // This is internal storage, not an SDK payload codec.
                    'message_full' => self::FULL_MESSAGE_ENCODING . base64_encode($value),
                ];
            },
        );
    }

    private static function decodeFullMessage(mixed $stored): string
    {
        if (! is_string($stored) || ! str_starts_with($stored, self::FULL_MESSAGE_ENCODING)) {
            throw new FailureMessageStorageException('Invalid stored full failure-message encoding.');
        }

        $encoded = substr($stored, strlen(self::FULL_MESSAGE_ENCODING));
        $decoded = base64_decode($encoded, true);
        if ($decoded === false || base64_encode($decoded) !== $encoded) {
            throw new FailureMessageStorageException('Invalid stored full failure-message encoding.');
        }

        return $decoded;
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(ConfiguredV2Models::resolve('run_model', WorkflowRun::class), 'workflow_run_id');
    }
}
