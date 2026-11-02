<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\V2\TestScheduledWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\ScheduleOverlapPolicy;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowSchedule;
use Workflow\V2\Support\PhpClassScheduleStarter;
use Workflow\V2\Support\ScheduleManager;
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
        Carbon::setTestNowAndTimezone(Carbon::parse('2026-10-20T14:00:00Z'), 'UTC');
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
        $from = Carbon::parse('2026-10-20T12:00:00Z');
        $to = Carbon::parse('2026-10-20T13:00:00Z');
        $occurrence = $from->getTimestamp();

        $defaultResults = ScheduleManager::backfill($default, $from, $to);
        $tenantResults = ScheduleManager::backfill($tenant, $from, $to);

        $this->assertCount(1, $defaultResults);
        $this->assertCount(1, $tenantResults);
        $this->assertArrayNotHasKey('error', $tenantResults[0]);

        // The default namespace keeps the original, readable instance ID.
        $this->assertSame("schedule:shared:backfill:{$occurrence}", $defaultResults[0]['instance_id']);
        $this->assertNotNull($tenantResults[0]['instance_id']);
        $this->assertStringStartsWith('schedule@', (string) $tenantResults[0]['instance_id']);

        $this->assertSame('default', $this->instanceNamespace($defaultResults[0]['instance_id']));
        $this->assertSame('tenant-b', $this->instanceNamespace($tenantResults[0]['instance_id']));
        $this->assertSame(1, (int) $default->refresh()->fires_count);
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
        $this->assertStringStartsWith('schedule:shared:', (string) $manual[0]);

        foreach ([$default, $tenant] as $schedule) {
            $schedule->refresh()
                ->forceFill([
                    'next_fire_at' => now()
                        ->subMinute(),
                ])->save();
        }

        $ticked = ScheduleManager::tick();
        $tickInstances = array_column($ticked, 'instance_id');

        $this->assertSame(['triggered', 'triggered'], array_column($ticked, 'outcome'));
        $this->assertCount(2, array_unique($tickInstances));
        $this->assertSame(4, WorkflowInstance::query()->count());
    }

    public function testLegacyScheduledInstanceStaysReadableAfterUpgrade(): void
    {
        $tenant = $this->schedule('shared', 'tenant-b');
        $from = Carbon::parse('2026-10-20T12:00:00Z');
        $legacyId = 'schedule:shared:backfill:' . $from->getTimestamp();

        // An instance started for this namespace by a release using the original ID form.
        $legacy = WorkflowStub::make(TestScheduledWorkflow::class, $legacyId, 'tenant-b');
        $legacy->start('legacy');

        $results = ScheduleManager::backfill($tenant, $from, Carbon::parse('2026-10-20T13:00:00Z'));

        $this->assertArrayNotHasKey('error', $results[0]);
        $this->assertNotSame($legacyId, $results[0]['instance_id']);
        $this->assertSame('tenant-b', WorkflowInstance::query()->findOrFail($legacyId)->namespace);
        $this->assertSame($legacy->runId(), WorkflowStub::load($legacyId, 'tenant-b')->runId());
    }

    public function testNamespacedInstanceIdsAreUnambiguousAndBounded(): void
    {
        $id = $this->instanceId(...);

        $this->assertSame('schedule:shared:backfill:1', $id('shared', 'default', 'backfill:1'));
        $this->assertSame('schedule:shared:backfill:1', $id('shared', null, 'backfill:1'));

        // Never equal to an original-form ID, whatever the schedule ID contains.
        $namespaced = $id('shared', 'tenant-b', 'backfill:1');
        $this->assertStringStartsWith('schedule@', $namespaced);
        $this->assertNotSame($namespaced, $id(substr($namespaced, strlen('schedule:')), 'default', 'backfill:1'));

        // Namespace and schedule delimiters cannot shift tuple boundaries.
        $this->assertNotSame($id('a:b', 'tenant-b', '1'), $id('a', 'tenant-b', 'b:1'));
        $this->assertNotSame($id('shared', 'tenant-b', '1'), $id('shared', 'tenant-c', '1'));
        $this->assertNotSame($id('shared', 'a:b', '1'), $id('shared', 'a', '1'));

        // Long schedule IDs stay within the 191-byte instance key and stay distinct.
        $long = $id(str_repeat('x', 255), 'tenant-b', 'backfill:1793000000');
        $otherLong = $id(str_repeat('x', 254) . 'y', 'tenant-b', 'backfill:1793000000');
        $this->assertLessThanOrEqual(191, strlen($long));
        $this->assertNotSame($long, $otherLong);
        $this->assertSame($long, $id(str_repeat('x', 255), 'tenant-b', 'backfill:1793000000'));
    }

    private function instanceId(string $scheduleId, ?string $namespace, string $suffix): string
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
