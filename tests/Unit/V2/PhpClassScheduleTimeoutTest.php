<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Facades\Queue;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\V2\TestScheduledWorkflow;
use Tests\TestCase;
use TypeError;
use Workflow\V2\Enums\ScheduleOverlapPolicy;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowSchedule;
use Workflow\V2\StartOptions;
use Workflow\V2\Support\ScheduleManager;
use Workflow\V2\WorkflowStub;

final class PhpClassScheduleTimeoutTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()
            ->set('queue.default', 'database');
        Queue::fake();
        $this->freezeTime();
    }

    public function testManualTriggerPersistsTimeoutsFromPublicCreate(): void
    {
        $schedule = ScheduleManager::create(
            scheduleId: 'manual-timeouts',
            workflowClass: TestScheduledWorkflow::class,
            cronExpression: '* * * * *',
            executionTimeoutSeconds: 120,
            runTimeoutSeconds: 60,
        );

        $instanceId = ScheduleManager::trigger($schedule);
        $this->assertNotNull($instanceId);
        $run = WorkflowRun::query()->findOrFail(WorkflowStub::load($instanceId)->runId());

        $this->assertSame(60, $run->run_timeout_seconds);
        $this->assertTrue($run->execution_deadline_at->equalTo(now()->addSeconds(120)));
        $this->assertTrue($run->run_deadline_at->equalTo(now()->addSeconds(60)));
    }

    public function testTickUsesSpecTimeoutsRelativeToActualStart(): void
    {
        $schedule = ScheduleManager::createFromSpec(
            scheduleId: 'automatic-timeouts',
            spec: [
                'cron_expressions' => ['* * * * *'],
            ],
            action: [
                'workflow_class' => TestScheduledWorkflow::class,
                'execution_timeout_seconds' => 120,
                'run_timeout_seconds' => 60,
            ],
        );
        $schedule->forceFill([
            'next_fire_at' => now()
                ->subMinute(),
        ])->save();

        $results = ScheduleManager::tick();
        $this->assertCount(1, $results);
        $this->assertSame('triggered', $results[0]['outcome']);
        $run = WorkflowRun::query()->findOrFail(WorkflowStub::load($results[0]['instance_id'])->runId());

        $this->assertSame(60, $run->run_timeout_seconds);
        $this->assertTrue($run->execution_deadline_at->equalTo(now()->addSeconds(120)));
        $this->assertTrue($run->run_deadline_at->equalTo(now()->addSeconds(60)));
    }

    #[DataProvider('omittedTimeouts')]
    public function testOmittedAndNullTimeoutsKeepUnlimitedStarts(array $timeouts): void
    {
        $schedule = ScheduleManager::createFromSpec(
            scheduleId: 'unlimited-timeouts',
            spec: [
                'cron_expressions' => ['* * * * *'],
            ],
            action: [
                'workflow_class' => TestScheduledWorkflow::class,
                ...$timeouts,
            ],
        );
        $instanceId = ScheduleManager::trigger($schedule);
        $this->assertNotNull($instanceId);
        $run = WorkflowRun::query()->findOrFail(WorkflowStub::load($instanceId)->runId());

        $this->assertNull($run->run_timeout_seconds);
        $this->assertNull($run->execution_deadline_at);
        $this->assertNull($run->run_deadline_at);
    }

    public static function omittedTimeouts(): array
    {
        return [
            'omitted' => [[]],
            'explicit null' => [[
                'execution_timeout_seconds' => null,
                'run_timeout_seconds' => null,
            ]],
        ];
    }

    public function testUpdatingTimeoutsChangesFutureStartsAndPreservesOriginalDeadlines(): void
    {
        $schedule = ScheduleManager::create(
            scheduleId: 'updated-timeouts',
            workflowClass: TestScheduledWorkflow::class,
            cronExpression: '* * * * *',
            overlapPolicy: ScheduleOverlapPolicy::AllowAll,
            executionTimeoutSeconds: 120,
            runTimeoutSeconds: 60,
        );
        $originalId = ScheduleManager::trigger($schedule);
        $this->assertNotNull($originalId);
        $originalRun = WorkflowRun::query()->findOrFail(WorkflowStub::load($originalId)->runId());
        $executionDeadline = $originalRun->execution_deadline_at->toISOString();
        $runDeadline = $originalRun->run_deadline_at->toISOString();

        $schedule = ScheduleManager::update($schedule, action: [
            ...$schedule->action,
            'execution_timeout_seconds' => 240,
            'run_timeout_seconds' => 90,
        ]);
        $this->travel(1)
            ->seconds();
        $nextId = ScheduleManager::trigger($schedule);
        $this->assertNotNull($nextId);
        $nextRun = WorkflowRun::query()->findOrFail(WorkflowStub::load($nextId)->runId());

        $this->assertSame(90, $nextRun->run_timeout_seconds);
        $this->assertTrue($nextRun->execution_deadline_at->equalTo(now()->addSeconds(240)));
        $this->assertTrue($nextRun->run_deadline_at->equalTo(now()->addSeconds(90)));
        $this->assertSame(60, $originalRun->fresh()->run_timeout_seconds);
        $this->assertSame($executionDeadline, $originalRun->fresh()->execution_deadline_at->toISOString());
        $this->assertSame($runDeadline, $originalRun->fresh()->run_deadline_at->toISOString());
    }

    public function testServiceActionTimeoutRepresentationRemainsUnchanged(): void
    {
        $action = [
            'workflow_type' => 'remote-workflow',
            'execution_timeout_seconds' => '120',
            'run_timeout_seconds' => '60',
        ];
        $schedule = ScheduleManager::createFromSpec(
            scheduleId: 'service-timeouts',
            spec: [
                'cron_expressions' => ['* * * * *'],
            ],
            action: $action,
        );

        $this->assertSame($action, $schedule->fresh()->action);
    }

    #[DataProvider('invalidTimeouts')]
    public function testInvalidCreateAndUpdateUseStartOptionsValidation(string $field, mixed $value): void
    {
        $options = $field === 'execution_timeout_seconds'
            ? [
                'executionTimeoutSeconds' => $value,
            ]
            : [
                'runTimeoutSeconds' => $value,
            ];
        try {
            new StartOptions(...$options);
            $this->fail('The direct start must reject the invalid timeout.');
        } catch (LogicException|TypeError $expected) {
            $errorClass = $expected::class;
        }
        $schedule = ScheduleManager::create(
            scheduleId: 'invalid-timeout',
            workflowClass: TestScheduledWorkflow::class,
            cronExpression: '* * * * *',
        );

        foreach (['create', 'update'] as $operation) {
            try {
                $action = [
                    'workflow_class' => TestScheduledWorkflow::class,
                    $field => $value,
                ];
                if ($operation === 'create') {
                    ScheduleManager::createFromSpec('invalid-create', [
                        'cron_expressions' => ['* * * * *'],
                    ], $action);
                } else {
                    ScheduleManager::update($schedule, action: $action);
                }
                $this->fail('The schedule must reject the invalid timeout.');
            } catch (LogicException|TypeError $actual) {
                $this->assertInstanceOf($errorClass, $actual);
            }
        }

        $this->assertSame(1, WorkflowSchedule::query()->count());
        $this->assertArrayNotHasKey($field, $schedule->fresh()->action);
        $this->assertSame(0, WorkflowRun::query()->count());
    }

    public static function invalidTimeouts(): array
    {
        $cases = [];
        foreach (['execution_timeout_seconds', 'run_timeout_seconds'] as $field) {
            foreach ([0, -1, '60', 1.5, true, []] as $index => $value) {
                $cases[$field . '-' . $index] = [$field, $value];
            }
        }

        return $cases;
    }
}
