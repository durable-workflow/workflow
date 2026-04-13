<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Tests\TestCase;
use Workflow\V2\Enums\StructuralLimitKind;
use Workflow\V2\Exceptions\StructuralLimitExceededException;
use Workflow\V2\Support\StructuralLimits;

final class StructuralLimitsTest extends TestCase
{
    // ---------------------------------------------------------------
    //  Default limits
    // ---------------------------------------------------------------

    public function testDefaultPendingActivityLimit(): void
    {
        $this->assertSame(2000, StructuralLimits::pendingActivityLimit());
    }

    public function testDefaultPendingChildLimit(): void
    {
        $this->assertSame(1000, StructuralLimits::pendingChildLimit());
    }

    public function testDefaultPendingTimerLimit(): void
    {
        $this->assertSame(2000, StructuralLimits::pendingTimerLimit());
    }

    public function testDefaultPendingSignalLimit(): void
    {
        $this->assertSame(5000, StructuralLimits::pendingSignalLimit());
    }

    public function testDefaultPendingUpdateLimit(): void
    {
        $this->assertSame(500, StructuralLimits::pendingUpdateLimit());
    }

    public function testDefaultCommandBatchSizeLimit(): void
    {
        $this->assertSame(1000, StructuralLimits::commandBatchSizeLimit());
    }

    public function testDefaultPayloadSizeLimit(): void
    {
        $this->assertSame(2097152, StructuralLimits::payloadSizeLimit());
    }

    public function testDefaultMemoSizeLimit(): void
    {
        $this->assertSame(262144, StructuralLimits::memoSizeLimit());
    }

    public function testDefaultSearchAttributeSizeLimit(): void
    {
        $this->assertSame(40960, StructuralLimits::searchAttributeSizeLimit());
    }

    // ---------------------------------------------------------------
    //  Config overrides
    // ---------------------------------------------------------------

    public function testConfigOverridesPendingActivityLimit(): void
    {
        config(['workflows.v2.structural_limits.pending_activity_count' => 100]);

        $this->assertSame(100, StructuralLimits::pendingActivityLimit());
    }

    public function testConfigOverridesPayloadSizeLimit(): void
    {
        config(['workflows.v2.structural_limits.payload_size_bytes' => 1048576]);

        $this->assertSame(1048576, StructuralLimits::payloadSizeLimit());
    }

    public function testZeroDisablesLimit(): void
    {
        config(['workflows.v2.structural_limits.pending_activity_count' => 0]);

        $this->assertSame(0, StructuralLimits::pendingActivityLimit());
    }

    public function testNegativeValueClampedToZero(): void
    {
        config(['workflows.v2.structural_limits.pending_activity_count' => -5]);

        $this->assertSame(0, StructuralLimits::pendingActivityLimit());
    }

    // ---------------------------------------------------------------
    //  Payload size guard
    // ---------------------------------------------------------------

    public function testGuardPayloadSizePassesUnderLimit(): void
    {
        config(['workflows.v2.structural_limits.payload_size_bytes' => 100]);

        StructuralLimits::guardPayloadSize(str_repeat('x', 99));

        $this->assertTrue(true); // No exception thrown.
    }

    public function testGuardPayloadSizeThrowsOverLimit(): void
    {
        config(['workflows.v2.structural_limits.payload_size_bytes' => 10]);

        $this->expectException(StructuralLimitExceededException::class);

        StructuralLimits::guardPayloadSize(str_repeat('x', 11));
    }

    public function testGuardPayloadSizeSkipsWhenDisabled(): void
    {
        config(['workflows.v2.structural_limits.payload_size_bytes' => 0]);

        StructuralLimits::guardPayloadSize(str_repeat('x', 999999));

        $this->assertTrue(true);
    }

    // ---------------------------------------------------------------
    //  Memo size guard
    // ---------------------------------------------------------------

    public function testGuardMemoSizePassesUnderLimit(): void
    {
        config(['workflows.v2.structural_limits.memo_size_bytes' => 100]);

        StructuralLimits::guardMemoSize(str_repeat('x', 99));

        $this->assertTrue(true);
    }

    public function testGuardMemoSizeThrowsOverLimit(): void
    {
        config(['workflows.v2.structural_limits.memo_size_bytes' => 10]);

        $this->expectException(StructuralLimitExceededException::class);

        StructuralLimits::guardMemoSize(str_repeat('x', 11));
    }

    // ---------------------------------------------------------------
    //  Search attribute size guard
    // ---------------------------------------------------------------

    public function testGuardSearchAttributeSizeThrowsOverLimit(): void
    {
        config(['workflows.v2.structural_limits.search_attribute_size_bytes' => 10]);

        $this->expectException(StructuralLimitExceededException::class);

        StructuralLimits::guardSearchAttributeSize(str_repeat('x', 11));
    }

    // ---------------------------------------------------------------
    //  Command batch size guard
    // ---------------------------------------------------------------

