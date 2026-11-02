<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use JsonException;
use LogicException;
use Throwable;
use Workflow\V2\Contracts\PredictableExternalPayloadStorageDriver;
use Workflow\V2\Exceptions\HistoryTransactionLimitExceededException;

/**
 * The whole-transaction budget of one workflow task completion (#930).
 *
 * History is charged where it is written ({@see \Workflow\V2\Models\WorkflowHistoryEvent::record()}):
 * one event and its bytes as {@see HistoryBudget} measures them. Write sites
 * that are metered charge the rows they will affect before their statement
 * runs. Raw external payload bytes are charged before each payload is staged
 * or stored. Staged objects are stored only by {@see self::flush()}, after
 * the completion has passed every limit and before it commits, so a refused
 * completion stores none of them.
 *
 * A meter belongs to one database transaction attempt: {@see self::run()}
 * gives every attempt a fresh meter and discards it with that attempt. A
 * limit refusal also trips the meter, so code that catches the exception
 * cannot continue to a commit: {@see self::run()} refuses again before
 * returning. Limits are hard {@see StructuralLimits}; 0 disables one.
 */
final class HistoryTransactionMeter
{
    public const EVENTS = 'history_transaction_size';

    public const BYTES = 'history_transaction_bytes';

    public const ROWS = 'history_transaction_rows';

    public const STAGED_BYTES = 'external_payload_transaction_bytes';

    public const PAYLOAD_SIZE = 'payload_size_bytes';

    private static ?self $current = null;

    /**
     * @var array<string, int>|null The totals of the last attempt that committed its body.
     */
    private static ?array $lastUsed = null;

    /**
     * @var array<string, int>
     */
    private array $used = [
        self::EVENTS => 0,
        self::BYTES => 0,
        self::ROWS => 0,
        self::STAGED_BYTES => 0,
    ];

    /**
     * Staged objects keyed by driver instance and predicted URI.
     *
     * @var array<string, array{driver: PredictableExternalPayloadStorageDriver, data: string, sha256: string, codec: string, uri: string}>
     */
    private array $stage = [];

    private ?HistoryTransactionLimitExceededException $tripped = null;

    /**
     * @param array<string, int> $limits
     */
    private function __construct(
        private readonly array $limits
    ) {
    }

    /**
     * Run one transaction attempt's body with a fresh meter, then store its
     * staged payloads. Call it inside the database transaction callback, so
     * a retried attempt starts over and a refusal rolls everything back.
     *
     * @template T
     * @param callable(self): T $body
     * @return T
     */
    public static function run(callable $body): mixed
    {
        if (self::$current !== null) {
            throw new LogicException('A history transaction meter is already running.');
        }

        $meter = new self([
            self::EVENTS => StructuralLimits::historyTransactionSizeLimit(),
            self::BYTES => StructuralLimits::historyTransactionBytesLimit(),
            self::ROWS => StructuralLimits::historyTransactionRowsLimit(),
            self::STAGED_BYTES => StructuralLimits::externalPayloadTransactionBytesLimit(),
        ]);
        self::$current = $meter;

        try {
            $result = $body($meter);
            $meter->refuseIfTripped();
            $meter->flush();
            $meter->refuseIfTripped();
            self::$lastUsed = $meter->used;

            return $result;
        } finally {
            self::$current = null;
        }
    }

    public static function current(): ?self
    {
        return self::$current;
    }

    /**
     * Run server-owned work on stored state inside a metered completion
     * without charging it: a run's deadline close, which no other path
     * performs while the run's workflow task is open.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public static function unmetered(callable $work): mixed
    {
        $meter = self::$current;
        self::$current = null;

        try {
            return $work();
        } finally {
            self::$current = $meter;
        }
    }

    /**
     * The totals the last admitted attempt charged, for diagnostics and the
     * row-accounting tests.
     *
     * @return array<string, int>|null
     */
    public static function lastUsed(): ?array
    {
        return self::$lastUsed;
    }

    /**
     * Overflow-checked addition for every budget aggregate.
     */
    public static function checkedAdd(int $left, int $right): int
    {
        $sum = $left + $right;
        if (! is_int($sum)) {
            throw HistoryTransactionLimitExceededException::over(
                'history_transaction_arithmetic',
                PHP_INT_MAX,
                PHP_INT_MAX
            );
        }

        return $sum;
    }

    /**
     * Overflow-checked multiplication for every budget aggregate.
     */
    public static function checkedMultiply(int $left, int $right): int
    {
        $product = $left * $right;
        if (! is_int($product)) {
            throw HistoryTransactionLimitExceededException::over(
                'history_transaction_arithmetic',
                PHP_INT_MAX,
                PHP_INT_MAX
            );
        }

        return $product;
    }

