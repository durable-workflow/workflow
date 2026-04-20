<?php

declare(strict_types=1);

namespace Workflow\V2\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;
use Workflow\V2\Support\ConfiguredV2Models;

/**
 * Workflow Search Attribute - Typed indexed metadata.
 *
 * Phase 1 foundational model separating indexed (searchable) attributes
 * from non-indexed memos. Enables efficient Waterline visibility filtering.
 *
 * Type System:
 * - string: variable-length text (max 2048 chars)
 * - keyword: exact-match text (max 255 chars, heavily indexed)
 * - int: 64-bit signed integer
 * - float: double precision
 * - bool: boolean
 * - datetime: timestamp with microseconds
 *
 * Size Limits (per v2 plan structural limits):
 * - Max 100 attributes per run
 * - String values: 2048 characters
 * - Keyword values: 255 characters
 * - Total serialized size: 64KB per run
 *
 * @property int $id
 * @property string $workflow_run_id
 * @property string $workflow_instance_id
 * @property string $key
 * @property string $type
 * @property string|null $value_string
 * @property string|null $value_keyword
 * @property int|null $value_int
 * @property float|null $value_float
 * @property bool|null $value_bool
 * @property \Carbon\Carbon|null $value_datetime
 * @property int $upserted_at_sequence
 * @property bool $inherited_from_parent
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class WorkflowSearchAttribute extends Model
{
    // Size limits from v2 plan
    public const MAX_ATTRIBUTES_PER_RUN = 100;

    public const MAX_STRING_LENGTH = 2048;

    public const MAX_KEYWORD_LENGTH = 255;

    public const MAX_TOTAL_SIZE_BYTES = 65536; // 64KB

    // Valid types
    public const TYPE_STRING = 'string';

    public const TYPE_KEYWORD = 'keyword';

    public const TYPE_INT = 'int';

    public const TYPE_FLOAT = 'float';

    public const TYPE_BOOL = 'bool';

    public const TYPE_DATETIME = 'datetime';

    public const VALID_TYPES = [
        self::TYPE_STRING,
        self::TYPE_KEYWORD,
        self::TYPE_INT,
        self::TYPE_FLOAT,
        self::TYPE_BOOL,
        self::TYPE_DATETIME,
    ];

    protected $table = 'workflow_search_attributes';

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $casts = [
        'value_int' => 'integer',
        'value_float' => 'float',
        'value_bool' => 'boolean',
        'value_datetime' => 'datetime',
        'upserted_at_sequence' => 'integer',
        'inherited_from_parent' => 'boolean',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(ConfiguredV2Models::resolve('run_model', WorkflowRun::class), 'workflow_run_id');
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(
            ConfiguredV2Models::resolve('instance_model', WorkflowInstance::class),
            'workflow_instance_id',
        );
    }

    /**
     * Get the typed value regardless of storage column.
     */
    public function getValue(): mixed
    {
        return match ($this->type) {
            self::TYPE_STRING => $this->value_string,
            self::TYPE_KEYWORD => $this->value_keyword,
            self::TYPE_INT => $this->value_int,
            self::TYPE_FLOAT => $this->value_float,
            self::TYPE_BOOL => $this->value_bool,
            self::TYPE_DATETIME => $this->value_datetime,
            default => null,
        };
    }

    /**
     * Set typed value with coercion and validation.
     *
     * @param mixed $value Raw value to store
     * @param string $type Target type (string, keyword, int, float, bool, datetime)
     */
    public function setTypedValue(mixed $value, string $type): void
    {
        if (! in_array($type, self::VALID_TYPES, true)) {
            throw new InvalidArgumentException("Invalid search attribute type: {$type}");
        }

        $this->type = $type;

        // Clear all value columns
        $this->value_string = null;
        $this->value_keyword = null;
        $this->value_int = null;
        $this->value_float = null;
        $this->value_bool = null;
        $this->value_datetime = null;

        // Set the appropriate column with coercion and validation
        match ($type) {
            self::TYPE_STRING => $this->setStringValue($value),
            self::TYPE_KEYWORD => $this->setKeywordValue($value),
            self::TYPE_INT => $this->setIntValue($value),
            self::TYPE_FLOAT => $this->setFloatValue($value),
            self::TYPE_BOOL => $this->setBoolValue($value),
            self::TYPE_DATETIME => $this->setDatetimeValue($value),
        };
    }

    /**
     * Infer type from value and set it.
     */
    public function setTypedValueWithInference(mixed $value): void
    {
        $inferredType = $this->inferType($value);
        $this->setTypedValue($value, $inferredType);
    }

    /**
     * Infer type from PHP value.
     */
    public static function inferType(mixed $value): string
    {
        if (is_bool($value)) {
            return self::TYPE_BOOL;
        }

        if (is_int($value)) {
            return self::TYPE_INT;
        }

        if (is_float($value)) {
            return self::TYPE_FLOAT;
        }

        if ($value instanceof CarbonInterface || $value instanceof \DateTimeInterface) {
            return self::TYPE_DATETIME;
        }

        if (is_string($value)) {
            // Keyword = short identifier-like strings (IDs, enums) with no
            // whitespace. String = anything longer than the keyword column
            // or prose containing whitespace.
            if (mb_strlen($value) > self::MAX_KEYWORD_LENGTH || preg_match('/\s/u', $value) === 1) {
                return self::TYPE_STRING;
            }

            return self::TYPE_KEYWORD;
        }

        if ($value === null) {
            // Default to keyword for null (will be stored as NULL in DB)
            return self::TYPE_KEYWORD;
        }

        throw new InvalidArgumentException('Cannot infer search attribute type from value: ' . gettype($value));
    }

    /**
     * Validate total size across all attributes for a run.
     *
     * @param string $runId Workflow run ID
     */
    public static function validateTotalSize(string $runId): void
    {
        $attributes = static::where('workflow_run_id', $runId)->get();

        $totalBytes = $attributes->sum(static function (self $attr): int {
            $value = $attr->getValue();

            if ($value === null) {
                return 0;
            }

            if (is_string($value)) {
                return mb_strlen($value, '8bit');
            }

            // Estimate size for other types
            return match ($attr->type) {
                self::TYPE_INT, self::TYPE_FLOAT => 8,
                self::TYPE_BOOL => 1,
                self::TYPE_DATETIME => 8,
                default => 0,
            };
        });

        if ($totalBytes > self::MAX_TOTAL_SIZE_BYTES) {
            throw new InvalidArgumentException(
                sprintf(
                    'Total search attributes size exceeds maximum (%d > %d bytes)',
                    $totalBytes,
                    self::MAX_TOTAL_SIZE_BYTES,
                ),
            );
        }
    }

    /**
     * Validate count limit for a run.
     *
     * @param string $runId Workflow run ID
     */
    public static function validateCount(string $runId): void
    {
        $count = static::where('workflow_run_id', $runId)->count();

        if ($count > self::MAX_ATTRIBUTES_PER_RUN) {
            throw new InvalidArgumentException(
                sprintf('Search attributes count exceeds maximum (%d > %d)', $count, self::MAX_ATTRIBUTES_PER_RUN),
            );
        }
    }

    private function setStringValue(mixed $value): void
    {
        $stringValue = $this->coerceToString($value);

        if (mb_strlen($stringValue) > self::MAX_STRING_LENGTH) {
            throw new InvalidArgumentException(
                sprintf(
                    'Search attribute string value exceeds maximum length (%d > %d)',
                    mb_strlen($stringValue),
                    self::MAX_STRING_LENGTH,
                ),
            );
        }

        $this->value_string = $stringValue;
    }

    private function setKeywordValue(mixed $value): void
    {
        $stringValue = $this->coerceToString($value);

        if (mb_strlen($stringValue) > self::MAX_KEYWORD_LENGTH) {
            throw new InvalidArgumentException(
                sprintf(
                    'Search attribute keyword value exceeds maximum length (%d > %d)',
                    mb_strlen($stringValue),
                    self::MAX_KEYWORD_LENGTH,
                ),
            );
        }

        $this->value_keyword = $stringValue;
    }

    private function setIntValue(mixed $value): void
    {
        if (! is_numeric($value)) {
            throw new InvalidArgumentException("Cannot coerce value to int: {$value}");
        }

        $this->value_int = (int) $value;
    }

    private function setFloatValue(mixed $value): void
    {
        if (! is_numeric($value)) {
            throw new InvalidArgumentException("Cannot coerce value to float: {$value}");
        }

        $this->value_float = (float) $value;
    }

    private function setBoolValue(mixed $value): void
    {
        if (is_bool($value)) {
            $this->value_bool = $value;

            return;
        }

        // Coerce from common boolean representations
        if (is_int($value)) {
            $this->value_bool = $value !== 0;

            return;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['true', '1', 'yes', 'on'], true)) {
                $this->value_bool = true;

                return;
            }
            if (in_array($normalized, ['false', '0', 'no', 'off', ''], true)) {
                $this->value_bool = false;

                return;
            }
        }

        throw new InvalidArgumentException("Cannot coerce value to bool: {$value}");
    }

    private function setDatetimeValue(mixed $value): void
    {
        if ($value instanceof CarbonInterface) {
            $this->value_datetime = $value;

            return;
        }

        if ($value instanceof \DateTimeInterface) {
            $this->value_datetime = \Illuminate\Support\Carbon::instance($value);

            return;
        }

        if (is_string($value) || is_int($value)) {
            try {
                $this->value_datetime = \Illuminate\Support\Carbon::parse($value);

                return;
            } catch (\Exception $e) {
                throw new InvalidArgumentException("Cannot parse datetime value: {$value}", previous: $e);
            }
        }

        throw new InvalidArgumentException('Cannot coerce value to datetime: ' . gettype($value));
    }

    private function coerceToString(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_string($value)) {
            return $value;
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        throw new InvalidArgumentException('Cannot coerce value to string: ' . gettype($value));
    }
}
