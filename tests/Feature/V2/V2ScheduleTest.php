<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use LogicException;
use Tests\Fixtures\V2\TestScheduledWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\ScheduleOverlapPolicy;
use Workflow\V2\Enums\ScheduleStatus;
use Workflow\V2\Models\WorkflowSchedule;
use Workflow\V2\Support\ScheduleDescription;
use Workflow\V2\Support\ScheduleManager;
use Workflow\V2\WorkflowStub;

final class V2ScheduleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('queue.default', 'sync');
        config()->set('queue.connections.sync.driver', 'sync');
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
        $this->assertSame(0, (int) $schedule->total_runs);
        $this->assertNotNull($schedule->next_run_at);
        $this->assertSame('Runs every night at 2 AM ET.', $schedule->notes);
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
        $this->assertNotNull($resumed->next_run_at);
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
        $this->assertNull($deleted->next_run_at);
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

        $originalNextRun = $schedule->next_run_at;

        $updated = ScheduleManager::update(
            $schedule,
            cronExpression: '30 2 * * *',
            timezone: 'America/Chicago',
            notes: 'Updated to 2:30 AM CT.',
        );

        $this->assertSame('30 2 * * *', $updated->cron_expression);
        $this->assertSame('America/Chicago', $updated->timezone);
        $this->assertSame('Updated to 2:30 AM CT.', $updated->notes);
        $this->assertNotEquals($originalNextRun, $updated->next_run_at);
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
        $this->assertSame(1, (int) $schedule->total_runs);
        $this->assertNotNull($schedule->last_triggered_at);
        $this->assertSame($instanceId, $schedule->latest_workflow_instance_id);
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

        $schedule->forceFill(['next_run_at' => now()->subMinute()])->save();

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

        $schedule->refresh();
        $schedule->forceFill(['next_run_at' => now()->subMinute()])->save();

        $secondInstanceId = ScheduleManager::trigger($schedule);

        $schedule->refresh();
        $this->assertSame(1, (int) $schedule->total_runs);
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
        $this->assertSame(2, (int) $schedule->total_runs);
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
        $this->assertNull($schedule->next_run_at);
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
        $this->assertSame('test-scheduled-workflow', $description->workflowType);
        $this->assertSame('0 6 * * 1-5', $description->cronExpression);
        $this->assertSame('Europe/London', $description->timezone);
        $this->assertSame(ScheduleStatus::Active, $description->status);
        $this->assertSame(ScheduleOverlapPolicy::BufferOne, $description->overlapPolicy);
        $this->assertSame(30, $description->jitterSeconds);
        $this->assertSame('Weekday 6 AM London.', $description->notes);

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
            labels: ['env' => 'production', 'team' => 'billing'],
            memo: ['origin' => 'scheduled'],
            searchAttributes: ['tenant_id' => '42'],
        );

        $this->assertSame(['env' => 'production', 'team' => 'billing'], $schedule->visibility_labels);
        $this->assertSame(['origin' => 'scheduled'], $schedule->memo);
        $this->assertSame(['tenant_id' => '42'], $schedule->search_attributes);
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
}