    public function testGuardCommandBatchSizePassesUnderLimit(): void
    {
        config(['workflows.v2.structural_limits.command_batch_size' => 100]);

        StructuralLimits::guardCommandBatchSize(99);

        $this->assertTrue(true);
    }

    public function testGuardCommandBatchSizeThrowsOverLimit(): void
    {
        config(['workflows.v2.structural_limits.command_batch_size' => 5]);

        $this->expectException(StructuralLimitExceededException::class);

        StructuralLimits::guardCommandBatchSize(6);
    }

    // ---------------------------------------------------------------
    //  History transaction size guard
    // ---------------------------------------------------------------

    public function testDefaultHistoryTransactionSizeLimit(): void
    {
        $this->assertSame(5000, StructuralLimits::historyTransactionSizeLimit());
    }

    public function testConfigOverridesHistoryTransactionSizeLimit(): void
    {
        config(['workflows.v2.structural_limits.history_transaction_size' => 200]);

        $this->assertSame(200, StructuralLimits::historyTransactionSizeLimit());
    }

    public function testGuardHistoryTransactionSizePassesUnderLimit(): void
    {
        config(['workflows.v2.structural_limits.history_transaction_size' => 100]);

        StructuralLimits::guardHistoryTransactionSize(99);

        $this->assertTrue(true);
    }

    public function testGuardHistoryTransactionSizeThrowsOverLimit(): void
    {
        config(['workflows.v2.structural_limits.history_transaction_size' => 5]);

        $this->expectException(StructuralLimitExceededException::class);

        StructuralLimits::guardHistoryTransactionSize(6);
    }

    public function testGuardHistoryTransactionSizeSkipsWhenDisabled(): void
    {
        config(['workflows.v2.structural_limits.history_transaction_size' => 0]);

        StructuralLimits::guardHistoryTransactionSize(999999);

        $this->assertTrue(true);
    }

    // ---------------------------------------------------------------
    //  Warning threshold
    // ---------------------------------------------------------------

    public function testDefaultWarningThresholdPercent(): void
    {
        $this->assertSame(80, StructuralLimits::warningThresholdPercent());
    }

    public function testConfigOverridesWarningThreshold(): void
    {
        config(['workflows.v2.structural_limits.warning_threshold_percent' => 90]);

        $this->assertSame(90, StructuralLimits::warningThresholdPercent());
    }

    public function testWarningThresholdClampedToZeroMin(): void
    {
        config(['workflows.v2.structural_limits.warning_threshold_percent' => -10]);

        $this->assertSame(0, StructuralLimits::warningThresholdPercent());
    }

    public function testWarningThresholdClampedTo100Max(): void
    {
        config(['workflows.v2.structural_limits.warning_threshold_percent' => 150]);

        $this->assertSame(100, StructuralLimits::warningThresholdPercent());
    }

    // ---------------------------------------------------------------
    //  checkApproaching
    // ---------------------------------------------------------------

    public function testCheckApproachingReturnsNullBelowThreshold(): void
    {
        config(['workflows.v2.structural_limits.pending_activity_count' => 100]);
        config(['workflows.v2.structural_limits.warning_threshold_percent' => 80]);

        $result = StructuralLimits::checkApproaching(StructuralLimitKind::PendingActivityCount, 79);

        $this->assertNull($result);
    }

    public function testCheckApproachingReturnsWarningAtThreshold(): void
    {
        config(['workflows.v2.structural_limits.pending_activity_count' => 100]);
        config(['workflows.v2.structural_limits.warning_threshold_percent' => 80]);

        $result = StructuralLimits::checkApproaching(StructuralLimitKind::PendingActivityCount, 80);

        $this->assertNotNull($result);
        $this->assertSame('pending_activity_count', $result['limit_kind']);
        $this->assertSame(80, $result['current']);
        $this->assertSame(100, $result['limit']);
        $this->assertSame(80, $result['threshold_percent']);
        $this->assertSame(80, $result['utilization_percent']);
    }

    public function testCheckApproachingReturnsWarningAboveThreshold(): void
    {
        config(['workflows.v2.structural_limits.command_batch_size' => 1000]);
        config(['workflows.v2.structural_limits.warning_threshold_percent' => 80]);

        $result = StructuralLimits::checkApproaching(StructuralLimitKind::CommandBatchSize, 950);

        $this->assertNotNull($result);
        $this->assertSame(95, $result['utilization_percent']);
    }

    public function testCheckApproachingReturnsNullWhenLimitDisabled(): void
    {
        config(['workflows.v2.structural_limits.pending_activity_count' => 0]);
        config(['workflows.v2.structural_limits.warning_threshold_percent' => 80]);

        $result = StructuralLimits::checkApproaching(StructuralLimitKind::PendingActivityCount, 999);

        $this->assertNull($result);
    }

