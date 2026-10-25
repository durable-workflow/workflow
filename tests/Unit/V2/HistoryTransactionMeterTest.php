<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use LogicException;
use RuntimeException;
use Tests\NonDatabaseTestCase;
use Workflow\V2\Contracts\PredictableExternalPayloadStorageDriver;
use Workflow\V2\Exceptions\HistoryTransactionLimitExceededException;
use Workflow\V2\Support\HistoryTransactionMeter;

/**
 * Workflow #930: the whole-transaction meter of a workflow task completion.
 */
final class HistoryTransactionMeterTest extends NonDatabaseTestCase
{
    public function testEventsBytesAndRowsAreChargedAsHistoryBudgetMeasuresThem(): void
    {
        $used = HistoryTransactionMeter::run(static function (HistoryTransactionMeter $meter): array {
            $meter->chargeEvent('ActivityStarted', [
                'a' => 'b/é',
            ]);
            $meter->chargeRows(2);

            return $meter->used();
        });

        // {"a":"b/é"}: slashes and unicode unescaped, as HistoryBudget stores it.
        $this->assertSame([
            HistoryTransactionMeter::EVENTS => 1,
            HistoryTransactionMeter::BYTES => strlen('ActivityStarted') + strlen('{"a":"b/é"}'),
            HistoryTransactionMeter::ROWS => 3,
            HistoryTransactionMeter::STAGED_BYTES => 0,
        ], $used);
    }

    public function testEachLimitAllowsItsExactValueAndRefusesOneMore(): void
    {
        foreach ([
            HistoryTransactionMeter::EVENTS => 'history_transaction_size',
            HistoryTransactionMeter::ROWS => 'history_transaction_rows',
        ] as $dimension => $config) {
            config([
                'workflows.v2.structural_limits.' . $config => 3,
            ]);
            HistoryTransactionMeter::run(static function (HistoryTransactionMeter $meter) use ($dimension): void {
                $dimension === HistoryTransactionMeter::EVENTS
                    ? array_map(static fn () => $meter->chargeEvent('E', []), range(1, 3))
                    : $meter->chargeRows(3);
            });
            try {
                HistoryTransactionMeter::run(static function (HistoryTransactionMeter $meter) use ($dimension): void {
                    $dimension === HistoryTransactionMeter::EVENTS
                        ? array_map(static fn () => $meter->chargeEvent('E', []), range(1, 4))
                        : $meter->chargeRows(4);
                });
                $this->fail("{$dimension} accepted one over its limit.");
            } catch (HistoryTransactionLimitExceededException $refusal) {
                $this->assertSame($dimension, $refusal->dimension);
                $this->assertSame(4, $refusal->used);
                $this->assertSame(3, $refusal->limit);
            }
            config([
                'workflows.v2.structural_limits.' . $config => null,
            ]);
        }

        // A disabled limit (0) charges without refusing.
        config([
            'workflows.v2.structural_limits.history_transaction_bytes' => 0,
        ]);
        $used = HistoryTransactionMeter::run(static function (HistoryTransactionMeter $meter): int {
            $meter->chargeEvent('E', [
                'data' => str_repeat('x', 100_000),
            ]);

            return $meter->used()[HistoryTransactionMeter::BYTES];
        });
        $this->assertGreaterThan(100_000, $used);
    }

    public function testACaughtRefusalCannotContinueToACommit(): void
    {
        config([
            'workflows.v2.structural_limits.history_transaction_rows' => 1,
        ]);
        $this->expectException(HistoryTransactionLimitExceededException::class);
        HistoryTransactionMeter::run(static function (HistoryTransactionMeter $meter): string {
            try {
                $meter->chargeRows(2);
            } catch (HistoryTransactionLimitExceededException) {
                // Swallowed by mistake: the meter still refuses before returning.
            }

            return 'committed';
        });
    }

