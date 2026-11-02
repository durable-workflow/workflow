<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\V2\TestScheduledWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\ScheduleOverlapPolicy;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowSchedule;
use Workflow\V2\Models\WorkflowScheduleHistoryEvent;
use Workflow\V2\Support\PhpClassScheduleStarter;
use Workflow\V2\Support\ScheduleManager;
use Workflow\V2\Support\WorkflowInstanceId;
use Workflow\V2\WorkflowStub;

/**
 * The same schedule ID in different namespaces starts independent workflow
 * instances for the same occurrence (#874).
 */
final class V2ScheduleNamespaceInstanceIdTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()
            ->set('queue.default', 'redis');
        Queue::fake();
        Carbon::setTestNowAndTimezone(Carbon::parse('2026-10-20 14:00:00', 'UTC'), 'UTC');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testBackfillOfTheSameOccurrenceStartsOneInstancePerNamespace(): void
    {
        $default = $this->schedule('shared');
        $tenant = $this->schedule('shared', 'tenant-b');
        [$from, $to] = $this->window();

        $defaultResults = ScheduleManager::backfill($default, $from, $to);
        $tenantResults = ScheduleManager::backfill($tenant, $from, $to);

        $this->assertCount(1, $defaultResults);
        $this->assertCount(1, $tenantResults);
        $this->assertArrayNotHasKey('error', $defaultResults[0]);
        $this->assertArrayNotHasKey('error', $tenantResults[0]);
        $this->assertNotSame($defaultResults[0]['instance_id'], $tenantResults[0]['instance_id']);
        $this->assertSame('default', $this->instanceNamespace($defaultResults[0]['instance_id']));
        $this->assertSame('tenant-b', $this->instanceNamespace($tenantResults[0]['instance_id']));
        $this->assertSame(1, (int) $tenant->refresh()->fires_count);
        $this->assertSame(0, (int) $tenant->failures_count);

        // A repeated backfill of the same occurrence stays deduplicated per namespace.
        $repeat = ScheduleManager::backfill($tenant, $from, $to);
        $this->assertNull($repeat[0]['instance_id']);
        $this->assertSame(1, (int) $tenant->refresh()->fires_count);
        $this->assertSame(2, WorkflowInstance::query()->count());
    }

    public function testManualTriggersAndTicksInTheSameMillisecondStartOnePerNamespace(): void
    {
        $default = $this->schedule('shared');
        $tenant = $this->schedule('shared', 'tenant-b');

        $manual = [ScheduleManager::trigger($default), ScheduleManager::trigger($tenant)];

        $this->assertNotNull($manual[0]);
        $this->assertNotNull($manual[1]);
        $this->assertNotSame($manual[0], $manual[1]);

        foreach ([$default, $tenant] as $schedule) {
            $schedule->refresh()
                ->forceFill([
                    'next_fire_at' => now()
                        ->subMinute(),
                ])->save();
        }

        $ticked = ScheduleManager::tick();

        $this->assertSame(['triggered', 'triggered'], array_column($ticked, 'outcome'));
        $this->assertCount(2, array_unique(array_column($ticked, 'instance_id')));
        $this->assertSame(4, WorkflowInstance::query()->count());
    }

    public function testChangingTheConfiguredDefaultNamespaceCannotShareAnInstance(): void
    {
        config()
            ->set('workflows.v2.namespace', 'tenant-a');
        $first = $this->schedule('shared');
        config()
            ->set('workflows.v2.namespace', 'tenant-b');
        $second = $this->schedule('shared');
        [$from, $to] = $this->window();

        $firstResults = ScheduleManager::backfill($first, $from, $to);
        $secondResults = ScheduleManager::backfill($second, $from, $to);

        $this->assertSame('tenant-a', $first->namespace);
        $this->assertSame('tenant-b', $second->namespace);
        $this->assertArrayNotHasKey('error', $firstResults[0]);
        $this->assertArrayNotHasKey('error', $secondResults[0]);
        $this->assertNotSame($firstResults[0]['instance_id'], $secondResults[0]['instance_id']);
    }

    public function testLegacyScheduledRunStaysReadableAndItsOccurrenceStaysDeduplicated(): void
    {
        $tenant = $this->schedule('shared', 'tenant-b');
        [$from, $to] = $this->window();
        $legacyId = 'schedule:shared:backfill:' . $from->getTimestamp();

        // What an older release left behind: an instance under the original
        // ID form and the schedule history that records the fired occurrence.
        $legacy = WorkflowStub::make(TestScheduledWorkflow::class, $legacyId, 'tenant-b');
        $legacy->start('legacy');
        WorkflowScheduleHistoryEvent::record($tenant, HistoryEventType::ScheduleTriggered, [
            'workflow_instance_id' => $legacyId,
            'workflow_run_id' => $legacy->runId(),
            'outcome' => 'backfilled',
            'effective_overlap_policy' => 'allow_all',
            'trigger_number' => 1,
            'occurrence_time' => $from->format('Y-m-d\TH:i:s.uP'),
        ]);

        $results = ScheduleManager::backfill($tenant, $from, $to);

        $this->assertNull($results[0]['instance_id']);
        $this->assertSame(1, WorkflowInstance::query()->count());
        $this->assertSame('tenant-b', WorkflowInstance::query()->findOrFail($legacyId)->namespace);
        $this->assertSame($legacy->runId(), WorkflowStub::load($legacyId, 'tenant-b')->runId());

        // The next occurrence starts under the namespace-aware ID without colliding.
        $next = ScheduleManager::backfill($tenant, $to, $to->copy()->addHour());
        $this->assertArrayNotHasKey('error', $next[0]);
        $this->assertStringStartsWith('schedule.ns:', (string) $next[0]['instance_id']);
    }

    public function testNamespacedInstanceIdsAreValidUnambiguousAndBounded(): void
    {
        $shared = $this->instanceId('shared', 'tenant-b', 'backfill:1');

        $this->assertStringStartsWith('schedule.ns:', $shared);
        $this->assertTrue(WorkflowInstanceId::isValid($shared));
        $this->assertNotSame($shared, $this->instanceId('shared', 'default', 'backfill:1'));

        // Never equal to an original-form ID, whatever the schedule ID contains.
        $this->assertStringStartsNotWith('schedule:', $shared);

        // Namespace and schedule delimiters cannot shift tuple boundaries.
        $this->assertNotSame($this->instanceId('a:b', 'tenant-b', '1'), $this->instanceId('a', 'tenant-b', 'b:1'));
        $this->assertNotSame($this->instanceId('shared', 'a:b', '1'), $this->instanceId('shared', 'a', '1'));

        // Long or out-of-alphabet schedule IDs are hashed to a valid, bounded, distinct ID.
        $cases = [str_repeat('x', 255), str_repeat('x', 254) . 'y', 'nightly report/eu', 'nightly report/us'];
        $ids = [];

        foreach ($cases as $scheduleId) {
            $ids[] = $this->instanceId($scheduleId, 'tenant-b', 'backfill:1793000000');
        }

        foreach ($ids as $id) {
            $this->assertTrue(WorkflowInstanceId::isValid($id), $id);
        }

        $this->assertCount(count($cases), array_unique($ids));
        $this->assertSame($ids[0], $this->instanceId($cases[0], 'tenant-b', 'backfill:1793000000'));
    }

    /**
     * @return array{Carbon, Carbon}
     */
    private function window(): array
    {
        return [Carbon::parse('2026-10-20 12:00:00', 'UTC'), Carbon::parse('2026-10-20 13:00:00', 'UTC')];
    }

    private function instanceId(string $scheduleId, string $namespace, string $suffix): string
    {
        return PhpClassScheduleStarter::instanceId(new WorkflowSchedule([
            'schedule_id' => $scheduleId,
            'namespace' => $namespace,
        ]), $suffix);
    }

    private function schedule(string $scheduleId, ?string $namespace = null): WorkflowSchedule
    {
        return ScheduleManager::create(
            scheduleId: $scheduleId,
            workflowClass: TestScheduledWorkflow::class,
            cronExpression: '0 * * * *',
            overlapPolicy: ScheduleOverlapPolicy::AllowAll,
            namespace: $namespace,
        );
    }

    private function instanceNamespace(?string $instanceId): ?string
    {
        $this->assertNotNull($instanceId);

        return WorkflowInstance::query()->findOrFail($instanceId)->namespace;
    }
}