    /**
     * One history event about to be written, with its bytes measured as
     * {@see HistoryBudget} measures a stored event.
     *
     * @param array<string, mixed> $payload
     */
    public function chargeEvent(string $eventType, array $payload): void
    {
        try {
            $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            $this->trip(HistoryTransactionLimitExceededException::refused(
                HistoryTransactionLimitExceededException::MEASURE_UNAVAILABLE,
                'The history event cannot be measured, so the completion is refused.',
            ));
        }

        $this->charge(self::EVENTS, 1);
        $this->charge(self::BYTES, self::checkedAdd(strlen($eventType), strlen($encoded)));
        $this->charge(self::ROWS, 1);
    }

    /**
     * Rows the next statement will insert, update, upsert or delete,
     * including any cascade it causes. Charged before the statement runs.
     */
    public function chargeRows(int $rows): void
    {
        if ($rows < 0) {
            throw new LogicException('A write cannot affect a negative number of rows.');
        }
        $this->charge(self::ROWS, $rows);
    }

    /**
     * How many more rows this completion may affect, or null when the row
     * limit is disabled. Bulk write sites read at most this plus one row.
     */
    public function remainingRows(): ?int
    {
        $limit = $this->limits[self::ROWS];

        return $limit > 0 ? max(0, $limit - $this->used[self::ROWS]) : null;
    }

    /**
     * Stage an externalizable payload: the per-payload limit and the raw
     * external payload byte limit are charged first (each occurrence counts),
     * then the object is registered under its driver and predicted URI for
     * {@see self::flush()}. The same driver predicting the same URI for other
     * content, hash or codec fails closed.
     */
    public function stage(
        PredictableExternalPayloadStorageDriver $driver,
        string $data,
        string $codec
    ): ExternalPayloadReference {
        $size = strlen($data);
        $this->chargePayload($size);

        $sha256 = hash('sha256', $data);
        $uri = $driver->uriFor($sha256, $codec);
        // The stage holds the driver, so its object id stays unique while staged.
        $key = spl_object_id($driver) . "\0" . $uri;
        $staged = $this->stage[$key] ?? null;
        if ($staged === null) {
            $this->stage[$key] = [
                'driver' => $driver,
                'data' => $data,
                'sha256' => $sha256,
                'codec' => $codec,
                'uri' => $uri,
            ];
        } elseif (! hash_equals($staged['sha256'], $sha256)
            || $staged['codec'] !== $codec
            || ! hash_equals($staged['data'], $data)) {
            $this->trip(HistoryTransactionLimitExceededException::refused(
                HistoryTransactionLimitExceededException::STAGED_URI_COLLISION,
                'The external payload driver predicted one URI for different payloads.',
            ));
        }

        return new ExternalPayloadReference(uri: $uri, sha256: $sha256, sizeBytes: $size, codec: $codec);
    }

    /**
     * Charge a payload that a driver without URI prediction stores during the
     * completion: the per-payload and raw external payload byte limits apply
     * before the driver's put, as for a staged payload.
     */
    public function chargeUnstaged(int $size): void
    {
        $this->chargePayload($size);
    }

    /**
     * @return array<string, int>
     */
    public function used(): array
    {
        return $this->used;
    }

    /**
     * Store every staged object, in staging order. Each put must return the
     * predicted URI; any failure rolls the completion back. An object already
     * stored by this flush stays: content-addressed objects may be shared, so
     * nothing is deleted, and reachability-based retention collects it.
     */
    private function flush(): void
    {
        foreach ($this->stage as $entry) {
            try {
                $stored = $entry['driver']->put($entry['data'], $entry['sha256'], $entry['codec']);
            } catch (Throwable $error) {
                $this->stage = [];

                throw $error;
            }
            if ($stored !== $entry['uri']) {
                $this->stage = [];
                $this->trip(HistoryTransactionLimitExceededException::refused(
                    HistoryTransactionLimitExceededException::STAGED_URI_MISMATCH,
                    'The external payload driver stored a payload under a different URI than it predicted.',
                ));
            }
        }
        $this->stage = [];
    }

    private function chargePayload(int $size): void
    {
        $this->refuseIfTripped();
        $payloadLimit = StructuralLimits::payloadSizeLimit();
        if ($payloadLimit > 0 && $size > $payloadLimit) {
            $this->trip(HistoryTransactionLimitExceededException::over(self::PAYLOAD_SIZE, $size, $payloadLimit));
        }
        $this->charge(self::STAGED_BYTES, $size);
    }

    private function charge(string $dimension, int $amount): void
    {
        $this->refuseIfTripped();
        $next = self::checkedAdd($this->used[$dimension], $amount);
        $limit = $this->limits[$dimension];
        if ($limit > 0 && $next > $limit) {
            $this->trip(HistoryTransactionLimitExceededException::over($dimension, $next, $limit));
        }
        $this->used[$dimension] = $next;
    }

    private function refuseIfTripped(): void
    {
        if ($this->tripped !== null) {
            throw $this->tripped;
        }
    }

    private function trip(HistoryTransactionLimitExceededException $refusal): never
    {
        $this->tripped = $refusal;
        $this->stage = [];

        throw $refusal;
    }
}
