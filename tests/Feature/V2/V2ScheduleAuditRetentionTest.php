<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\V2\TestScheduledWorkflow;
use Tests\TestCase;
use Workflow\V2\Contracts\ScheduleWorkflowStarter;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\ScheduleOverlapPolicy;
use Workflow\V2\Models\WorkflowSchedule;
use Workflow\V2\Models\WorkflowScheduleHistoryEvent;
use Workflow\V2\Models\WorkflowScheduleOccurrence;
use Workflow\V2\Support\ScheduleAuditRetention;
use Workflow\V2\Support\ScheduleManager;
use Workflow\V2\Support\ScheduleStartResult;

final class V2ScheduleAuditRetentionTest extends TestCase
{
    public function testRepeatedUpdatesKeepCreationAndNewestEventsWithoutReusingSequences(): void
    {
        config()->set('workflows.v2.schedules.audit_history_limit', 4);
        $schedule = $this->schedule('bounded');
        $creation = $schedule->historyEvents()
            ->firstOrFail()
            ->toArray();
        for ($i = 1; $i <= 20; ++$i) {
            ScheduleManager::update($schedule->fresh(), notes: 'revision ' . $i);
            $this->assertLessThanOrEqual(4, $schedule->historyEvents()->count());
        }
        $events = $schedule->historyEvents()
            ->orderBy('sequence')
            ->get();
        $this->assertSame([1, 19, 20, 21], $events->pluck('sequence')->all());
        $this->assertSame($creation, $events->first()->toArray());
        $this->assertSame('revision 20', $events->last()->payload['schedule']['note']);

        config()
            ->set('workflows.v2.schedules.audit_history_limit', 2);
        ScheduleManager::update($schedule->fresh(), notes: 'revision 21');
        $this->assertSame([1, 22], $schedule->historyEvents()->orderBy('sequence')->pluck('sequence')->all());
    }

    public function testExplicitlyDisabledCompactionSupportsAnUpgradeBeforeOldReadersStop(): void
    {
        config()->set('workflows.v2.schedules.audit_history_limit', null);
        $schedule = $this->schedule('upgrade');
        for ($i = 0; $i < 8; ++$i) {
            ScheduleManager::update($schedule->fresh(), notes: 'revision ' . $i);
        }
        $this->assertSame(9, $schedule->historyEvents()->count());
        config()
            ->set('workflows.v2.schedules.audit_history_limit', 3);
        $this->assertSame(6, ScheduleAuditRetention::prune($schedule));
        $this->assertSame([1, 8, 9], $schedule->historyEvents()->orderBy('sequence')->pluck('sequence')->all());
    }

    public function testSnapshotStillRepresentsTheCallerStateWithoutChangingThePersistedOwner(): void
    {
        $schedule = $this->schedule('snapshot', 'orders');
        $persisted = $schedule->note;
        $schedule->note = 'caller snapshot';
        $event = WorkflowScheduleHistoryEvent::record($schedule, HistoryEventType::ScheduleUpdated);
        $this->assertSame('caller snapshot', $event->payload['schedule']['note']);
        $this->assertSame($persisted, $schedule->fresh()->note);
        $this->assertSame('orders', $event->namespace);
    }

