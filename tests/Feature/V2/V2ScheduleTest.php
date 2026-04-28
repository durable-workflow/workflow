<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Http\Request;
use LogicException;
use Tests\Fixtures\V2\TestScheduledWorkflow;
use Tests\TestCase;
use Workflow\V2\CommandContext;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\ScheduleOverlapPolicy;
use Workflow\V2\Enums\ScheduleStatus;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowSchedule;
use Workflow\V2\Models\WorkflowScheduleHistoryEvent;
use Workflow\V2\Support\ScheduleDescription;
use Workflow\V2\Support\ScheduleManager;
use Workflow\V2\Support\WorkerCompatibilityFleet;
use Workflow\V2\WorkflowStub;

final class V2ScheduleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()
            ->set('queue.default', 'sync');
        config()
            ->set('queue.connections.sync.driver', 'sync');
    }

    public function testCreateSchedule(): void
    {
        $schedule = ScheduleManager::create(
            scheduleId: 'daily-invoice-sync',
            workflowClass: TestScheduledWorkflow::class,
            cronExpression: '0 2 * * *',
            arguments: ['nightly'],
            timezone: 'America/New_York',
            notes: 'Runs every night at 2 AM ET.',
        );

        $this->assertInstanceOf(WorkflowSchedule::class, $schedule);
        $this->assertSame('daily-invoice-sync', $schedule->schedule_id);
        $this->assertSame(TestScheduledWorkflow::class, $schedule->workflow_class);
        $this->assertSame('test-scheduled-workflow', $schedule->workflow_type);
        $this->assertSame('0 2 * * *', $schedule->cron_expression);
        $this->assertSame('America/New_York', $schedule->timezone);
        $this->assertSame(ScheduleStatus::Active, $schedule->status);
        $this->assertSame('skip', $schedule->overlap_policy);
        $this->assertSame(0, (int) $schedule->fires_count);
        $this->assertNotNull($schedule->next_fire_at);
        $this->assertSame('Runs every night at 2 AM ET.', $schedule->note);
    }

    public function testCreateScheduleWithInvalidCronThrows(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Invalid cron expression');

        ScheduleManager::create(
            scheduleId: 'bad-cron',
            workflowClass: TestScheduledWorkflow::class,
            cronExpression: 'not a cron',
        );
    }

    public function testDuplicateScheduleIdRejected(): void
    {
        ScheduleManager::create(
            scheduleId: 'unique-schedule',
            workflowClass: TestScheduledWorkflow::class,
            cronExpression: '*/5 * * * *',
        );

        $this->expectException(\Illuminate\Database\QueryException::class);

        ScheduleManager::create(
            scheduleId: 'unique-schedule',
            workflowClass: TestScheduledWorkflow::class,
            cronExpression: '*/10 * * * *',
        );
    }

    public function testPauseAndResumeSchedule(): void
    {
        $schedule = ScheduleManager::create(
            scheduleId: 'pause-test',
            workflowClass: TestScheduledWorkflow::class,
            cronExpression: '0 * * * *',
        );

        $this->assertSame(ScheduleStatus::Active, $schedule->status);

        $paused = ScheduleManager::pause($schedule);
        $this->assertSame(ScheduleStatus::Paused, $paused->status);
        $this->assertNotNull($paused->paused_at);

        $resumed = ScheduleManager::resume($paused);
        $this->assertSame(ScheduleStatus::Active, $resumed->status);
        $this->assertNull($resumed->paused_at);
        $this->assertNotNull($resumed->next_fire_at);
    }

    public function testResumeNonPausedThrows(): void
    {
        $schedule = ScheduleManager::create(
            scheduleId: 'not-paused',
            workflowClass: TestScheduledWorkflow::class,
            cronExpression: '0 * * * *',
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('is not paused');

        ScheduleManager::resume($schedule);
    }

    public function testDeleteSchedule(): void
    {
        $schedule = ScheduleManager::create(
            scheduleId: 'delete-test',
            workflowClass: TestScheduledWorkflow::class,
            cronExpression: '0 * * * *',
        );

        $deleted = ScheduleManager::delete($schedule);
        $this->assertSame(ScheduleStatus::Deleted, $deleted->status);
        $this->assertNotNull($deleted->deleted_at);
        $this->assertNull($deleted->next_fire_at);
    }

    public function testPauseDeletedScheduleThrows(): void
    {
        $schedule = ScheduleManager::create(
            scheduleId: 'delete-then-pause',
            workflowClass: TestScheduledWorkflow::class,
            cronExpression: '0 * * * *',
        );

        ScheduleManager::delete($schedule);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Cannot pause deleted schedule');

        ScheduleManager::pause($schedule);
    }

    public function testUpdateScheduleCronAndTimezone(): void
    {
        $schedule = ScheduleManager::create(
            scheduleId: 'update-test',
            workflowClass: TestScheduledWorkflow::class,
            cronExpression: '0 * * * *',
            timezone: 'UTC',
        );

        $originalNextRun = $schedule->next_fire_at;

        $updated = ScheduleManager::update(
            $schedule,
            cronExpression: '30 2 * * *',
            timezone: 'America/Chicago',
            notes: 'Updated to 2:30 AM CT.',
        );

        $this->assertSame('30 2 * * *', $updated->cron_expression);
        $this->assertSame('America/Chicago', $updated->timezone);
        $this->assertSame('Updated to 2:30 AM CT.', $updated->note);
        $this->assertNotEquals($originalNextRun, $updated->next_fire_at);
    }

    public function testTriggerCreatesWorkflowRun(): void
    {
        WorkflowStub::fake();

        $schedule = ScheduleManager::create(
            scheduleId: 'trigger-test',
            workflowClass: TestScheduledWorkflow::class,
            cronExpression: '* * * * *',
            arguments: ['triggered-batch'],
        );

        $instanceId = ScheduleManager::trigger($schedule);

        $this->assertNotNull($instanceId);
        $schedule->refresh();
        $this->assertSame(1, (int) $schedule->fires_count);
        $this->assertNotNull($schedule->last_fired_at);
        $this->assertSame($instanceId, $schedule->latest_workflow_instance_id);
    }

    public function testTriggerDetailedSkipsCompatibilityBlockedStart(): void
    {
        WorkerCompatibilityFleet::clear();

        config()->set('queue.default', 'redis');
        config()->set('queue.connections.redis.driver', 'redis');
        config()->set('workflows.v2.compatibility.current', 'build-a');
        config()->set('workflows.v2.compatibility.supported', ['build-a']);
        config()->set('workflows.v2.compatibility.namespace', null);
        config()->set('workflows.v2.fleet.validation_mode', 'fail');

        WorkerCompatibilityFleet::record(['build-b'], 'redis', 'default', 'worker-build-b');

        $schedule = ScheduleManager::create(
            scheduleId: 'trigger-compatibility-blocked',
            workflowClass: TestScheduledWorkflow::class,
            cronExpression: '* * * * *',
            connection: 'redis',
            queue: 'default',
        );

        $result = ScheduleManager::triggerDetailed($schedule);

        $this->assertSame('skipped', $result->outcome);
        $this->assertNull($result->instanceId);
        $this->assertNull($result->runId);
        $this->assertSame('compatibility_blocked', $result->reason);

        $schedule->refresh();
        $this->assertSame(0, (int) $schedule->fires_count);
        $this->assertSame(0, (int) $schedule->failures_count);
        $this->assertSame('compatibility_blocked', $schedule->last_skip_reason);
        $this->assertSame(0, WorkflowRun::query()->count());
    }

    public function testTriggerPausedScheduleReturnsNull(): void
    {
        $schedule = ScheduleManager::create(
            scheduleId: 'paused-trigger',
            workflowClass: TestScheduledWorkflow::class,
            cronExpression: '* * * * *',
        );

        ScheduleManager::pause($schedule);
        $instanceId = ScheduleManager::trigger($schedule);

        $this->assertNull($instanceId);
    }

    public function testTriggerDeletedScheduleReturnsNull(): void
    {
        $schedule = ScheduleManager::create(
            scheduleId: 'deleted-trigger',
            workflowClass: TestScheduledWorkflow::class,
            cronExpression: '* * * * *',
        );

        ScheduleManager::delete($schedule);
        $instanceId = ScheduleManager::trigger($schedule);

        $this->assertNull($instanceId);
    }

    public function testTickProcessesDueSchedules(): void
    {
        WorkflowStub::fake();

        $schedule = ScheduleManager::create(
            scheduleId: 'tick-test',
            workflowClass: TestScheduledWorkflow::class,
            cronExpression: '* * * * *',
        );

        $schedule->forceFill([
            'next_fire_at' => now()
                ->subMinute(),
        ])->save();

        $results = ScheduleManager::tick();

        $this->assertCount(1, $results);
        $this->assertSame('tick-test', $results[0]['schedule_id']);
        $this->assertNotNull($results[0]['instance_id']);
    }

    public function testTickSkipsFutureSchedules(): void
    {
        ScheduleManager::create(
            scheduleId: 'future-tick',
            workflowClass: TestScheduledWorkflow::class,
            cronExpression: '0 0 1 1 *',
        );

        $results = ScheduleManager::tick();
        $this->assertCount(0, $results);
    }

    public function testSkipOverlapPolicyPreventsOverlappingRuns(): void
    {
        WorkflowStub::fake();

        $schedule = ScheduleManager::create(
            scheduleId: 'overlap-skip-test',
            workflowClass: TestScheduledWorkflow::class,
            cronExpression: '* * * * *',
            overlapPolicy: ScheduleOverlapPolicy::Skip,
        );

        $firstInstanceId = ScheduleManager::trigger($schedule);
        $this->assertNotNull($firstInstanceId);

        $run = WorkflowRun::query()->find(WorkflowStub::load($firstInstanceId)->runId());
        $run->forceFill([
            'status' => 'running',
        ])->save();

        $schedule->refresh();
        $schedule->forceFill([
            'next_fire_at' => now()
                ->subMinute(),
        ])->save();

        $secondInstanceId = ScheduleManager::trigger($schedule);
        $this->assertNull($secondInstanceId, 'Skip policy should prevent overlapping runs.');

        $schedule->refresh();
        $this->assertSame(1, (int) $schedule->fires_count);
    }

    public function testAllowAllOverlapPolicyPermitsOverlappingRuns(): void
    {
        WorkflowStub::fake();

        $schedule = ScheduleManager::create(
            scheduleId: 'overlap-all-test',
            workflowClass: TestScheduledWorkflow::class,
            cronExpression: '* * * * *',
            overlapPolicy: ScheduleOverlapPolicy::AllowAll,
        );

        $firstInstanceId = ScheduleManager::trigger($schedule);
        $this->assertNotNull($firstInstanceId);

        $schedule->refresh();
        $secondInstanceId = ScheduleManager::trigger($schedule);
        $this->assertNotNull($secondInstanceId);

        $schedule->refresh();
        $this->assertSame(2, (int) $schedule->fires_count);
    }

    public function testMaxRunsEnforcementDeletesSchedule(): void
    {
        WorkflowStub::fake();

        $schedule = ScheduleManager::create(
            scheduleId: 'max-runs-test',
            workflowClass: TestScheduledWorkflow::class,
            cronExpression: '* * * * *',
            maxRuns: 2,
            overlapPolicy: ScheduleOverlapPolicy::AllowAll,
        );

        $this->assertSame(2, (int) $schedule->remaining_actions);

        ScheduleManager::trigger($schedule);
        $schedule->refresh();
        $this->assertSame(1, (int) $schedule->remaining_actions);
        $this->assertSame(ScheduleStatus::Active, $schedule->status);

        ScheduleManager::trigger($schedule);
        $schedule->refresh();
        $this->assertSame(0, (int) $schedule->remaining_actions);
        $this->assertSame(ScheduleStatus::Deleted, $schedule->status);
        $this->assertNull($schedule->next_fire_at);
    }

    public function testDescribeReturnsScheduleDescription(): void
    {
        $schedule = ScheduleManager::create(
            scheduleId: 'describe-test',
            workflowClass: TestScheduledWorkflow::class,
            cronExpression: '0 6 * * 1-5',
            timezone: 'Europe/London',
            overlapPolicy: ScheduleOverlapPolicy::BufferOne,
            jitterSeconds: 30,
            notes: 'Weekday 6 AM London.',
        );

        $description = ScheduleManager::describe($schedule);

        $this->assertInstanceOf(ScheduleDescription::class, $description);
        $this->assertSame('describe-test', $description->scheduleId);
        $this->assertSame(['0 6 * * 1-5'], $description->spec['cron_expressions']);
        $this->assertSame('Europe/London', $description->spec['timezone']);
        $this->assertSame('test-scheduled-workflow', $description->action['workflow_type']);
        $this->assertSame(TestScheduledWorkflow::class, $description->action['workflow_class']);
        $this->assertSame(ScheduleStatus::Active, $description->status);
        $this->assertSame(ScheduleOverlapPolicy::BufferOne, $description->overlapPolicy);
        $this->assertSame(30, $description->jitterSeconds);
        $this->assertSame('Weekday 6 AM London.', $description->note);

        $array = $description->toArray();
        $this->assertSame('describe-test', $array['schedule_id']);
        $this->assertSame('active', $array['status']);
        $this->assertSame('buffer_one', $array['overlap_policy']);
    }

    public function testFindByScheduleId(): void
    {
        ScheduleManager::create(
            scheduleId: 'find-test',
            workflowClass: TestScheduledWorkflow::class,
            cronExpression: '0 * * * *',
        );

        $found = ScheduleManager::findByScheduleId('find-test');
        $this->assertNotNull($found);
        $this->assertSame('find-test', $found->schedule_id);

        $missing = ScheduleManager::findByScheduleId('does-not-exist');
        $this->assertNull($missing);
    }

    public function testScheduleWithConnectionAndQueue(): void
    {
        $schedule = ScheduleManager::create(
            scheduleId: 'routing-test',
            workflowClass: TestScheduledWorkflow::class,
            cronExpression: '*/15 * * * *',
            connection: 'redis',
            queue: 'high-priority',
        );

        $this->assertSame('redis', $schedule->connection);
        $this->assertSame('high-priority', $schedule->queue);
    }

    public function testScheduleWithVisibilityLabelsAndMemo(): void
    {
        $schedule = ScheduleManager::create(
            scheduleId: 'metadata-test',
            workflowClass: TestScheduledWorkflow::class,
            cronExpression: '0 * * * *',
            labels: [
                'env' => 'production',
                'team' => 'billing',
            ],
            memo: [
                'origin' => 'scheduled',
            ],
            searchAttributes: [
                'tenant_id' => '42',
            ],
        );

        $this->assertSame([
            'env' => 'production',
            'team' => 'billing',
        ], $schedule->visibility_labels);
        $this->assertSame([
            'origin' => 'scheduled',
        ], $schedule->memo);
        $this->assertSame([
            'tenant_id' => '42',
        ], $schedule->search_attributes);
    }

    public function testUpdateDeletedScheduleThrows(): void
    {
        $schedule = ScheduleManager::create(
            scheduleId: 'delete-then-update',
            workflowClass: TestScheduledWorkflow::class,
            cronExpression: '0 * * * *',
        );

        ScheduleManager::delete($schedule);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Cannot update deleted schedule');

        ScheduleManager::update($schedule, cronExpression: '30 * * * *');
    }

    public function testDeleteIdempotent(): void
    {
        $schedule = ScheduleManager::create(
            scheduleId: 'double-delete',
            workflowClass: TestScheduledWorkflow::class,
            cronExpression: '0 * * * *',
        );

        ScheduleManager::delete($schedule);
        $again = ScheduleManager::delete($schedule);

        $this->assertSame(ScheduleStatus::Deleted, $again->status);
    }

    public function testTriggerRecordsScheduleTriggeredHistoryEvent(): void
    {
        WorkflowStub::fake();

        $schedule = ScheduleManager::create(
            scheduleId: 'event-test',
            workflowClass: TestScheduledWorkflow::class,
            cronExpression: '* * * * *',
        );

        $instanceId = ScheduleManager::trigger($schedule);
        $this->assertNotNull($instanceId);

        $schedule->refresh();
        $runId = null;

        $stub = WorkflowStub::load($instanceId);
        $runId = $stub->runId();

        $event = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->where('event_type', HistoryEventType::ScheduleTriggered->value)
            ->first();

        $this->assertNotNull($event, 'ScheduleTriggered history event should be recorded on the started run.');
        $this->assertSame('event-test', $event->payload['schedule_id']);
        $this->assertSame($schedule->id, $event->payload['schedule_ulid']);
        $this->assertSame('* * * * *', $event->payload['cron_expression']);
        $this->assertSame(1, $event->payload['trigger_number']);
    }

    public function testScheduleLifecycleRecordsScheduleLevelHistoryEvents(): void
    {
        WorkflowStub::fake();

        $schedule = ScheduleManager::create(
            scheduleId: 'schedule-audit-test',
            workflowClass: TestScheduledWorkflow::class,
            cronExpression: '* * * * *',
            overlapPolicy: ScheduleOverlapPolicy::AllowAll,
        );

        ScheduleManager::pause($schedule, 'operator hold');
        $schedule->refresh();

        ScheduleManager::resume($schedule);
        $schedule->refresh();

        ScheduleManager::update($schedule, notes: 'operator updated schedule');
        $schedule->refresh();

        $instanceId = ScheduleManager::trigger($schedule);
        $this->assertNotNull($instanceId);
        $schedule->refresh();

        ScheduleManager::delete($schedule);
        $schedule->refresh();

        $skippedInstanceId = ScheduleManager::trigger($schedule);
        $this->assertNull($skippedInstanceId);

        $events = WorkflowScheduleHistoryEvent::query()
            ->where('workflow_schedule_id', $schedule->id)
            ->orderBy('sequence')
            ->get();

        $this->assertSame([
            HistoryEventType::ScheduleCreated->value,
            HistoryEventType::SchedulePaused->value,
            HistoryEventType::ScheduleResumed->value,
            HistoryEventType::ScheduleUpdated->value,
            HistoryEventType::ScheduleTriggered->value,
            HistoryEventType::ScheduleDeleted->value,
            HistoryEventType::ScheduleTriggerSkipped->value,
        ], $events
            ->map(static fn (WorkflowScheduleHistoryEvent $event): string => $event->event_type->value)
            ->all());

        $this->assertSame(range(1, 7), $events->pluck('sequence')->all());

        $created = $events[0];
        $this->assertSame('schedule-audit-test', $created->schedule_id);
        $this->assertSame($schedule->id, $created->workflow_schedule_id);
        $this->assertSame('schedule-audit-test', $created->payload['schedule']['schedule_id']);
        $this->assertSame(['* * * * *'], $created->payload['spec']['cron_expressions']);

        $this->assertSame('operator hold', $events[1]->payload['reason']);
        $this->assertContains('note', $events[3]->payload['changed_fields']);
        $this->assertSame('operator updated schedule', $events[3]->payload['schedule']['note']);

        $triggered = $events[4];
        $this->assertSame($instanceId, $triggered->workflow_instance_id);
        $this->assertSame(1, $triggered->payload['trigger_number']);
        $this->assertSame('scheduled', $triggered->payload['outcome']);
        $this->assertNotNull($triggered->workflow_run_id);

        $this->assertSame('deleted', $events[5]->payload['reason']);
        $this->assertSame('status_not_triggerable', $events[6]->payload['reason']);
        $this->assertSame(1, $events[6]->payload['skipped_trigger_count']);
    }

    public function testScheduleHistorySequenceRecoversFromConcurrentCollision(): void
    {
        $schedule = ScheduleManager::create(
            scheduleId: 'sequence-race-test',
            workflowClass: TestScheduledWorkflow::class,
            cronExpression: '* * * * *',
        );

        // ScheduleCreated was written with sequence = 1. Simulate another
        // concurrent writer that has already claimed sequence = 2 before we
        // attempt to record the next lifecycle event — exactly the collision
        // the unique(workflow_schedule_id, sequence) constraint would raise.
        WorkflowScheduleHistoryEvent::query()->create([
            'workflow_schedule_id' => $schedule->id,
            'schedule_id' => $schedule->schedule_id,
            'namespace' => $schedule->namespace,
            'sequence' => 2,
            'event_type' => HistoryEventType::SchedulePaused->value,
            'payload' => [
                'reason' => 'pre-claimed by racing writer',
            ],
            'recorded_at' => now(),
        ]);

        ScheduleManager::pause($schedule, 'operator hold');

        $events = WorkflowScheduleHistoryEvent::query()
            ->where('workflow_schedule_id', $schedule->id)
            ->orderBy('sequence')
            ->get();

        // record() should have retried past the collision and landed on
        // sequence = 3, preserving ordering rather than blowing up.
        $this->assertSame([1, 2, 3], $events->pluck('sequence')->all());
        $this->assertSame(
            [
                HistoryEventType::ScheduleCreated->value,
                HistoryEventType::SchedulePaused->value,
                HistoryEventType::SchedulePaused->value,
            ],
            $events->map(static fn (WorkflowScheduleHistoryEvent $event): string => $event->event_type->value)
                ->all(),
        );
        $this->assertSame('pre-claimed by racing writer', $events[1]->payload['reason']);
        $this->assertSame('operator hold', $events[2]->payload['reason']);
    }

    public function testConcurrentScheduleLifecycleWritesAllLandWithUniqueSequences(): void
    {
        $schedule = ScheduleManager::create(
            scheduleId: 'sequence-pileup-test',
            workflowClass: TestScheduledWorkflow::class,
            cronExpression: '* * * * *',
        );

        // Pre-claim sequences 2..6 to force record() to retry five times
        // before landing on the next free slot. This exercises the retry
        // loop under a larger pileup than a single collision.
        for ($claimed = 2; $claimed <= 6; $claimed++) {
            WorkflowScheduleHistoryEvent::query()->create([
                'workflow_schedule_id' => $schedule->id,
                'schedule_id' => $schedule->schedule_id,
                'namespace' => $schedule->namespace,
                'sequence' => $claimed,
                'event_type' => HistoryEventType::SchedulePaused->value,
                'payload' => [
                    'reason' => 'pileup ' . $claimed,
                ],
                'recorded_at' => now(),
            ]);
        }

        ScheduleManager::pause($schedule, 'post-pileup');

        $sequences = WorkflowScheduleHistoryEvent::query()
            ->where('workflow_schedule_id', $schedule->id)
            ->orderBy('sequence')
            ->pluck('sequence')
            ->all();

        $this->assertSame([1, 2, 3, 4, 5, 6, 7], $sequences);

        $tail = WorkflowScheduleHistoryEvent::query()
            ->where('workflow_schedule_id', $schedule->id)
            ->where('sequence', 7)
            ->first();
        $this->assertNotNull($tail);
        $this->assertSame('post-pileup', $tail->payload['reason']);
    }

    public function testRecordRetriesWhenStaleMaxSequenceCollidesWithUniqueIndex(): void
    {
        // Prior regression coverage (the two tests above) exercised the happy
        // path where record() reads a fresh max(sequence) and lands on the
        // next free slot. They do NOT hit the UniqueConstraintViolationException
        // branch of record(), because by the time record() runs the
        // pre-inserted rows have already pushed max() up.
        //
        // This test forces the collision path: we hook the Eloquent `creating`
        // event so the *first* create() call inside record() runs into a
        // sibling row at the same sequence, racing the unique index. The hook
        // fires exactly once — record()'s retry loop must then re-read max()
        // and insert at the next free slot.

        $schedule = ScheduleManager::create(
            scheduleId: 'stale-max-race-test',
            workflowClass: TestScheduledWorkflow::class,
            cronExpression: '* * * * *',
        );

        $raced = false;

        WorkflowScheduleHistoryEvent::creating(static function (WorkflowScheduleHistoryEvent $event) use (
            &$raced,
            $schedule
        ): void {
            // Only race the very first attempt inside record(). The retry
            // re-reads max(sequence) and should land on the next slot, which
            // this hook no longer interferes with.
            if ($raced || (int) $event->sequence !== 2) {
                return;
            }

            $raced = true;

            // Commit a sibling row at the exact sequence record() is about to
            // try. On MySQL/Postgres this produces UniqueConstraintViolationException
            // when record()'s create() runs; on SQLite the same unique-index
            // violation is raised by Laravel as UniqueConstraintViolationException.
            WorkflowScheduleHistoryEvent::query()->create([
                'workflow_schedule_id' => $schedule->id,
                'schedule_id' => $schedule->schedule_id,
                'namespace' => $schedule->namespace,
                'sequence' => 2,
                'event_type' => HistoryEventType::SchedulePaused->value,
                'payload' => [
                    'reason' => 'raced the pending writer',
                ],
                'recorded_at' => now(),
            ]);
        });

        try {
            ScheduleManager::pause($schedule, 'after retry');
        } finally {
            WorkflowScheduleHistoryEvent::flushEventListeners();
        }

        $this->assertTrue($raced, 'The creating-hook race was not exercised; test fixture is broken.');

        $events = WorkflowScheduleHistoryEvent::query()
            ->where('workflow_schedule_id', $schedule->id)
            ->orderBy('sequence')
            ->get();

        // record() must land the SchedulePaused event at sequence 3 after the
        // retry, preserving monotonic ordering without a duplicate-key fatal.
        $this->assertSame([1, 2, 3], $events->pluck('sequence')->all());

        $this->assertSame(HistoryEventType::ScheduleCreated->value, $events[0]->event_type->value);
        $this->assertSame('raced the pending writer', $events[1]->payload['reason']);
        $this->assertSame(HistoryEventType::SchedulePaused->value, $events[2]->event_type->value);
        $this->assertSame('after retry', $events[2]->payload['reason']);
    }

    public function testTriggerDeletedScheduleTracksSkipReason(): void
    {
        $schedule = ScheduleManager::create(
            scheduleId: 'skip-tracking-test',
            workflowClass: TestScheduledWorkflow::class,
            cronExpression: '* * * * *',
        );

        ScheduleManager::delete($schedule);

        $instanceId = ScheduleManager::trigger($schedule);
        $schedule->refresh();

        $this->assertNull($instanceId);
        $this->assertSame('status_not_triggerable', $schedule->last_skip_reason);
        $this->assertNotNull($schedule->last_skipped_at);
        $this->assertSame(1, (int) $schedule->skipped_trigger_count);
    }

    public function testDescribeIncludesSkipTracking(): void
    {
        $schedule = ScheduleManager::create(
            scheduleId: 'describe-skip-test',
            workflowClass: TestScheduledWorkflow::class,
            cronExpression: '* * * * *',
        );

        ScheduleManager::delete($schedule);

        ScheduleManager::trigger($schedule);
        $schedule->refresh();

        $description = ScheduleManager::describe($schedule);
        $this->assertSame(1, $description->skippedTriggerCount);
        $this->assertSame('status_not_triggerable', $description->lastSkipReason);
        $this->assertNotNull($description->lastSkippedAt);

        $array = $description->toArray();
        $this->assertSame(1, $array['skipped_trigger_count']);
        $this->assertSame('status_not_triggerable', $array['last_skip_reason']);
    }

    public function testExhaustedRemainingActionsTracksSkipReason(): void
    {
        WorkflowStub::fake();

        $schedule = ScheduleManager::create(
            scheduleId: 'exhausted-skip-test',
            workflowClass: TestScheduledWorkflow::class,
            cronExpression: '* * * * *',
            maxRuns: 1,
            overlapPolicy: ScheduleOverlapPolicy::AllowAll,
        );

        ScheduleManager::trigger($schedule);
        $schedule->refresh();

        ScheduleManager::trigger($schedule);
        $schedule->refresh();

        $this->assertSame('status_not_triggerable', $schedule->last_skip_reason);
        $this->assertSame(1, (int) $schedule->skipped_trigger_count);
    }

    public function testBackfillTriggersMultipleOccurrences(): void
    {
        WorkflowStub::fake();

        $schedule = ScheduleManager::create(
            scheduleId: 'backfill-test',
            workflowClass: TestScheduledWorkflow::class,
            cronExpression: '0 * * * *',
            overlapPolicy: ScheduleOverlapPolicy::AllowAll,
        );

        $from = new \DateTimeImmutable('2026-04-14 00:00:00', new \DateTimeZone('UTC'));
        $to = new \DateTimeImmutable('2026-04-14 03:00:00', new \DateTimeZone('UTC'));

        $results = ScheduleManager::backfill($schedule, $from, $to);

        $this->assertCount(3, $results);
        $this->assertSame('backfill-test', $results[0]['schedule_id']);
        $this->assertNotNull($results[0]['instance_id']);
        $this->assertNotNull($results[1]['instance_id']);
        $this->assertNotNull($results[2]['instance_id']);

        $schedule->refresh();
        $this->assertSame(3, (int) $schedule->fires_count);
    }

    public function testBackfillRespectsMaxRuns(): void
    {
        WorkflowStub::fake();

        $schedule = ScheduleManager::create(
            scheduleId: 'backfill-maxruns-test',
            workflowClass: TestScheduledWorkflow::class,
            cronExpression: '0 * * * *',
            maxRuns: 2,
            overlapPolicy: ScheduleOverlapPolicy::AllowAll,
        );

        $from = new \DateTimeImmutable('2026-04-14 00:00:00', new \DateTimeZone('UTC'));
        $to = new \DateTimeImmutable('2026-04-14 05:00:00', new \DateTimeZone('UTC'));

        $results = ScheduleManager::backfill($schedule, $from, $to);

        $triggeredResults = array_filter($results, static fn (array $r) => $r['instance_id'] !== null);
        $this->assertCount(2, $triggeredResults);

        $schedule->refresh();
        $this->assertSame(ScheduleStatus::Deleted, $schedule->status);
    }

    public function testBackfillDeletedScheduleThrows(): void
    {
        $schedule = ScheduleManager::create(
            scheduleId: 'backfill-deleted-test',
            workflowClass: TestScheduledWorkflow::class,
            cronExpression: '0 * * * *',
        );

        ScheduleManager::delete($schedule);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Cannot backfill deleted schedule');

        ScheduleManager::backfill(
            $schedule,
            new \DateTimeImmutable('2026-04-14 00:00:00'),
            new \DateTimeImmutable('2026-04-14 03:00:00'),
        );
    }

    public function testBackfillRecordsScheduleTriggeredEventsWithOccurrenceTime(): void
    {
        WorkflowStub::fake();

        $schedule = ScheduleManager::create(
            scheduleId: 'backfill-event-test',
            workflowClass: TestScheduledWorkflow::class,
            cronExpression: '0 * * * *',
            overlapPolicy: ScheduleOverlapPolicy::AllowAll,
        );

        $from = new \DateTimeImmutable('2026-04-14 00:00:00', new \DateTimeZone('UTC'));
        $to = new \DateTimeImmutable('2026-04-14 02:00:00', new \DateTimeZone('UTC'));

        $results = ScheduleManager::backfill($schedule, $from, $to);

        $this->assertCount(2, $results);

        foreach ($results as $result) {
            $this->assertNotNull($result['instance_id']);

            $stub = WorkflowStub::load($result['instance_id']);
            $runId = $stub->runId();

            $event = WorkflowHistoryEvent::query()
                ->where('workflow_run_id', $runId)
                ->where('event_type', HistoryEventType::ScheduleTriggered->value)
                ->first();

            $this->assertNotNull($event);
            $this->assertSame('backfill-event-test', $event->payload['schedule_id']);
            $this->assertArrayHasKey('occurrence_time', $event->payload);
        }
    }

    public function testBackfillWithOverlapPolicyOverride(): void
    {
        WorkflowStub::fake();

        $schedule = ScheduleManager::create(
            scheduleId: 'backfill-overlap-override',
            workflowClass: TestScheduledWorkflow::class,
            cronExpression: '0 * * * *',
            overlapPolicy: ScheduleOverlapPolicy::Skip,
        );

        $from = new \DateTimeImmutable('2026-04-14 00:00:00', new \DateTimeZone('UTC'));
        $to = new \DateTimeImmutable('2026-04-14 03:00:00', new \DateTimeZone('UTC'));

        $results = ScheduleManager::backfill(
            $schedule,
            $from,
            $to,
            overlapPolicyOverride: ScheduleOverlapPolicy::AllowAll,
        );

        $triggeredResults = array_filter($results, static fn (array $r) => $r['instance_id'] !== null);
        $this->assertCount(3, $triggeredResults);

        $schedule->refresh();
        $this->assertSame(3, (int) $schedule->fires_count);
    }

    public function testBackfillSkipsCompatibilityBlockedStartWithoutRecordingFailure(): void
    {
        WorkerCompatibilityFleet::clear();

        config()->set('queue.default', 'redis');
        config()->set('queue.connections.redis.driver', 'redis');
        config()->set('workflows.v2.compatibility.current', 'build-a');
        config()->set('workflows.v2.compatibility.supported', ['build-a']);
        config()->set('workflows.v2.compatibility.namespace', null);
        config()->set('workflows.v2.fleet.validation_mode', 'fail');

        WorkerCompatibilityFleet::record(['build-b'], 'redis', 'default', 'worker-build-b');

        $schedule = ScheduleManager::create(
            scheduleId: 'backfill-compatibility-blocked',
            workflowClass: TestScheduledWorkflow::class,
            cronExpression: '0 * * * *',
            overlapPolicy: ScheduleOverlapPolicy::AllowAll,
            connection: 'redis',
            queue: 'default',
        );

        $from = new \DateTimeImmutable('2026-04-14 00:00:00', new \DateTimeZone('UTC'));
        $to = new \DateTimeImmutable('2026-04-14 01:00:00', new \DateTimeZone('UTC'));

        $results = ScheduleManager::backfill($schedule, $from, $to);

        $this->assertCount(1, $results);
        $this->assertSame('backfill-compatibility-blocked', $results[0]['schedule_id']);
        $this->assertNull($results[0]['instance_id']);
        $this->assertArrayNotHasKey('error', $results[0]);

        $schedule->refresh();
        $this->assertSame(0, (int) $schedule->fires_count);
        $this->assertSame(0, (int) $schedule->failures_count);
        $this->assertSame('compatibility_blocked', $schedule->last_skip_reason);
        $this->assertSame(0, WorkflowRun::query()->count());
    }

    public function testMultipleTriggersIncrementScheduleTriggeredEventCount(): void
    {
        WorkflowStub::fake();

        $schedule = ScheduleManager::create(
            scheduleId: 'multi-trigger-event-test',
            workflowClass: TestScheduledWorkflow::class,
            cronExpression: '* * * * *',
            overlapPolicy: ScheduleOverlapPolicy::AllowAll,
        );

        $firstInstanceId = ScheduleManager::trigger($schedule);
        $schedule->refresh();

        $secondInstanceId = ScheduleManager::trigger($schedule);
        $schedule->refresh();

        $this->assertNotNull($firstInstanceId);
        $this->assertNotNull($secondInstanceId);

        $firstStub = WorkflowStub::load($firstInstanceId);
        $firstEvent = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $firstStub->runId())
            ->where('event_type', HistoryEventType::ScheduleTriggered->value)
            ->firstOrFail();

        $this->assertSame(1, $firstEvent->payload['trigger_number']);

        $secondStub = WorkflowStub::load($secondInstanceId);
        $secondEvent = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $secondStub->runId())
            ->where('event_type', HistoryEventType::ScheduleTriggered->value)
            ->firstOrFail();

        $this->assertSame(2, $secondEvent->payload['trigger_number']);
    }

    public function testBufferOneBuffersWhenRunIsActive(): void
    {
        WorkflowStub::fake();

        $schedule = ScheduleManager::create(
            scheduleId: 'buffer-one-test',
            workflowClass: TestScheduledWorkflow::class,
            cronExpression: '* * * * *',
            overlapPolicy: ScheduleOverlapPolicy::BufferOne,
        );

        $firstInstanceId = ScheduleManager::trigger($schedule);
        $this->assertNotNull($firstInstanceId);

        $schedule->refresh();
        $this->assertSame(1, (int) $schedule->fires_count);

        $run = WorkflowRun::query()->find(WorkflowStub::load($firstInstanceId)->runId());
        $run->forceFill([
            'status' => 'running',
        ])->save();

        $secondInstanceId = ScheduleManager::trigger($schedule);
        $this->assertNull($secondInstanceId, 'BufferOne should not start a second run while one is active.');

        $schedule->refresh();
        $this->assertSame(1, (int) $schedule->fires_count);
        $this->assertTrue($schedule->hasBufferedActions(), 'BufferOne should buffer the trigger instead of skipping.');
        $this->assertCount(1, $schedule->buffered_actions);
    }

    public function testBufferOneRejectsSecondBuffer(): void
    {
        WorkflowStub::fake();

        $schedule = ScheduleManager::create(
            scheduleId: 'buffer-one-cap-test',
            workflowClass: TestScheduledWorkflow::class,
            cronExpression: '* * * * *',
            overlapPolicy: ScheduleOverlapPolicy::BufferOne,
        );

        ScheduleManager::trigger($schedule);
        $schedule->refresh();

        $run = WorkflowRun::query()->find(WorkflowStub::load($schedule->latest_workflow_instance_id)->runId());
        $run->forceFill([
            'status' => 'running',
        ])->save();

        ScheduleManager::trigger($schedule);
        $schedule->refresh();
        $this->assertCount(1, $schedule->buffered_actions);

        ScheduleManager::trigger($schedule);
        $schedule->refresh();
        $this->assertCount(1, $schedule->buffered_actions, 'BufferOne should not exceed one buffered action.');
        $this->assertSame('buffer_full', $schedule->last_skip_reason);
    }

    public function testBufferOneDrainsOnTick(): void
    {
        WorkflowStub::fake();

        $schedule = ScheduleManager::create(
            scheduleId: 'buffer-drain-test',
            workflowClass: TestScheduledWorkflow::class,
            cronExpression: '0 0 1 1 *',
            overlapPolicy: ScheduleOverlapPolicy::BufferOne,
        );

        $firstInstanceId = ScheduleManager::trigger($schedule);
        $schedule->refresh();

        $run = WorkflowRun::query()->find(WorkflowStub::load($firstInstanceId)->runId());
        $run->forceFill([
            'status' => 'running',
        ])->save();

        ScheduleManager::trigger($schedule);
        $schedule->refresh();
        $this->assertTrue($schedule->hasBufferedActions());

        $run->forceFill([
            'status' => 'completed',
        ])->save();

        $results = ScheduleManager::tick();

        $drainedResults = array_filter(
            $results,
            static fn (array $r) => $r['schedule_id'] === 'buffer-drain-test' && $r['instance_id'] !== null
        );
        $this->assertCount(1, $drainedResults, 'Tick should drain the buffered action after the run completes.');

        $schedule->refresh();
        $this->assertFalse($schedule->hasBufferedActions());
        $this->assertSame(2, (int) $schedule->fires_count);
    }

    public function testJitterAppliesRandomOffsetToNextFireAt(): void
    {
        $schedule = ScheduleManager::create(
            scheduleId: 'jitter-test',
            workflowClass: TestScheduledWorkflow::class,
            cronExpression: '0 * * * *',
            jitterSeconds: 300,
        );

        $this->assertSame(300, (int) $schedule->jitter_seconds);

        $canonicalNext = $schedule->computeNextFireAt();
        $this->assertNotNull($canonicalNext);

        $jitteredTimes = [];
        for ($i = 0; $i < 20; $i++) {
            $jittered = $schedule->computeNextFireAtWithJitter();
            $this->assertNotNull($jittered);
            $this->assertGreaterThanOrEqual(
                $canonicalNext->getTimestamp(),
                $jittered->getTimestamp(),
                'Jittered time must not be earlier than canonical time.',
            );
            $this->assertLessThanOrEqual(
                $canonicalNext->getTimestamp() + 300,
                $jittered->getTimestamp(),
                'Jittered time must not exceed canonical time + jitter_seconds.',
            );
            $jitteredTimes[] = $jittered->getTimestamp();
        }

        $this->assertGreaterThan(
            1,
            count(array_unique($jitteredTimes)),
            'Jitter should produce varying fire times across multiple calls.',
        );
    }

    public function testZeroJitterReturnsCanonicalTime(): void
    {
        $schedule = ScheduleManager::create(
            scheduleId: 'no-jitter-test',
            workflowClass: TestScheduledWorkflow::class,
            cronExpression: '0 * * * *',
            jitterSeconds: 0,
        );

        $canonical = $schedule->computeNextFireAt();
        $jittered = $schedule->computeNextFireAtWithJitter();

        $this->assertNotNull($canonical);
        $this->assertNotNull($jittered);
        $this->assertSame(
            $canonical->getTimestamp(),
            $jittered->getTimestamp(),
            'With jitter_seconds=0, jittered time should equal canonical time.',
        );
    }

    public function testJitterAppliedOnCreate(): void
    {
        $schedule = ScheduleManager::create(
            scheduleId: 'jitter-create-test',
            workflowClass: TestScheduledWorkflow::class,
            cronExpression: '0 * * * *',
            jitterSeconds: 600,
        );

        $canonical = $schedule->computeNextFireAt();
        $storedNext = $schedule->next_fire_at;

        $this->assertNotNull($canonical);
        $this->assertNotNull($storedNext);
        $this->assertGreaterThanOrEqual(
            $canonical->getTimestamp(),
            $storedNext->getTimestamp(),
            'Stored next_fire_at should be >= canonical time.',
        );
        $this->assertLessThanOrEqual(
            $canonical->getTimestamp() + 600,
            $storedNext->getTimestamp(),
            'Stored next_fire_at should be <= canonical time + jitter_seconds.',
        );
    }

    public function testJitterAppliedAfterTrigger(): void
    {
        WorkflowStub::fake();

        $schedule = ScheduleManager::create(
            scheduleId: 'jitter-trigger-test',
            workflowClass: TestScheduledWorkflow::class,
            cronExpression: '0 * * * *',
            jitterSeconds: 120,
            overlapPolicy: ScheduleOverlapPolicy::AllowAll,
        );

        ScheduleManager::trigger($schedule);
        $schedule->refresh();

        $canonical = $schedule->computeNextFireAt();
        $storedNext = $schedule->next_fire_at;

        $this->assertNotNull($canonical);
        $this->assertNotNull($storedNext);
        $this->assertGreaterThanOrEqual($canonical->getTimestamp(), $storedNext->getTimestamp());
        $this->assertLessThanOrEqual($canonical->getTimestamp() + 120, $storedNext->getTimestamp());
    }

    public function testBackfillEnumerationIgnoresJitter(): void
    {
        WorkflowStub::fake();

        $schedule = ScheduleManager::create(
            scheduleId: 'jitter-backfill-test',
            workflowClass: TestScheduledWorkflow::class,
            cronExpression: '0 * * * *',
            jitterSeconds: 300,
            overlapPolicy: ScheduleOverlapPolicy::AllowAll,
        );

        $from = new \DateTimeImmutable('2026-04-14 00:00:00', new \DateTimeZone('UTC'));
        $to = new \DateTimeImmutable('2026-04-14 03:00:00', new \DateTimeZone('UTC'));

        $results = ScheduleManager::backfill($schedule, $from, $to);

        $this->assertCount(3, $results);
        $this->assertSame('2026-04-14T00:00:00+00:00', $results[0]['cron_time']);
        $this->assertSame('2026-04-14T01:00:00+00:00', $results[1]['cron_time']);
        $this->assertSame('2026-04-14T02:00:00+00:00', $results[2]['cron_time']);
    }

    public function testDescribeIncludesJitterSeconds(): void
    {
        $schedule = ScheduleManager::create(
            scheduleId: 'jitter-describe-test',
            workflowClass: TestScheduledWorkflow::class,
            cronExpression: '0 * * * *',
            jitterSeconds: 45,
        );

        $description = ScheduleManager::describe($schedule);
        $this->assertSame(45, $description->jitterSeconds);

        $array = $description->toArray();
        $this->assertSame(45, $array['jitter_seconds']);
    }

    public function testTriggerWithConnectionAndQueueRoutesWorkflow(): void
    {
        WorkflowStub::fake();

        $schedule = ScheduleManager::create(
            scheduleId: 'routing-trigger-test',
            workflowClass: TestScheduledWorkflow::class,
            cronExpression: '* * * * *',
            connection: 'redis',
            queue: 'high-priority',
        );

        $instanceId = ScheduleManager::trigger($schedule);
        $this->assertNotNull($instanceId);

        $stub = WorkflowStub::load($instanceId);
        $run = WorkflowRun::query()->find($stub->runId());

        $this->assertSame('redis', $run->connection, 'Schedule connection should be forwarded to the workflow run.');
        $this->assertSame('high-priority', $run->queue, 'Schedule queue should be forwarded to the workflow run.');
    }

    public function testBufferAllBuffersMultipleTriggersWhenRunIsActive(): void
    {
        WorkflowStub::fake();

        $schedule = ScheduleManager::create(
            scheduleId: 'buffer-all-test',
            workflowClass: TestScheduledWorkflow::class,
            cronExpression: '* * * * *',
            overlapPolicy: ScheduleOverlapPolicy::BufferAll,
        );

        $firstInstanceId = ScheduleManager::trigger($schedule);
        $this->assertNotNull($firstInstanceId);

        $run = WorkflowRun::query()->find(WorkflowStub::load($firstInstanceId)->runId());
        $run->forceFill([
            'status' => 'running',
        ])->save();

        $schedule->refresh();

        ScheduleManager::trigger($schedule);
        $schedule->refresh();
        $this->assertCount(1, $schedule->buffered_actions);

        ScheduleManager::trigger($schedule);
        $schedule->refresh();
        $this->assertCount(2, $schedule->buffered_actions, 'BufferAll should accept multiple buffered actions.');

        ScheduleManager::trigger($schedule);
        $schedule->refresh();
        $this->assertCount(3, $schedule->buffered_actions, 'BufferAll should not cap buffered actions.');
    }

    public function testBufferAllDrainsSequentiallyOnTick(): void
    {
        WorkflowStub::fake();

        $schedule = ScheduleManager::create(
            scheduleId: 'buffer-all-drain-test',
            workflowClass: TestScheduledWorkflow::class,
            cronExpression: '0 0 1 1 *',
            overlapPolicy: ScheduleOverlapPolicy::BufferAll,
        );

        $firstInstanceId = ScheduleManager::trigger($schedule);
        $schedule->refresh();

        $run = WorkflowRun::query()->find(WorkflowStub::load($firstInstanceId)->runId());
        $run->forceFill([
            'status' => 'running',
        ])->save();

        ScheduleManager::trigger($schedule);
        ScheduleManager::trigger($schedule);
        $schedule->refresh();
        $this->assertCount(2, $schedule->buffered_actions);

        $run->forceFill([
            'status' => 'completed',
        ])->save();

        $results = ScheduleManager::tick();

        $drained = array_filter(
            $results,
            static fn (array $r) => $r['schedule_id'] === 'buffer-all-drain-test' && $r['instance_id'] !== null
        );
        $this->assertCount(1, $drained, 'Tick should drain one buffered action at a time.');

        $schedule->refresh();
        $this->assertCount(1, $schedule->buffered_actions, 'One buffered action should remain after draining one.');
        $this->assertSame(2, (int) $schedule->fires_count);
    }

    public function testBackfillWithBufferOnePolicyStartsAllOccurrences(): void
    {
        WorkflowStub::fake();

        $schedule = ScheduleManager::create(
            scheduleId: 'backfill-buffer-one-test',
            workflowClass: TestScheduledWorkflow::class,
            cronExpression: '0 * * * *',
            overlapPolicy: ScheduleOverlapPolicy::BufferOne,
        );

        $from = new \DateTimeImmutable('2026-04-14 00:00:00', new \DateTimeZone('UTC'));
        $to = new \DateTimeImmutable('2026-04-14 03:00:00', new \DateTimeZone('UTC'));

        $results = ScheduleManager::backfill($schedule, $from, $to);

        $triggeredResults = array_filter($results, static fn (array $r) => $r['instance_id'] !== null);
        $this->assertCount(
            3,
            $triggeredResults,
            'Backfill with BufferOne should start all occurrences (buffer treated as AllowAll).'
        );

        $schedule->refresh();
        $this->assertSame(3, (int) $schedule->fires_count);
    }

    public function testBackfillWithBufferAllPolicyStartsAllOccurrences(): void
    {
        WorkflowStub::fake();

        $schedule = ScheduleManager::create(
            scheduleId: 'backfill-buffer-all-test',
            workflowClass: TestScheduledWorkflow::class,
            cronExpression: '0 * * * *',
            overlapPolicy: ScheduleOverlapPolicy::BufferAll,
        );

        $from = new \DateTimeImmutable('2026-04-14 00:00:00', new \DateTimeZone('UTC'));
        $to = new \DateTimeImmutable('2026-04-14 03:00:00', new \DateTimeZone('UTC'));

        $results = ScheduleManager::backfill($schedule, $from, $to);

        $triggeredResults = array_filter($results, static fn (array $r) => $r['instance_id'] !== null);
        $this->assertCount(
            3,
            $triggeredResults,
            'Backfill with BufferAll should start all occurrences (buffer treated as AllowAll).'
        );

        $schedule->refresh();
        $this->assertSame(3, (int) $schedule->fires_count);
    }

    // ── Interval-based schedule tests ────────────────────────────────

    public function testCreateFromSpecWithInterval(): void
    {
        $schedule = ScheduleManager::createFromSpec(
            scheduleId: 'interval-30m',
            spec: [
                'intervals' => [[
                    'every' => 'PT30M',
                ]],
            ],
            action: [
                'workflow_type' => 'test-scheduled-workflow',
                'workflow_class' => TestScheduledWorkflow::class,
                'input' => ['interval-run'],
            ],
        );

        $this->assertInstanceOf(WorkflowSchedule::class, $schedule);
        $this->assertSame('interval-30m', $schedule->schedule_id);
        $this->assertSame(ScheduleStatus::Active, $schedule->status);
        $this->assertNotNull($schedule->next_fire_at);

        $spec = $schedule->spec;
        $this->assertSame([[
            'every' => 'PT30M',
        ]], $spec['intervals']);
    }

    public function testCreateFromSpecWithIntervalAndOffset(): void
    {
        $schedule = ScheduleManager::createFromSpec(
            scheduleId: 'interval-offset',
            spec: [
                'intervals' => [[
                    'every' => 'PT1H',
                    'offset' => 'PT5M',
                ]],
            ],
            action: [
                'workflow_type' => 'test-scheduled-workflow',
                'workflow_class' => TestScheduledWorkflow::class,
                'input' => [],
            ],
        );

        $this->assertNotNull($schedule->next_fire_at);
        $spec = $schedule->spec;
        $this->assertSame('PT5M', $spec['intervals'][0]['offset']);
    }

    public function testNextIntervalOccurrenceComputesCorrectly(): void
    {
        $after = new \DateTimeImmutable('2026-04-14 10:00:00', new \DateTimeZone('UTC'));

        $next = WorkflowSchedule::nextIntervalOccurrence([
            'every' => 'PT30M',
        ], $after,);

        $this->assertNotNull($next);
        $this->assertGreaterThan($after, $next);
        $diffSeconds = $next->getTimestamp() - $after->getTimestamp();
        $this->assertLessThanOrEqual(30 * 60, $diffSeconds);
    }

    public function testNextIntervalOccurrenceWithOffset(): void
    {
        $after = new \DateTimeImmutable('2026-04-14 10:02:00', new \DateTimeZone('UTC'));

        $next = WorkflowSchedule::nextIntervalOccurrence([
            'every' => 'PT1H',
            'offset' => 'PT5M',
        ], $after,);

        $this->assertNotNull($next);
        $minuteOfHour = (int) date('i', $next->getTimestamp());
        $this->assertSame(5, $minuteOfHour, 'Interval with offset PT5M should land on :05 of the hour.');
    }

    public function testNextIntervalOccurrenceReturnsNullForInvalidSpec(): void
    {
        $after = new \DateTimeImmutable('2026-04-14 10:00:00', new \DateTimeZone('UTC'));

        $this->assertNull(WorkflowSchedule::nextIntervalOccurrence([
            'every' => '',
        ], $after));
        $this->assertNull(WorkflowSchedule::nextIntervalOccurrence([
            'every' => 'INVALID',
        ], $after));
        $this->assertNull(WorkflowSchedule::nextIntervalOccurrence([], $after));
    }

    public function testDateIntervalToSeconds(): void
    {
        $this->assertSame(3600, WorkflowSchedule::dateIntervalToSeconds(new \DateInterval('PT1H')));
        $this->assertSame(1800, WorkflowSchedule::dateIntervalToSeconds(new \DateInterval('PT30M')));
        $this->assertSame(86400, WorkflowSchedule::dateIntervalToSeconds(new \DateInterval('P1D')));
        $this->assertSame(90, WorkflowSchedule::dateIntervalToSeconds(new \DateInterval('PT1M30S')));
    }

    public function testIntervalScheduleTriggersOnTick(): void
    {
        WorkflowStub::fake();

        $schedule = ScheduleManager::createFromSpec(
            scheduleId: 'interval-tick-test',
            spec: [
                'intervals' => [[
                    'every' => 'PT10M',
                ]],
            ],
            action: [
                'workflow_type' => 'test-scheduled-workflow',
                'workflow_class' => TestScheduledWorkflow::class,
                'input' => [],
            ],
        );

        $schedule->forceFill([
            'next_fire_at' => now()
                ->subMinute(),
        ])->save();

        $results = ScheduleManager::tick();
        $triggered = array_filter($results, static fn (array $r) => $r['instance_id'] !== null);

        $this->assertNotEmpty($triggered);
        $this->assertSame('interval-tick-test', $triggered[0]['schedule_id'] ?? $results[0]['schedule_id']);

        $schedule->refresh();
        $this->assertSame(1, (int) $schedule->fires_count);
        $this->assertNotNull($schedule->next_fire_at);
    }

    public function testCreateFromSpecWithMixedCronAndInterval(): void
    {
        $schedule = ScheduleManager::createFromSpec(
            scheduleId: 'mixed-spec',
            spec: [
                'cron_expressions' => ['0 12 * * *'],
                'intervals' => [[
                    'every' => 'PT6H',
                ]],
                'timezone' => 'America/Chicago',
            ],
            action: [
                'workflow_type' => 'test-scheduled-workflow',
                'workflow_class' => TestScheduledWorkflow::class,
                'input' => ['mixed'],
            ],
        );

        $this->assertNotNull($schedule->next_fire_at);
        $spec = $schedule->spec;
        $this->assertCount(1, $spec['cron_expressions']);
        $this->assertCount(1, $spec['intervals']);
        $this->assertSame('America/Chicago', $spec['timezone']);
    }

    public function testCreateFromSpecRejectsEmptySpec(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('at least one cron_expression or interval');

        ScheduleManager::createFromSpec(
            scheduleId: 'empty-spec',
            spec: [
                'cron_expressions' => [],
                'intervals' => [],
            ],
            action: [
                'workflow_type' => 'test-scheduled-workflow',
                'workflow_class' => TestScheduledWorkflow::class,
                'input' => [],
            ],
        );
    }

    public function testCreateFromSpecWithNamespace(): void
    {
        $schedule = ScheduleManager::createFromSpec(
            scheduleId: 'ns-schedule',
            spec: [
                'cron_expressions' => ['*/5 * * * *'],
            ],
            action: [
                'workflow_type' => 'test-scheduled-workflow',
                'workflow_class' => TestScheduledWorkflow::class,
                'input' => [],
            ],
            namespace: 'billing',
        );

        $this->assertSame('billing', $schedule->namespace);

        $found = ScheduleManager::findByScheduleId('ns-schedule', namespace: 'billing');
        $this->assertNotNull($found);
        $this->assertSame($schedule->id, $found->id);

        $notFound = ScheduleManager::findByScheduleId('ns-schedule', namespace: 'shipping');
        $this->assertNull($notFound);
    }

    public function testDescribeIncludesNamespace(): void
    {
        $schedule = ScheduleManager::create(
            scheduleId: 'ns-describe-test',
            workflowClass: TestScheduledWorkflow::class,
            cronExpression: '0 * * * *',
            namespace: 'operations',
        );

        $description = ScheduleManager::describe($schedule);
        $array = $description->toArray();

        $this->assertSame('operations', $description->namespace);
        $this->assertSame('operations', $array['namespace']);
    }

    public function testUpdateScheduleWithSpecOverride(): void
    {
        $schedule = ScheduleManager::create(
            scheduleId: 'update-spec-test',
            workflowClass: TestScheduledWorkflow::class,
            cronExpression: '0 * * * *',
        );

        $updated = ScheduleManager::update($schedule, spec: [
            'intervals' => [[
                'every' => 'PT15M',
            ]],
        ],);

        $spec = $updated->spec;
        $this->assertSame([[
            'every' => 'PT15M',
        ]], $spec['intervals']);
        $this->assertArrayNotHasKey('cron_expressions', $spec);
    }

    public function testUpdateScheduleMemoAndSearchAttributes(): void
    {
        $schedule = ScheduleManager::create(
            scheduleId: 'update-memo-test',
            workflowClass: TestScheduledWorkflow::class,
            cronExpression: '0 * * * *',
            memo: [
                'origin' => 'initial',
            ],
            searchAttributes: [
                'tenant_id' => '1',
            ],
        );

        $this->assertSame([
            'origin' => 'initial',
        ], $schedule->memo);
        $this->assertSame([
            'tenant_id' => '1',
        ], $schedule->search_attributes);

        $updated = ScheduleManager::update(
            $schedule,
            memo: [
                'origin' => 'updated',
                'version' => 2,
            ],
            searchAttributes: [
                'tenant_id' => '2',
            ],
        );

        $this->assertSame([
            'origin' => 'updated',
            'version' => 2,
        ], $updated->memo);
        $this->assertSame([
            'tenant_id' => '2',
        ], $updated->search_attributes);
    }

    public function testUpdateScheduleClearsOptionalFieldsWithEmptyArrays(): void
    {
        $schedule = ScheduleManager::create(
            scheduleId: 'clear-fields-test',
            workflowClass: TestScheduledWorkflow::class,
            cronExpression: '0 * * * *',
            memo: [
                'key' => 'value',
            ],
            searchAttributes: [
                'tenant' => '1',
            ],
        );

        $updated = ScheduleManager::update($schedule, memo: [], searchAttributes: []);

        $this->assertNull($updated->memo);
        $this->assertNull($updated->search_attributes);
    }

    public function testScheduleToDetailIncludesAllFields(): void
    {
        $schedule = ScheduleManager::create(
            scheduleId: 'detail-test',
            workflowClass: TestScheduledWorkflow::class,
            cronExpression: '0 * * * *',
            jitterSeconds: 60,
            maxRuns: 10,
            notes: 'Test detail.',
            memo: [
                'env' => 'test',
            ],
            searchAttributes: [
                'tier' => 'free',
            ],
            namespace: 'platform',
        );

        $detail = $schedule->toDetail();

        $this->assertSame('detail-test', $detail['schedule_id']);
        $this->assertArrayHasKey('spec', $detail);
        $this->assertArrayHasKey('action', $detail);
        $this->assertArrayHasKey('overlap_policy', $detail);
        $this->assertArrayHasKey('state', $detail);
        $this->assertArrayHasKey('info', $detail);
        $this->assertSame([
            'env' => 'test',
        ], $detail['memo']);
        $this->assertSame([
            'tier' => 'free',
        ], $detail['search_attributes']);
    }

    public function testScheduleToListItemShape(): void
    {
        $schedule = ScheduleManager::create(
            scheduleId: 'list-shape-test',
            workflowClass: TestScheduledWorkflow::class,
            cronExpression: '0 * * * *',
        );

        $item = $schedule->toListItem();

        $this->assertSame('list-shape-test', $item['schedule_id']);
        $this->assertSame('test-scheduled-workflow', $item['workflow_type']);
        $this->assertSame('active', $item['status']);
        $this->assertFalse($item['paused']);
        $this->assertArrayHasKey('next_fire', $item);
        $this->assertArrayHasKey('last_fire', $item);
        $this->assertArrayHasKey('overlap_policy', $item);
    }

    public function testCancelOtherOverlapPolicyCancelsPreviousRun(): void
    {
        WorkflowStub::fake();

        $schedule = ScheduleManager::create(
            scheduleId: 'cancel-other-test',
            workflowClass: TestScheduledWorkflow::class,
            cronExpression: '*/5 * * * *',
            overlapPolicy: ScheduleOverlapPolicy::CancelOther,
        );

        $firstId = ScheduleManager::trigger($schedule);
        $this->assertNotNull($firstId);

        $schedule->refresh();
        $secondId = ScheduleManager::trigger($schedule);
        $this->assertNotNull($secondId);

        $schedule->refresh();
        $this->assertSame(2, (int) $schedule->fires_count);
    }

    public function testTerminateOtherOverlapPolicyTerminatesPreviousRun(): void
    {
        WorkflowStub::fake();

        $schedule = ScheduleManager::create(
            scheduleId: 'terminate-other-test',
            workflowClass: TestScheduledWorkflow::class,
            cronExpression: '*/5 * * * *',
            overlapPolicy: ScheduleOverlapPolicy::TerminateOther,
        );

        $firstId = ScheduleManager::trigger($schedule);
        $this->assertNotNull($firstId);

        $schedule->refresh();
        $secondId = ScheduleManager::trigger($schedule);
        $this->assertNotNull($secondId);

        $schedule->refresh();
        $this->assertSame(2, (int) $schedule->fires_count);
    }

    // ───────────────────────────────────────────────────────────────
    //  CommandContext attribution on schedule operations (#297)
    // ───────────────────────────────────────────────────────────────

    public function testPauseRecordsCommandContextInHistoryEvent(): void
    {
        $schedule = ScheduleManager::create(
            scheduleId: 'pause-context-test',
            workflowClass: TestScheduledWorkflow::class,
            cronExpression: '0 * * * *',
        );

        ScheduleManager::pause(
            $schedule,
            reason: 'Operator requested pause',
            context: CommandContext::waterline($this->newWaterlineRequest()),
        );

        $event = WorkflowScheduleHistoryEvent::query()
            ->where('workflow_schedule_id', $schedule->id)
            ->where('event_type', HistoryEventType::SchedulePaused->value)
            ->firstOrFail();

        $payload = $event->payload;
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('command_context', $payload);
        $this->assertSame('waterline', $payload['command_context']['source']);
        $this->assertSame('waterline', $payload['command_context']['context']['caller']['type']);
    }

    public function testResumeRecordsCommandContextInHistoryEvent(): void
    {
        $schedule = ScheduleManager::create(
            scheduleId: 'resume-context-test',
            workflowClass: TestScheduledWorkflow::class,
            cronExpression: '0 * * * *',
        );
        ScheduleManager::pause($schedule);

        ScheduleManager::resume($schedule->fresh(), CommandContext::waterline($this->newWaterlineRequest()));

        $event = WorkflowScheduleHistoryEvent::query()
            ->where('workflow_schedule_id', $schedule->id)
            ->where('event_type', HistoryEventType::ScheduleResumed->value)
            ->firstOrFail();

        $this->assertSame('waterline', $event->payload['command_context']['source'] ?? null);
    }

    public function testDeleteRecordsCommandContextInHistoryEvent(): void
    {
        $schedule = ScheduleManager::create(
            scheduleId: 'delete-context-test',
            workflowClass: TestScheduledWorkflow::class,
            cronExpression: '0 * * * *',
        );

        ScheduleManager::delete($schedule, CommandContext::waterline($this->newWaterlineRequest()));

        $event = WorkflowScheduleHistoryEvent::query()
            ->where('workflow_schedule_id', $schedule->id)
            ->where('event_type', HistoryEventType::ScheduleDeleted->value)
            ->firstOrFail();

        $this->assertSame('waterline', $event->payload['command_context']['source'] ?? null);
    }

    public function testTriggerRecordsCommandContextInScheduleTriggeredEvent(): void
    {
        WorkflowStub::fake();

        $schedule = ScheduleManager::create(
            scheduleId: 'trigger-context-test',
            workflowClass: TestScheduledWorkflow::class,
            cronExpression: '*/5 * * * *',
        );

        $instanceId = ScheduleManager::trigger(
            $schedule,
            context: CommandContext::waterline($this->newWaterlineRequest()),
        );

        $this->assertNotNull($instanceId);

        $event = WorkflowScheduleHistoryEvent::query()
            ->where('workflow_schedule_id', $schedule->id)
            ->where('event_type', HistoryEventType::ScheduleTriggered->value)
            ->firstOrFail();

        $this->assertSame('waterline', $event->payload['command_context']['source'] ?? null);
    }

    public function testScheduleOperationsWithoutContextDoNotAddCommandContextKey(): void
    {
        $schedule = ScheduleManager::create(
            scheduleId: 'no-context-test',
            workflowClass: TestScheduledWorkflow::class,
            cronExpression: '0 * * * *',
        );
        ScheduleManager::pause($schedule);

        $event = WorkflowScheduleHistoryEvent::query()
            ->where('workflow_schedule_id', $schedule->id)
            ->where('event_type', HistoryEventType::SchedulePaused->value)
            ->firstOrFail();

        $this->assertArrayNotHasKey('command_context', (array) $event->payload);
    }

    private function newWaterlineRequest(): Request
    {
        $request = Request::create('/waterline/v2/schedules/test/pause', 'POST');
        $request->headers->set('X-Request-Id', 'test-request-id');

        return $request;
    }
}
