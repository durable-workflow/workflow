<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Fixtures\V2\TestValidatedUpdateWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Exceptions\UpdateValidationFailedException;
use Workflow\V2\Interceptors\PassthroughWorkerInterceptor;
use Workflow\V2\Interceptors\UpdateValidationInterceptorContext;
use Workflow\V2\Interceptors\UpdateValidationOutcome;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowUpdate;
use Workflow\V2\WorkflowStub;

/**
 * Worker interceptors observe update validation; they cannot change its
 * decision, skip it, or make a refusal record anything (#226, #234).
 */
final class V2UpdateValidationInterceptorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        TestValidatedUpdateWorkflow::$validatorCalls = 0;
        TestValidatedUpdateWorkflow::$handlerCalls = 0;
        ValidationAudit::$events = [];
        ValidationAudit::$contexts = [];
        ValidationAudit::$outcomes = [];
    }

    protected function tearDown(): void
    {
        config()->set('workflows.v2.interceptors', []);

        parent::tearDown();
    }

    public function testHooksWrapValidationInOrderAndObserveItsOutcome(): void
    {
        $this->interceptors([new ObservingValidationInterceptor('outer'), new ObservingValidationInterceptor('inner')]);
        $workflow = $this->startedWorkflow('validation-interceptor-observe');

        $rejected = $workflow->attemptUpdate('add-item', '');
        $accepted = $workflow->attemptUpdate('add-item', 'apple');

        $this->assertTrue($rejected->rejectedByValidator());
        $this->assertTrue($accepted->accepted());
        $this->assertSame([
            'outer:before', 'inner:before', 'inner:after', 'outer:after',
            'outer:before', 'inner:before', 'inner:after', 'outer:after',
        ], ValidationAudit::$events);

        $context = ValidationAudit::$contexts[0];
        $this->assertNull($context->workerId);
        $this->assertSame('add-item', $context->updateName);
        $this->assertSame(\Workflow\V2\Enums\WorkflowReplayState::Replaying, $context->replayState());
        $this->assertSame('test-validated-update-workflow', $context->workflowType);
        $this->assertSame('validation-interceptor-observe', $context->workflowId);
        $this->assertSame($workflow->runId(), $context->runId);
        $this->assertSame([''], $context->arguments);

        $this->assertFalse(ValidationAudit::$outcomes[0]->approved);
        $this->assertSame('The item is required.', ValidationAudit::$outcomes[0]->rejectionMessage);
        $this->assertSame([
            'item' => ['The item is required.'],
        ], ValidationAudit::$outcomes[0]->validationErrors);
        $this->assertTrue(ValidationAudit::$outcomes[2]->approved);
    }

    public function testAHookCannotOverturnTheValidatorsDecision(): void
    {
        $this->interceptors([new OverridingValidationInterceptor()]);
        $workflow = $this->startedWorkflow('validation-interceptor-override');

        $result = $workflow->attemptUpdate('add-item', '');

        $this->assertTrue($result->rejectedByValidator());
        $this->assertSame('The item is required.', $result->rejectionMessage());
        $this->assertSame(0, $this->eventCount($workflow->runId(), 'UpdateAccepted'));
        $this->assertSame(0, TestValidatedUpdateWorkflow::$handlerCalls);
    }

    public function testAHookThatSkipsOrThrowsRefusesTheUpdateAndRecordsNothing(): void
    {
        foreach ([
            'skip' => new SkippingValidationInterceptor(),
            'throw' => new ThrowingValidationInterceptor(),
            'twice' => new TwiceValidationInterceptor(),
        ] as $case => $interceptor) {
            $this->interceptors([$interceptor]);
            $workflow = $this->startedWorkflow("validation-interceptor-{$case}");
            $runId = (string) $workflow->runId();
            $calls = TestValidatedUpdateWorkflow::$validatorCalls;

            try {
                $workflow->attemptUpdate('add-item', 'apple');
                $this->fail("{$case}: the update must be refused.");
            } catch (UpdateValidationFailedException $exception) {
                $this->assertSame('add-item', $exception->updateName(), $case);
                $this->assertStringContainsString('update-validation interceptor', $exception->getMessage(), $case);
            }

            $this->assertSame(0, WorkflowUpdate::query()->where('workflow_run_id', $runId)->count(), $case);
            $this->assertSame(0, WorkflowCommand::query()
                ->where('workflow_run_id', $runId)
                ->where('command_type', 'update')
                ->count(), $case);
            $this->assertSame(0, $this->eventCount($runId, 'UpdateAccepted'), $case);
            $this->assertSame(0, $this->eventCount($runId, 'UpdateRejected'), $case);
            $this->assertSame(0, TestValidatedUpdateWorkflow::$handlerCalls, $case);
            $this->assertLessThanOrEqual($calls + 1, TestValidatedUpdateWorkflow::$validatorCalls, $case);
        }
    }

    public function testUpdatesWithoutAValidatorDoNotRunTheHook(): void
    {
        $this->interceptors([new ObservingValidationInterceptor('only')]);
        $workflow = WorkflowStub::make(\Tests\Fixtures\V2\TestUpdateWorkflow::class, 'validation-interceptor-plain');
        $workflow->start();
        $this->runReadyWorkflowTask((string) $workflow->runId());

        $this->assertTrue($workflow->attemptUpdate('approve', true)->accepted());
        $this->assertSame([], ValidationAudit::$events);
    }

    /**
     * @param list<PassthroughWorkerInterceptor> $interceptors
     */
    private function interceptors(array $interceptors): void
    {
        config()
            ->set('workflows.v2.interceptors', $interceptors);
    }

    private function startedWorkflow(string $id): WorkflowStub
    {
        $workflow = WorkflowStub::make(TestValidatedUpdateWorkflow::class, $id);
        $workflow->start(2);
        $this->runReadyWorkflowTask((string) $workflow->runId());

        return $workflow;
    }

    private function eventCount(string $runId, string $type): int
    {
        return WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->where('event_type', $type)
            ->count();
    }

    private function runReadyWorkflowTask(string $runId): void
    {
        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->orderBy('created_at')
            ->firstOrFail();

        $this->app->call([new RunWorkflowTask($task->id), 'handle']);
    }
}