    public function testAStaleOrForgedNamespaceCannotCompactAnotherOwner(): void
    {
        $schedule = $this->schedule('owner', 'orders');
        $before = $schedule->historyEvents()
            ->get()
            ->toArray();
        $schedule->namespace = 'other';
        try {
            ScheduleAuditRetention::prune($schedule);
            $this->fail('A conflicting schedule namespace was admitted.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('schedule identity changed', $error->getMessage());
        }
        $this->assertSame($before, $schedule->historyEvents()->get()->toArray());
        $this->assertSame(0, WorkflowScheduleOccurrence::query()->count());
    }

    public function testPrunedTriggerStillPreventsASecondStartForItsOccurrence(): void
    {
        config()->set('workflows.v2.schedules.audit_history_limit', 3);
        $starter = $this->createMock(ScheduleWorkflowStarter::class);
        $starter->expects($this->once())
            ->method('start')
            ->willReturn(new ScheduleStartResult('first-run', null));
        app()
            ->instance(ScheduleWorkflowStarter::class, $starter);
        Carbon::setTestNow(Carbon::parse('2026-11-14T00:59:00Z'));
        try {
            $schedule = $this->schedule('deduplication');
            $due = $schedule->next_fire_at;
            Carbon::setTestNow($due);
            $this->assertSame('triggered', ScheduleManager::tick()[0]['outcome']);
            for ($i = 0; $i < 6; ++$i) {
                ScheduleManager::update($schedule->fresh(), notes: 'revision ' . $i);
            }
            $this->assertFalse(
                $schedule->historyEvents()
                    ->where('event_type', HistoryEventType::ScheduleTriggered->value)->exists()
            );
            $this->assertSame(
                1,
                WorkflowScheduleOccurrence::query()->where('workflow_schedule_id', $schedule->id)->count()
            );
            $answer = ScheduleManager::backfill($schedule->fresh(), $due, $due->copy()->addMinute());
            $this->assertNull($answer[0]['instance_id']);
            $this->assertArrayNotHasKey('error', $answer[0]);
            $this->assertSame(1, (int) $schedule->fresh()->fires_count);
            $this->assertSame('already_fired_occurrence', $schedule->fresh()->last_skip_reason);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function testLegacyTriggerMetadataMovesBeforeItsAuditRowIsDeleted(): void
    {
        config()->set('workflows.v2.schedules.audit_history_limit', null);
        $schedule = $this->schedule('legacy');
        $event = WorkflowScheduleHistoryEvent::record($schedule, HistoryEventType::ScheduleTriggered, [
            'occurrence_time' => '2026-11-13T01:02:03.123456-05:00',
        ]);
        WorkflowScheduleOccurrence::query()->delete();
        $event->forceFill([
            'occurrence_at_utc' => null,
        ])->save();
        for ($i = 0; $i < 4; ++$i) {
            ScheduleManager::update($schedule->fresh(), notes: 'revision ' . $i);
        }
        config()
            ->set('workflows.v2.schedules.audit_history_limit', 3);
        ScheduleAuditRetention::prune($schedule);
        $this->assertModelMissing($event);
        $this->assertSame(
            '2026-11-13 06:02:03.123456',
            WorkflowScheduleOccurrence::query()->firstOrFail()->occurrence_at_utc
        );
        $this->assertSame(3, $schedule->historyEvents()->count());
    }

    public function testAnUnusableLegacyOccurrenceRollsBackTheWholeCompaction(): void
    {
        config()->set('workflows.v2.schedules.audit_history_limit', null);
        $schedule = $this->schedule('malformed');
        WorkflowScheduleHistoryEvent::record($schedule, HistoryEventType::ScheduleTriggered, [
            'occurrence_time' => '2026-11-13T01:02:03Z',
        ]);
        $bad = WorkflowScheduleHistoryEvent::record($schedule, HistoryEventType::ScheduleTriggered, [
            'occurrence_time' => '2026-11-13T02:02:03Z',
        ]);
        WorkflowScheduleOccurrence::query()->delete();
        $bad->forceFill([
            'occurrence_at_utc' => 'malformed',
        ])->save();
        for ($i = 0; $i < 3; ++$i) {
            ScheduleManager::update($schedule->fresh(), notes: 'revision ' . $i);
        }
        $before = $schedule->historyEvents()
            ->orderBy('sequence')
            ->get()
            ->toArray();
        config()
            ->set('workflows.v2.schedules.audit_history_limit', 2);
        try {
            ScheduleAuditRetention::prune($schedule);
            $this->fail('Unusable occurrence metadata was discarded.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('noncanonical schedule occurrence', $error->getMessage());
        }
        $this->assertSame($before, $schedule->historyEvents()->orderBy('sequence')->get()->toArray());
        $this->assertSame(0, WorkflowScheduleOccurrence::query()->count());
    }

    public function testCleanupKeepsSoftDeletedAndOtherNamespaceSchedules(): void
    {
        $kept = $this->schedule('kept', 'orders');
        ScheduleManager::delete($kept);
        $purged = $this->schedule('purged', 'orders');
        WorkflowScheduleHistoryEvent::record($purged, HistoryEventType::ScheduleTriggered, [
            'occurrence_time' => '2026-11-13T01:02:03Z',
        ]);
        $other = $this->schedule('other', 'Orders');
        $other->delete();
        $purged->delete();
        $before = $kept->historyEvents()
            ->count();
        $first = ScheduleAuditRetention::cleanup('orders', 1);
        $this->assertSame(1, $first['audit_events_deleted']);
        $this->assertTrue($first['limit_reached']);
        $rest = ScheduleAuditRetention::cleanup('orders');
        $this->assertSame(1, $rest['audit_events_deleted']);
        $this->assertSame(1, $rest['occurrences_deleted']);
        $this->assertSame($before, $kept->historyEvents()->count());
        $this->assertSame(1, $other->historyEvents()->count());
    }

    public function testApplicationFiltersCannotMakeAPhysicalOwnerAnOrphan(): void
    {
        $schedule = $this->schedule('filtered-owner', 'orders');
        WorkflowScheduleHistoryEvent::record($schedule, HistoryEventType::ScheduleTriggered, [
            'occurrence_time' => '2026-11-13T01:00:00Z',
        ]);
        config()
            ->set('workflows.v2.schedule_model', HiddenScheduleForAuditRetention::class);
        $report = ScheduleAuditRetention::cleanup('orders');
        $this->assertSame(0, $report['audit_events_deleted']);
        $this->assertSame(0, $report['occurrences_deleted']);
        $this->assertSame(2, $schedule->historyEvents()->count());
        $this->assertSame(1, WorkflowScheduleOccurrence::query()->count());
    }

    public function testApplicationFiltersCannotHideACompactedFiringReceipt(): void
    {
        config()->set('workflows.v2.schedules.audit_history_limit', 2);
        $schedule = $this->schedule('filtered-receipt');
        WorkflowScheduleHistoryEvent::record($schedule, HistoryEventType::ScheduleTriggered, [
            'occurrence_time' => '2026-11-13T01:00:00Z',
        ]);
        WorkflowScheduleHistoryEvent::record($schedule, HistoryEventType::ScheduleUpdated);
        $this->assertFalse(
            $schedule->historyEvents()
                ->where('event_type', HistoryEventType::ScheduleTriggered->value)->exists()
        );
        config()
            ->set('workflows.v2.schedule_occurrence_model', HiddenOccurrenceForAuditRetention::class);
        $starter = $this->createStub(ScheduleWorkflowStarter::class);
        $starter->method('start')
            ->willReturn(new ScheduleStartResult('unexpected-duplicate', null));
        app()
            ->instance(ScheduleWorkflowStarter::class, $starter);
        $from = Carbon::parse('2026-11-13T01:00:00Z');
        $answer = ScheduleManager::backfill($schedule, $from, $from->copy()->addMinute());
        $this->assertNull($answer[0]['instance_id']);
        $this->assertArrayNotHasKey('error', $answer[0]);
        $this->assertSame('already_fired_occurrence', $schedule->fresh()->last_skip_reason);
    }

    public function testApplicationFiltersCannotHideAnUncompactedLegacyTrigger(): void
    {
        config()->set('workflows.v2.schedules.audit_history_limit', null);
        $schedule = $this->schedule('filtered-legacy');
        WorkflowScheduleHistoryEvent::record($schedule, HistoryEventType::ScheduleTriggered, [
            'occurrence_time' => '2026-11-13T01:00:00Z',
        ]);
        WorkflowScheduleOccurrence::query()->delete();
        config()
            ->set('workflows.v2.schedule_history_event_model', HiddenScheduleHistoryForAuditRetention::class);
        $starter = $this->createStub(ScheduleWorkflowStarter::class);
        $starter->method('start')
            ->willReturn(new ScheduleStartResult('unexpected-legacy-duplicate', null));
        app()
            ->instance(ScheduleWorkflowStarter::class, $starter);
        $from = Carbon::parse('2026-11-13T01:00:00Z');
        $answer = ScheduleManager::backfill($schedule, $from, $from->copy()->addMinute());
        $this->assertNull($answer[0]['instance_id']);
        $this->assertArrayNotHasKey('error', $answer[0]);
        $this->assertSame('already_fired_occurrence', $schedule->fresh()->last_skip_reason);
    }

    public function testARecreatedScheduleDoesNotInheritThePurgedOwnersFiringReceipt(): void
    {
        $old = $this->schedule('recreated', 'orders');
        WorkflowScheduleHistoryEvent::record($old, HistoryEventType::ScheduleTriggered, [
            'occurrence_time' => '2026-11-13T01:00:00Z',
        ]);
        $old->delete();
        $new = $this->schedule('recreated', 'orders');
        $this->assertNotSame($old->id, $new->id);
        $starter = $this->createMock(ScheduleWorkflowStarter::class);
        $starter->expects($this->once())
            ->method('start')
            ->willReturn(new ScheduleStartResult('new-owner', null));
        app()
            ->instance(ScheduleWorkflowStarter::class, $starter);
        $from = Carbon::parse('2026-11-13T01:00:00Z');
        $answer = ScheduleManager::backfill($new, $from, $from->copy()->addMinute());
        $this->assertSame('new-owner', $answer[0]['instance_id']);
        $this->assertSame(1, (int) $new->fresh()->fires_count);
        $this->assertSame(2, WorkflowScheduleOccurrence::query()->count());
    }

    public function testARefusedCompactionRollsBackTheNewAuditAppendAndItsFiringReceipt(): void
    {
        config()->set('workflows.v2.schedules.audit_history_limit', null);
        $schedule = $this->schedule('append-rollback');
        $bad = WorkflowScheduleHistoryEvent::record($schedule, HistoryEventType::ScheduleTriggered, [
            'occurrence_time' => '2026-11-13T01:00:00Z',
        ]);
        $bad->forceFill([
            'occurrence_at_utc' => 'malformed',
        ])->save();
        WorkflowScheduleOccurrence::query()->delete();
        WorkflowScheduleHistoryEvent::record($schedule, HistoryEventType::ScheduleUpdated);
        $before = $schedule->historyEvents()
            ->orderBy('sequence')
            ->get()
            ->toArray();
        config()
            ->set('workflows.v2.schedules.audit_history_limit', 2);
        try {
            WorkflowScheduleHistoryEvent::record($schedule, HistoryEventType::ScheduleTriggered, [
                'occurrence_time' => '2026-11-13T02:00:00Z',
            ]);
            $this->fail('An audit append escaped the refused compaction transaction.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('noncanonical schedule occurrence', $error->getMessage());
        }
        $this->assertSame($before, $schedule->historyEvents()->orderBy('sequence')->get()->toArray());
        $this->assertSame(0, WorkflowScheduleOccurrence::query()->count());
        config()
            ->set('workflows.v2.schedules.audit_history_limit', null);
        $next = WorkflowScheduleHistoryEvent::record($schedule, HistoryEventType::ScheduleUpdated);
        $this->assertSame(4, $next->sequence);
    }

    public function testLegacyBacklogIsCompactedInBoundedPasses(): void
    {
        config()->set('workflows.v2.schedules.audit_history_limit', null);
        $schedule = $this->schedule('backlog');
        for ($i = 0; $i < 205; ++$i) {
            WorkflowScheduleHistoryEvent::record($schedule, HistoryEventType::ScheduleUpdated);
        }
        config()
            ->set('workflows.v2.schedules.audit_history_limit', 3);
        $this->assertSame(100, ScheduleAuditRetention::prune($schedule));
        $this->assertSame(106, $schedule->historyEvents()->count());
        $this->assertSame(100, ScheduleAuditRetention::prune($schedule));
        $this->assertSame(3, ScheduleAuditRetention::prune($schedule));
        $this->assertSame(0, ScheduleAuditRetention::prune($schedule));
        $this->assertSame([1, 205, 206], $schedule->historyEvents()->orderBy('sequence')->pluck('sequence')->all());
    }

    public function testCleanupCommandUsesAnExactNamespaceAndHonorsItsLimit(): void
    {
        $target = $this->schedule('target', 'orders');
        WorkflowScheduleHistoryEvent::record($target, HistoryEventType::ScheduleUpdated);
        $other = $this->schedule('other-command', 'Orders');
        $target->delete();
        $other->delete();
        $this->artisan('workflow:v2:schedule-audit-prune', [
            '--namespace' => 'orders',
            '--limit' => '1',
        ])
            ->assertExitCode(0)
            ->run();
        $this->assertSame(1, $target->historyEvents()->count());
        $this->assertSame(1, $other->historyEvents()->count());
        $this->artisan('workflow:v2:schedule-audit-prune', [
            '--namespace' => 'orders',
        ])
            ->assertExitCode(0)
            ->run();
        $this->assertSame(0, $target->historyEvents()->count());
        $this->assertSame(1, $other->historyEvents()->count());
    }

    public function testCommandCanCompactAnIdleScheduleAndSelectLegacyNullRowsSeparately(): void
    {
        config()->set('workflows.v2.schedules.audit_history_limit', null);
        $schedule = $this->schedule('idle', 'orders');
        for ($i = 0; $i < 5; ++$i) {
            WorkflowScheduleHistoryEvent::record($schedule, HistoryEventType::ScheduleUpdated);
        }
        config()
            ->set('workflows.v2.schedules.audit_history_limit', 2);
        $this->artisan('workflow:v2:schedule-audit-prune', [
            '--namespace' => 'orders',
            '--schedule' => 'idle',
        ])
            ->assertExitCode(0)
            ->run();
        $this->assertSame([1, 6], $schedule->historyEvents()->orderBy('sequence')->pluck('sequence')->all());
        $schedule->historyEvents()
            ->update([
                'namespace' => null,
            ]);
        $schedule->delete();
        $this->artisan('workflow:v2:schedule-audit-prune', [
            '--namespace' => 'orders',
        ])
            ->assertExitCode(0)
            ->run();
        $this->assertSame(2, $schedule->historyEvents()->count());
        $this->artisan('workflow:v2:schedule-audit-prune', [
            '--null-namespace' => true,
        ])
            ->assertExitCode(0)
            ->run();
        $this->assertSame(0, $schedule->historyEvents()->count());
    }

    public function testInvalidLimitsAndConflictingNamespaceOptionsRefuseDeletion(): void
    {
        $schedule = $this->schedule('invalid', 'orders');
        $schedule->delete();
        foreach (['0', '1001', 'invalid'] as $limit) {
            $this->artisan('workflow:v2:schedule-audit-prune', [
                '--namespace' => 'orders',
                '--limit' => $limit,
            ])
                ->assertExitCode(1)
                ->run();
        }
        $this->artisan('workflow:v2:schedule-audit-prune', [
            '--namespace' => 'orders',
            '--null-namespace' => true,
        ])
            ->assertExitCode(1)
            ->run();
        $this->assertSame(1, $schedule->historyEvents()->count());
        config()
            ->set('workflows.v2.schedules.audit_history_limit', 1);
        $this->expectException(InvalidArgumentException::class);
        ScheduleAuditRetention::limit();
    }

    public function testCommandReportsPhysicalRemainingRowsDespiteAnApplicationFilter(): void
    {
        config()->set('workflows.v2.schedules.audit_history_limit', null);
        $schedule = $this->schedule('filtered-report', 'orders');
        for ($i = 0; $i < 205; ++$i) {
            WorkflowScheduleHistoryEvent::record($schedule, HistoryEventType::ScheduleUpdated);
        }
        config()
            ->set('workflows.v2.schedule_history_event_model', HiddenScheduleHistoryForAuditRetention::class);
        config()
            ->set('workflows.v2.schedules.audit_history_limit', 3);
        foreach ([[100, 106], [100, 6], [3, 3]] as [$deleted, $remaining]) {
            $this->assertSame(0, Artisan::call('workflow:v2:schedule-audit-prune', [
                '--namespace' => 'orders',
                '--schedule' => 'filtered-report',
            ]));
            $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($deleted, $report['audit_events_deleted']);
            $this->assertSame($remaining, $report['retained_events']);
            $this->assertSame(3, $report['configured_limit']);
            $this->assertSame($remaining, WorkflowScheduleHistoryEvent::query()
                ->where('workflow_schedule_id', $schedule->id)
                ->count());
        }
        $this->assertSame([1, 205, 206], WorkflowScheduleHistoryEvent::query()
            ->where('workflow_schedule_id', $schedule->id)
            ->orderBy('sequence')
            ->pluck('sequence')
            ->all());
    }

    public function testCanonicalEnvironmentLimitIsParsedAndOverflowRefuses(): void
    {
        config()->set('workflows.v2.schedules.audit_history_limit', '3');
        $this->assertSame(3, ScheduleAuditRetention::limit());
        config()
            ->set('workflows.v2.schedules.audit_history_limit', (string) PHP_INT_MAX);
        $this->assertSame(PHP_INT_MAX, ScheduleAuditRetention::limit());
        config()
            ->set('workflows.v2.schedules.audit_history_limit', PHP_INT_MAX . '0');
        $this->expectException(InvalidArgumentException::class);
        ScheduleAuditRetention::limit();
    }

    public function testCompactingAManualTriggerDoesNotInventAnOccurrenceReceipt(): void
    {
        config()->set('workflows.v2.schedules.audit_history_limit', null);
        $schedule = $this->schedule('manual');
        $manual = WorkflowScheduleHistoryEvent::record($schedule, HistoryEventType::ScheduleTriggered);
        WorkflowScheduleHistoryEvent::record($schedule, HistoryEventType::ScheduleUpdated);
        config()
            ->set('workflows.v2.schedules.audit_history_limit', 2);
        $this->assertSame(1, ScheduleAuditRetention::prune($schedule));
        $this->assertModelMissing($manual);
        $this->assertSame(0, WorkflowScheduleOccurrence::query()->count());
        $this->assertSame([1, 3], $schedule->historyEvents()->orderBy('sequence')->pluck('sequence')->all());
    }

    public static function unusableLegacyClocks(): array
    {
        return [
            'non-string clock' => [123],
            'unsupported clock syntax' => ['not-a-clock'],
            'invalid calendar day' => ['2026-02-30T01:02:03Z'],
        ];
    }

    #[DataProvider('unusableLegacyClocks')]
    public function testUnusableLegacyPayloadRefusesBothCompactionAndTheCommand(mixed $clock): void
    {
        config()->set('workflows.v2.schedules.audit_history_limit', null);
        $schedule = $this->schedule('legacy-refusal', 'orders');
        $legacy = WorkflowScheduleHistoryEvent::record($schedule, HistoryEventType::ScheduleTriggered, [
            'occurrence_time' => '2026-11-13T01:02:03Z',
        ]);
        $payload = $legacy->payload;
        $payload['occurrence_time'] = $clock;
        $legacy->forceFill([
            'occurrence_at_utc' => null,
            'payload' => $payload,
        ])->save();
        WorkflowScheduleOccurrence::query()->delete();
        WorkflowScheduleHistoryEvent::record($schedule, HistoryEventType::ScheduleUpdated);
        $before = $schedule->historyEvents()
            ->orderBy('sequence')
            ->get()
            ->toArray();
        config()
            ->set('workflows.v2.schedules.audit_history_limit', 2);
        try {
            ScheduleAuditRetention::prune($schedule);
            $this->fail('Unusable legacy payload was discarded.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('legacy schedule occurrence cannot be established', $error->getMessage());
        }
        $this->assertSame($before, $schedule->historyEvents()->orderBy('sequence')->get()->toArray());
        $this->assertSame(1, Artisan::call('workflow:v2:schedule-audit-prune', [
            '--namespace' => 'orders',
            '--schedule' => 'legacy-refusal',
        ]));
        $this->assertStringContainsString('audit row was retained', Artisan::output());
        $this->assertSame($before, $schedule->historyEvents()->orderBy('sequence')->get()->toArray());
        $this->assertSame(0, WorkflowScheduleOccurrence::query()->count());
    }

    public function testAConflictingReceiptNamespaceRollsBackTheAuditAppend(): void
    {
        $schedule = $this->schedule('conflicting-receipt', 'orders');
        $receipt = WorkflowScheduleOccurrence::query()->create([
            'workflow_schedule_id' => $schedule->id,
            'namespace' => 'other',
            'occurrence_at_utc' => '2026-11-13 01:02:03.000000',
        ]);
        $before = $schedule->historyEvents()
            ->get()
            ->toArray();
        try {
            WorkflowScheduleHistoryEvent::record($schedule, HistoryEventType::ScheduleTriggered, [
                'occurrence_time' => '2026-11-13T01:02:03Z',
            ]);
            $this->fail('An occurrence receipt with another namespace was reused.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('conflicting namespace ownership', $error->getMessage());
        }
        $this->assertSame($before, $schedule->historyEvents()->get()->toArray());
        $this->assertSame('other', $receipt->fresh()->namespace);
        $this->assertSame(1, WorkflowScheduleOccurrence::query()->count());
    }

    public function testTheCleanupApiRejectsOutOfRangeLimitsWithoutDeletingOrphans(): void
    {
        $schedule = $this->schedule('invalid-api-limit', 'orders');
        $schedule->delete();
        foreach ([0, 1001] as $limit) {
            try {
                ScheduleAuditRetention::cleanup('orders', $limit);
                $this->fail('An invalid cleanup limit was accepted.');
            } catch (InvalidArgumentException $error) {
                $this->assertStringContainsString('between 1 and 1000', $error->getMessage());
            }
            $this->assertSame(1, $schedule->historyEvents()->count());
        }
    }

    public static function invalidCommandNamespaces(): array
    {
        return [
            'empty' => [''],
            'too long' => [str_repeat('x', 256)],
            'invalid UTF-8' => ["\xff"],
        ];
    }

    #[DataProvider('invalidCommandNamespaces')]
    public function testAnInvalidCommandNamespaceCannotDeleteRows(string $namespace): void
    {
        $schedule = $this->schedule('invalid-namespace', 'orders');
        $schedule->delete();
        $this->assertSame(1, Artisan::call('workflow:v2:schedule-audit-prune', [
            '--namespace' => $namespace,
        ]));
        $this->assertStringContainsString('namespace must be a nonempty UTF-8 string', Artisan::output());
        $this->assertSame(1, $schedule->historyEvents()->count());
    }

    public function testARequestedScheduleMustExistWithItsExactIdInTheSelectedNamespace(): void
    {
        $schedule = $this->schedule('exact-schedule', 'orders');
        $before = $schedule->historyEvents()
            ->get()
            ->toArray();
        foreach ([['other', 'exact-schedule'],
            ['orders', 'missing'],
            ['orders', 'EXACT-SCHEDULE'],
        ] as [$namespace, $id]) {
            $this->assertSame(1, Artisan::call('workflow:v2:schedule-audit-prune', [
                '--namespace' => $namespace,
                '--schedule' => $id,
            ]));
            $this->assertStringContainsString('exact schedule ID does not exist', Artisan::output());
            $this->assertSame($before, $schedule->historyEvents()->get()->toArray());
        }
    }

    public function testTheOccurrenceMigrationIsIdempotentWithoutDiscardingReceipts(): void
    {
        $schedule = $this->schedule('migration-repeat');
        WorkflowScheduleHistoryEvent::record($schedule, HistoryEventType::ScheduleTriggered, [
            'occurrence_time' => '2026-11-13T01:02:03Z',
        ]);
        $before = WorkflowScheduleOccurrence::query()->get()->toArray();
        $migration = require __DIR__ . '/../../../src/migrations/2026_11_14_000200_create_workflow_schedule_occurrences.php';
        $migration->up();
        $this->assertSame($before, WorkflowScheduleOccurrence::query()->get()->toArray());
    }

    public function testAnEmptyOccurrenceMigrationCanRollBackAndRepeatWithoutAnExistingTable(): void
    {
        $migration = require __DIR__ . '/../../../src/migrations/2026_11_14_000200_create_workflow_schedule_occurrences.php';
        $this->assertSame(0, WorkflowScheduleOccurrence::query()->count());
        try {
            $migration->down();
            $this->assertFalse(Schema::hasTable('workflow_schedule_occurrences'));
            $migration->down();
            $this->assertFalse(Schema::hasTable('workflow_schedule_occurrences'));
        } finally {
            $migration->up();
        }
        $this->assertTrue(Schema::hasTable('workflow_schedule_occurrences'));
        $this->assertSame(0, WorkflowScheduleOccurrence::query()->count());
    }

    public function testMigrationCannotDiscardPopulatedOccurrenceReceipts(): void
    {
        $schedule = $this->schedule('rollback');
        WorkflowScheduleHistoryEvent::record($schedule, HistoryEventType::ScheduleTriggered, [
            'occurrence_time' => '2026-11-13T01:02:03Z',
        ]);
        $migration = require __DIR__ . '/../../../src/migrations/2026_11_14_000200_create_workflow_schedule_occurrences.php';
        try {
            $migration->down();
            $this->fail('The deduplication receipt table was dropped.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('receipts must be preserved', $error->getMessage());
        }
        $this->assertTrue(Schema::hasTable('workflow_schedule_occurrences'));
        $this->assertSame(1, WorkflowScheduleOccurrence::query()->count());
    }

    private function schedule(string $id, ?string $namespace = null): WorkflowSchedule
    {
        return ScheduleManager::create(
            scheduleId: $id,
            workflowClass: TestScheduledWorkflow::class,
            cronExpression: '* * * * *',
            overlapPolicy: ScheduleOverlapPolicy::AllowAll,
            namespace: $namespace,
        );
    }
}

final class HiddenScheduleForAuditRetention extends WorkflowSchedule
{
    protected static function booted(): void
    {
        static::addGlobalScope('application-filter', static function (Builder $query): void {
            $query->whereRaw('1=0');
        });
    }
}

final class HiddenOccurrenceForAuditRetention extends WorkflowScheduleOccurrence
{
    protected static function booted(): void
    {
        static::addGlobalScope('application-filter', static function (Builder $query): void {
            $query->whereRaw('1=0');
        });
    }
}

final class HiddenScheduleHistoryForAuditRetention extends WorkflowScheduleHistoryEvent
{
    protected static function booted(): void
    {
        static::addGlobalScope('application-filter', static function (Builder $query): void {
            $query->whereRaw('1=0');
        });
    }
}
