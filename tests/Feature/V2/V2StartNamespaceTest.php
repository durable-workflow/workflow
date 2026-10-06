<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Facades\Queue;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\V2\TestScheduledWorkflow;
use Tests\Fixtures\V2\TestSignalWorkflow;
use Tests\TestCase;
use Workflow\V2\Contracts\WorkflowControlPlane;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\ScheduleManager;
use Workflow\V2\WorkflowStub;

final class V2StartNamespaceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        self::stopWorkers();
        Queue::fake();
        config()
            ->set('workflows.v2.task_dispatch_mode', 'poll');
    }

    public static function namespaces(): array
    {
        return [
            'configured' => ['billing', 'billing'],
            'normalized' => [' billing ', 'billing'],
            'unconfigured' => [null, null],
            'blank' => ['', null],
        ];
    }

    #[DataProvider('namespaces')]
    public function testStubAndControlPlanePersistTheSameNamespace(?string $configured, ?string $expected): void
    {
        config()->set('workflows.v2.namespace', $configured);

        foreach ([null, 'namespace-explicit'] as $instanceId) {
            $stub = WorkflowStub::make(TestScheduledWorkflow::class, $instanceId);
            $result = $stub->start();
            $this->assertNamespace($result->instanceId(), $result->runId(), $expected);

            $control = app(WorkflowControlPlane::class)->start(
                TestScheduledWorkflow::class,
                $instanceId === null ? null : $instanceId . '-control',
            );
            $this->assertTrue($control['started']);
            $this->assertNamespace($control['workflow_instance_id'], $control['workflow_run_id'], $expected);
        }
    }

    public static function scheduleNamespaces(): array
    {
        return [
            'configured' => ['billing', null, 'billing'],
            'explicit override' => ['billing', 'shipping', 'shipping'],
            'unconfigured default' => [null, null, 'default'],
        ];
    }

    #[DataProvider('scheduleNamespaces')]
    public function testPhpScheduleStartsInItsOwnNamespace(
        ?string $configured,
        ?string $explicit,
        string $expected
    ): void {
        config()->set('workflows.v2.namespace', $configured);

        $schedule = ScheduleManager::create(
            scheduleId: 'namespace-schedule',
            workflowClass: TestScheduledWorkflow::class,
            cronExpression: '0 * * * *',
            namespace: $explicit,
        );

        $this->assertSame($expected, $schedule->namespace);
        $instanceId = ScheduleManager::trigger($schedule);
        $instance = WorkflowInstance::query()->findOrFail($instanceId);
        $this->assertNamespace($instance->id, $instance->current_run_id, $expected);
    }

    public static function namespaceConflicts(): array
    {
        return [
            'different tenant' => ['billing', 'shipping'],
            'existing unscoped instance' => [null, 'billing'],
            'unscoped request for existing tenant' => ['billing', null],
        ];
    }

    #[DataProvider('namespaceConflicts')]
    public function testAStartCannotReassignAnExistingInstance(?string $original, ?string $requested): void
    {
        config()->set('workflows.v2.namespace', $original);
        $result = app(WorkflowControlPlane::class)->start(TestScheduledWorkflow::class, 'namespace-reserved');
        $this->assertTrue($result['started']);
        $commandCount = WorkflowCommand::query()->count();
        $historyCount = WorkflowHistoryEvent::query()->count();

        config()
            ->set('workflows.v2.namespace', $requested);

        $errors = [];

        foreach (['stub', 'control'] as $api) {
            try {
                if ($api === 'stub') {
                    WorkflowStub::make(TestScheduledWorkflow::class, 'namespace-reserved');
                } else {
                    app(WorkflowControlPlane::class)->start(TestScheduledWorkflow::class, 'namespace-reserved');
                }

                $this->fail('A conflicting namespace was accepted by ' . $api . '.');
            } catch (LogicException $error) {
                $errors[] = $error->getMessage();
            }

            $this->assertNamespace('namespace-reserved', $result['workflow_run_id'], $original);
            $this->assertSame(1, WorkflowRun::query()->where('workflow_instance_id', 'namespace-reserved')->count());
            $this->assertSame($commandCount, WorkflowCommand::query()->count());
            $this->assertSame($historyCount, WorkflowHistoryEvent::query()->count());
        }

        foreach ($errors as $message) {
            $this->assertStringContainsString('namespace', $message);
        }
    }

    public function testMatchingNamespaceCanReuseItsReservation(): void
    {
        config()->set('workflows.v2.namespace', 'billing');
        $first = WorkflowStub::make(TestScheduledWorkflow::class, 'namespace-matching');
        $second = WorkflowStub::make(TestScheduledWorkflow::class, 'namespace-matching');

        $this->assertSame($first->id(), $second->id());
        $this->assertSame('billing', WorkflowInstance::query()->findOrFail($second->id())->namespace);
        $this->assertSame(0, WorkflowRun::query()->count());
    }

    public function testSignalWithStartKeepsTheReservedNamespace(): void
    {
        config()->set('workflows.v2.namespace', 'billing');
        $stub = WorkflowStub::make(TestSignalWorkflow::class, 'namespace-signal-start');

        $result = $stub->signalWithStart('name-provided', ['Ada']);

        $this->assertTrue($result->accepted());
        $this->assertNamespace($stub->id(), $stub->runId(), 'billing');
    }

    public function testAnExplicitNamespaceOverridesConfigurationThroughEitherApi(): void
    {
        config()->set('workflows.v2.namespace', 'billing');
        $stub = WorkflowStub::make(TestScheduledWorkflow::class, 'namespace-override', namespace: ' shipping ');
        $result = $stub->start();
        $this->assertNamespace($result->instanceId(), $result->runId(), 'shipping');

        $control = app(WorkflowControlPlane::class)->start(TestScheduledWorkflow::class, 'namespace-override-control', [
            'namespace' => ' shipping ',
        ]);
        $this->assertTrue($control['started']);
        $this->assertNamespace($control['workflow_instance_id'], $control['workflow_run_id'], 'shipping');
    }

    public function testLoadingAnExistingReservationPreservesItsNamespaceAfterConfigurationChanges(): void
    {
        config()->set('workflows.v2.namespace', 'billing');
        $stub = WorkflowStub::make(TestScheduledWorkflow::class, 'namespace-before-config-change');
        config()
            ->set('workflows.v2.namespace', 'shipping');

        $result = WorkflowStub::load($stub->id(), 'billing')->start();

        $this->assertNamespace($result->instanceId(), $result->runId(), 'billing');
    }

    private function assertNamespace(string $instanceId, string $runId, ?string $expected): void
    {
        $this->assertSame($expected, WorkflowInstance::query()->findOrFail($instanceId)->namespace);
        $this->assertSame($expected, WorkflowRun::query()->findOrFail($runId)->namespace);
        $this->assertSame($expected, WorkflowRunSummary::query()->findOrFail($runId)->namespace);
        $tasks = WorkflowTask::query()->where('workflow_run_id', $runId)->get();
        $this->assertCount(1, $tasks);
        $this->assertSame($expected, $tasks->sole()->namespace);
    }
}