final class ValidationAudit
{
    /**
     * @var list<string>
     */
    public static array $events = [];

    /**
     * @var list<UpdateValidationInterceptorContext>
     */
    public static array $contexts = [];

    /**
     * @var list<UpdateValidationOutcome>
     */
    public static array $outcomes = [];
}

final class ObservingValidationInterceptor extends PassthroughWorkerInterceptor
{
    public function __construct(
        private readonly string $name,
    ) {
    }

    public function executeUpdateValidation(
        UpdateValidationInterceptorContext $context,
        callable $next,
    ): UpdateValidationOutcome {
        ValidationAudit::$events[] = "{$this->name}:before";
        ValidationAudit::$contexts[] = $context;
        $outcome = $next($context);
        ValidationAudit::$outcomes[] = $outcome;
        ValidationAudit::$events[] = "{$this->name}:after";

        return $outcome;
    }
}

final class OverridingValidationInterceptor extends PassthroughWorkerInterceptor
{
    public function executeUpdateValidation(
        UpdateValidationInterceptorContext $context,
        callable $next,
    ): UpdateValidationOutcome {
        $next(new UpdateValidationInterceptorContext(
            $context->workerId,
            $context->connection,
            $context->taskQueue,
            $context->namespace,
            $context->updateName,
            $context->workflowType,
            $context->workflowId,
            $context->runId,
            ['overridden'],
        ));

        return new UpdateValidationOutcome(true);
    }
}

final class SkippingValidationInterceptor extends PassthroughWorkerInterceptor
{
    public function executeUpdateValidation(
        UpdateValidationInterceptorContext $context,
        callable $next,
    ): UpdateValidationOutcome {
        return new UpdateValidationOutcome(true);
    }
}

final class ThrowingValidationInterceptor extends PassthroughWorkerInterceptor
{
    public function executeUpdateValidation(
        UpdateValidationInterceptorContext $context,
        callable $next,
    ): UpdateValidationOutcome {
        throw new RuntimeException('audit store unavailable');
    }
}

final class TwiceValidationInterceptor extends PassthroughWorkerInterceptor
{
    public function executeUpdateValidation(
        UpdateValidationInterceptorContext $context,
        callable $next,
    ): UpdateValidationOutcome {
        $next($context);

        return $next($context);
    }
}