    public function testAggregatesAreOverflowChecked(): void
    {
        $this->assertSame(PHP_INT_MAX, HistoryTransactionMeter::checkedAdd(PHP_INT_MAX - 1, 1));
        $this->expectException(HistoryTransactionLimitExceededException::class);
        HistoryTransactionMeter::checkedAdd(PHP_INT_MAX, 1);
    }

    public function testMultiplicationIsOverflowChecked(): void
    {
        $this->assertSame(6, HistoryTransactionMeter::checkedMultiply(2, 3));
        $this->expectException(HistoryTransactionLimitExceededException::class);
        HistoryTransactionMeter::checkedMultiply(PHP_INT_MAX, 2);
    }

    public function testAnUnmeasurableEventFailsClosed(): void
    {
        try {
            HistoryTransactionMeter::run(static function (HistoryTransactionMeter $meter): void {
                $meter->chargeEvent('E', [
                    'bad' => "\xB1\x31",
                ]);
            });
            $this->fail('An event json_encode cannot measure was accepted.');
        } catch (HistoryTransactionLimitExceededException $refusal) {
            $this->assertSame(HistoryTransactionLimitExceededException::MEASURE_UNAVAILABLE, $refusal->dimension);
        }
    }

    public function testStagedPayloadsAreStoredOnlyWhenTheWholeAttemptPasses(): void
    {
        $driver = new RecordingPredictableDriver();

        // Refused after staging: nothing is stored.
        config([
            'workflows.v2.structural_limits.history_transaction_rows' => 1,
        ]);
        try {
            HistoryTransactionMeter::run(static function (HistoryTransactionMeter $meter) use ($driver): void {
                $meter->stage($driver, 'payload-one', 'avro');
                $meter->chargeRows(2);
            });
            $this->fail('A refused attempt returned.');
        } catch (HistoryTransactionLimitExceededException) {
        }
        $this->assertSame([], $driver->puts);

        // Accepted: each distinct object once, under its predicted URI, while
        // every occurrence counts toward the staged bytes.
        config([
            'workflows.v2.structural_limits.history_transaction_rows' => null,
        ]);
        $used = HistoryTransactionMeter::run(static function (HistoryTransactionMeter $meter) use ($driver): int {
            $first = $meter->stage($driver, 'payload-one', 'avro');
            $meter->stage($driver, 'payload-one', 'avro');
            $meter->stage($driver, 'payload-two', 'avro');
            TestCaseAssert::same($driver->uriFor(hash('sha256', 'payload-one'), 'avro'), $first->uri);

            return $meter->used()[HistoryTransactionMeter::STAGED_BYTES];
        });
        $this->assertSame(3 * strlen('payload-one'), $used);
        $this->assertSame(
            [hash('sha256', 'payload-one'), hash('sha256', 'payload-two')],
            array_column($driver->puts, 'sha256')
        );

        // A fresh attempt starts with an empty stage.
        $driver->puts = [];
        HistoryTransactionMeter::run(
            static fn (HistoryTransactionMeter $meter): int => $meter->used()[HistoryTransactionMeter::STAGED_BYTES]
        );
        $this->assertSame([], $driver->puts);
    }

    public function testRawStagedBytesAreLimitedPerPayloadAndPerTransaction(): void
    {
        $driver = new RecordingPredictableDriver();
        config([
            'workflows.v2.structural_limits.payload_size_bytes' => 10,
            'workflows.v2.structural_limits.external_payload_transaction_bytes' => 20,
        ]);

        foreach ([
            [[str_repeat('a', 11)], HistoryTransactionMeter::PAYLOAD_SIZE],
            [[str_repeat('a', 10), str_repeat('b', 10), 'c'], HistoryTransactionMeter::STAGED_BYTES],
        ] as [$payloads, $dimension]) {
            try {
                HistoryTransactionMeter::run(static function (HistoryTransactionMeter $meter) use (
                    $driver,
                    $payloads
                ): void {
                    foreach ($payloads as $payload) {
                        $meter->stage($driver, $payload, 'avro');
                    }
                });
                $this->fail("{$dimension} accepted one byte over.");
            } catch (HistoryTransactionLimitExceededException $refusal) {
                $this->assertSame($dimension, $refusal->dimension);
            }
        }
        $this->assertSame([], $driver->puts);

        // Exactly at both limits.
        HistoryTransactionMeter::run(static function (HistoryTransactionMeter $meter) use ($driver): void {
            $meter->stage($driver, str_repeat('a', 10), 'avro');
            $meter->stage($driver, str_repeat('b', 10), 'avro');
        });
        $this->assertCount(2, $driver->puts);
    }