    public function testCheckApproachingReturnsNullWhenThresholdDisabled(): void
    {
        config(['workflows.v2.structural_limits.pending_activity_count' => 100]);
        config(['workflows.v2.structural_limits.warning_threshold_percent' => 0]);

        $result = StructuralLimits::checkApproaching(StructuralLimitKind::PendingActivityCount, 99);

        $this->assertNull($result);
    }

    public function testWarnApproachingHistoryTransactionReturnsWarning(): void
    {
        config(['workflows.v2.structural_limits.history_transaction_size' => 100]);
        config(['workflows.v2.structural_limits.warning_threshold_percent' => 80]);

        $result = StructuralLimits::warnApproachingHistoryTransaction(85);

        $this->assertNotNull($result);
        $this->assertSame('history_transaction_size', $result['limit_kind']);
    }

    public function testWarnApproachingCommandBatchReturnsWarning(): void
    {
        config(['workflows.v2.structural_limits.command_batch_size' => 50]);
        config(['workflows.v2.structural_limits.warning_threshold_percent' => 80]);

        $result = StructuralLimits::warnApproachingCommandBatch(45);

        $this->assertNotNull($result);
        $this->assertSame('command_batch_size', $result['limit_kind']);
    }

    // ---------------------------------------------------------------
    //  limitForKind
    // ---------------------------------------------------------------

    public function testLimitForKindReturnsConfiguredLimit(): void
    {
        config(['workflows.v2.structural_limits.pending_timer_count' => 500]);

        $this->assertSame(500, StructuralLimits::limitForKind(StructuralLimitKind::PendingTimerCount));
    }

    public function testLimitForKindCoversAllKinds(): void
    {
        foreach (StructuralLimitKind::cases() as $kind) {
            $limit = StructuralLimits::limitForKind($kind);
            $this->assertIsInt($limit, "limitForKind should return int for {$kind->value}");
        }
    }

    // ---------------------------------------------------------------
    //  Snapshot
    // ---------------------------------------------------------------

    public function testSnapshotReturnsAllLimits(): void
    {
        $snapshot = StructuralLimits::snapshot();

        $this->assertArrayHasKey('pending_activity_count', $snapshot);
        $this->assertArrayHasKey('pending_child_count', $snapshot);
        $this->assertArrayHasKey('pending_timer_count', $snapshot);
        $this->assertArrayHasKey('pending_signal_count', $snapshot);
        $this->assertArrayHasKey('pending_update_count', $snapshot);
        $this->assertArrayHasKey('command_batch_size', $snapshot);
        $this->assertArrayHasKey('payload_size_bytes', $snapshot);
        $this->assertArrayHasKey('memo_size_bytes', $snapshot);
        $this->assertArrayHasKey('search_attribute_size_bytes', $snapshot);
        $this->assertArrayHasKey('history_transaction_size', $snapshot);
        $this->assertArrayHasKey('warning_threshold_percent', $snapshot);
    }

    // ---------------------------------------------------------------
    //  StructuralLimitKind enum
    // ---------------------------------------------------------------

    public function testEnumContainsAllLimitKinds(): void
    {
        $expected = [
            'payload_size',
            'history_transaction_size',
            'search_attribute_size',
            'memo_size',
            'pending_activity_count',
            'pending_child_count',
            'pending_timer_count',
            'pending_signal_count',
            'pending_update_count',
            'command_batch_size',
        ];

        $actual = array_map(
            static fn (StructuralLimitKind $case): string => $case->value,
            StructuralLimitKind::cases(),
        );

        foreach ($expected as $value) {
            $this->assertContains($value, $actual, "Missing limit kind: {$value}");
        }
    }

    public function testEnumValuesAreStringBacked(): void
    {
        foreach (StructuralLimitKind::cases() as $case) {
            $this->assertSame($case->value, StructuralLimitKind::from($case->value)->value);
        }
    }

    // ---------------------------------------------------------------
    //  Exception metadata
    // ---------------------------------------------------------------

    public function testExceptionCarriesLimitKindAndValues(): void
    {
        $exception = StructuralLimitExceededException::pendingChildCount(150, 100);

        $this->assertSame(StructuralLimitKind::PendingChildCount, $exception->limitKind);
        $this->assertSame(150, $exception->currentValue);
        $this->assertSame(100, $exception->configuredLimit);
    }

    public function testHistoryTransactionSizeExceptionCarriesMetadata(): void
    {
        $exception = StructuralLimitExceededException::historyTransactionSize(600, 500);

        $this->assertSame(StructuralLimitKind::HistoryTransactionSize, $exception->limitKind);
        $this->assertSame(600, $exception->currentValue);
        $this->assertSame(500, $exception->configuredLimit);
    }

    public function testExceptionMessageIsHumanReadable(): void
    {
        $exception = StructuralLimitExceededException::memoSize(300000, 262144);

        $this->assertStringContainsString('300000 bytes', $exception->getMessage());
        $this->assertStringContainsString('limit 262144 bytes', $exception->getMessage());
    }
}
