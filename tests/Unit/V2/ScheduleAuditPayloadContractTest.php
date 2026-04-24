<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Support\HistoryEventPayloadContract;

/**
 * Pins the schedule lifecycle audit payload contract published in the
 * public schedules documentation (docs/features/schedules.md on
 * durable-workflow.github.io). Schedule audit events appear on two
 * streams:
 *
 * - The started workflow run's history (`workflow_history_events`) for
 *   `ScheduleTriggered`, so a run can trace back to the schedule that
 *   started it.
 * - The schedule's own audit log (`workflow_schedule_history_events`)
 *   for every lifecycle transition.
 *
 * Both streams share the same `HistoryEventPayloadContract` entries
 * and reject undocumented payload keys at write time.
 *
 * A change to any of the expected key lists below is a wire-format
 * change: update this test and the public schedules docs in the same
 * commit so drift gets reviewed deliberately.
 */
final class ScheduleAuditPayloadContractTest extends TestCase
{
    /**
     * @return array<string, array{0: HistoryEventType, 1: list<string>}>
     */
    public static function scheduleEvents(): array
    {
        return [
            'ScheduleCreated' => [
                HistoryEventType::ScheduleCreated,
                ['spec', 'action', 'overlap_policy', 'next_fire_at', 'command_context'],
            ],
            'SchedulePaused' => [HistoryEventType::SchedulePaused, ['reason', 'paused_at', 'command_context']],
            'ScheduleResumed' => [HistoryEventType::ScheduleResumed, ['next_fire_at', 'command_context']],
            'ScheduleUpdated' => [
                HistoryEventType::ScheduleUpdated,
                ['changed_fields', 'spec', 'action', 'overlap_policy', 'next_fire_at', 'command_context'],
            ],
            'ScheduleTriggered' => [
                HistoryEventType::ScheduleTriggered,
                [
                    'workflow_instance_id',
                    'workflow_run_id',
                    'schedule_id',
                    'schedule_ulid',
                    'cron_expression',
                    'timezone',
                    'overlap_policy',
                    'outcome',
                    'effective_overlap_policy',
                    'trigger_number',
                    'occurrence_time',
                    'command_context',
                ],
            ],
            'ScheduleTriggerSkipped' => [
                HistoryEventType::ScheduleTriggerSkipped,
                ['reason', 'skipped_trigger_count', 'last_skipped_at', 'command_context'],
            ],
            'ScheduleDeleted' => [HistoryEventType::ScheduleDeleted, ['reason', 'deleted_at', 'command_context']],
        ];
    }

    /**
     * @dataProvider scheduleEvents
     * @param  list<string>  $expected
     */
    public function testPayloadContractExposesExpectedKeys(HistoryEventType $eventType, array $expected): void
    {
        $registry = HistoryEventPayloadContract::payloadKeys();

        $this->assertArrayHasKey(
            $eventType->value,
            $registry,
            sprintf('%s must be registered in HistoryEventPayloadContract.', $eventType->value),
        );

        $this->assertSame(
            $expected,
            $registry[$eventType->value],
            sprintf(
                'Payload keys for %s must match the schedules documentation. '
                . 'If this test fails, update docs/features/schedules.md on the docs site in the same change.',
                $eventType->value,
            ),
        );
    }

    /**
     * @dataProvider scheduleEvents
     * @param  list<string>  $expected
     */
    public function testAssertKnownPayloadKeysAcceptsEveryDocumentedKey(
        HistoryEventType $eventType,
        array $expected
    ): void {
        $payload = array_fill_keys($expected, 'value');

        // Expect no exception when every documented key is present.
        HistoryEventPayloadContract::assertKnownPayloadKeys($eventType, $payload);

        $this->addToAssertionCount(1);
    }

    /**
     * @dataProvider scheduleEvents
     * @param  list<string>  $expected
     */
    public function testAssertKnownPayloadKeysRejectsUndocumentedKeys(
        HistoryEventType $eventType,
        array $expected
    ): void {
        $payload = array_fill_keys($expected, 'value');
        $payload['undocumented_key'] = 'value';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('undocumented_key');

        HistoryEventPayloadContract::assertKnownPayloadKeys($eventType, $payload);
    }

    public function testCoversEveryScheduleEventType(): void
    {
        $scheduleEventTypes = array_filter(
            HistoryEventType::cases(),
            static fn (HistoryEventType $event): bool => str_starts_with($event->value, 'Schedule'),
        );

        $covered = array_map(
            static fn (array $row): string => $row[0]->value,
            array_values(self::scheduleEvents()),
        );

        sort($covered);

        $expected = array_map(static fn (HistoryEventType $event): string => $event->value, $scheduleEventTypes);
        sort($expected);

        $this->assertSame(
            $expected,
            $covered,
            'Every Schedule* history event must be covered by this contract test.',
        );
    }
}