    public function testAMispredictedUriOrAFailedPutRollsTheAttemptBack(): void
    {
        $lying = new RecordingPredictableDriver(lie: true);
        try {
            HistoryTransactionMeter::run(
                static fn (HistoryTransactionMeter $meter) => $meter->stage($lying, 'x', 'avro')
            );
            $this->fail('A mispredicted URI was accepted.');
        } catch (HistoryTransactionLimitExceededException $refusal) {
            $this->assertSame(HistoryTransactionLimitExceededException::STAGED_URI_MISMATCH, $refusal->dimension);
        }

        $failing = new RecordingPredictableDriver(failAfter: 1);
        try {
            HistoryTransactionMeter::run(static function (HistoryTransactionMeter $meter) use ($failing): void {
                $meter->stage($failing, 'first', 'avro');
                $meter->stage($failing, 'second', 'avro');
            });
            $this->fail('A failed put was accepted.');
        } catch (RuntimeException $error) {
            $this->assertSame('put failed', $error->getMessage());
        }
        // The first object stays (content-addressed objects may be shared);
        // the attempt itself failed and rolls back.
        $this->assertCount(1, $failing->puts);
    }

    public function testAnUnstageableDriverIsRefusedAndMetersDoNotNest(): void
    {
        try {
            HistoryTransactionMeter::run(static fn (HistoryTransactionMeter $meter) => $meter->refuseUnstageable());
            $this->fail('An unstageable payload was accepted.');
        } catch (HistoryTransactionLimitExceededException $refusal) {
            $this->assertSame(HistoryTransactionLimitExceededException::STAGING_UNSUPPORTED, $refusal->dimension);
        }
        $this->assertNull(HistoryTransactionMeter::current());

        $this->expectException(LogicException::class);
        HistoryTransactionMeter::run(static fn () => HistoryTransactionMeter::run(static fn () => null));
    }
}

/**
 * Assertions callable from static closures.
 */
final class TestCaseAssert
{
    public static function same(mixed $expected, mixed $actual): void
    {
        \PHPUnit\Framework\Assert::assertSame($expected, $actual);
    }
}

/**
 * A content-addressed driver that records every put.
 */
final class RecordingPredictableDriver implements PredictableExternalPayloadStorageDriver
{
    /**
     * @var list<array{sha256: string, codec: string}>
     */
    public array $puts = [];

    public function __construct(
        private readonly bool $lie = false,
        private readonly ?int $failAfter = null
    ) {
    }

    public function uriFor(string $sha256, string $codec): string
    {
        return "memory://{$codec}/{$sha256}";
    }

    public function put(string $data, string $sha256, string $codec): string
    {
        if ($this->failAfter !== null && count($this->puts) >= $this->failAfter) {
            throw new RuntimeException('put failed');
        }
        $this->puts[] = [
            'sha256' => $sha256,
            'codec' => $codec,
        ];

        return $this->lie ? "memory://elsewhere/{$sha256}" : $this->uriFor($sha256, $codec);
    }

    public function get(string $uri): string
    {
        throw new RuntimeException('not used');
    }

    public function delete(string $uri): void
    {
        throw new RuntimeException('not used');
    }
}
