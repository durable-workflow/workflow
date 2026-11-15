<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Journal;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\RetainedOperation;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Status;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use LogicException;
use PDO;
use Tests\Fixtures\V2\TestRetainedProviderWorkflow;
use Tests\TestCase;
use Workflow\V2\Compensation\Definition\PortableDescriptor;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\Execution\HandlerRegistry;
use Workflow\V2\Compensation\Execution\OperationContext;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\Compensation\Recovery\ScopeRecovery;
use Workflow\V2\Compensation\Security\ScopeAuthorization;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\WorkflowReplayer;
use Workflow\V2\WorkflowStub;

final class V2RetainedProviderOperationTest extends TestCase
{
    private string $providerPath;

    private ?PDO $providerDb;

    private ?Journal $provider;

    protected function setUp(): void
    {
        parent::setUp();
        self::stopWorkers();
        Queue::fake();
        config([
            'workflows.v2.namespaces' => ['provider-example'],
            'workflows.v2.task_dispatch_mode' => 'queue',
            'queue.default' => 'redis',
        ]);
        $this->providerPath = tempnam(sys_get_temp_dir(), 'native-provider-');
        $this->providerDb = new PDO('sqlite:' . $this->providerPath);
        $this->provider = new Journal($this->providerDb, 'shop-a');
        $this->provider->install();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->app->forgetInstance(HandlerRegistry::class);
        $this->provider = null;
        $this->providerDb = null;
        unlink($this->providerPath);
        parent::tearDown();
    }

    public function testLostCaptureAndRefundAcknowledgementsKeepOneOriginalEffectThroughClosedRunRecovery(): void
    {
        $origin = $this->start();
        $run = $origin->runId();
        $this->provider->plan(
            TestRetainedProviderWorkflow::forward($run),
            Status::Confirmed,
            loseAcknowledgement: true
        );
        $this->provider->plan(TestRetainedProviderWorkflow::refund($run), Status::Confirmed, loseAcknowledgement: true);
        $this->drain();
        $this->assertTrue($origin->refresh()->completed(), $this->progress());
        $this->assertSame('confirmed', WorkflowRun::query()->findOrFail($run)->workflowOutput()['status']);
        $history = $this->history($run);
        $this->assertSame(1, $this->effects());
        $request = app(ScopeRecovery::class)->request(
            TestRetainedProviderWorkflow::scope($run),
            'cancel-1',
            'provider-worker',
            'customer-cancel'
        );
        $this->assertSame('recovery', $request['route']);
        $this->drain();
        $this->assertSame(
            'verified',
            (new ScopeRepository())->metadata(TestRetainedProviderWorkflow::scope($run))['status'],
            $this->progress()
        );
        $this->assertSame(RunStatus::Completed, WorkflowRun::query()->findOrFail($request['runId'])->status);
        $this->assertSame($history, $this->history($run));
        $this->assertSame(2, $this->effects());
        $requests = $this->providerDb->query('SELECT kind FROM provider_requests ORDER BY sequence')
            ->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['command', 'lookup', 'command', 'command'], $requests);
        $beforeReplay = count($requests);
        app(WorkflowReplayer::class)->replay(WorkflowRun::query()->findOrFail($run));
        app(WorkflowReplayer::class)->replay(WorkflowRun::query()->findOrFail($request['runId']));
        $this->assertSame(
            $beforeReplay,
            (int) $this->providerDb->query('SELECT COUNT(*) FROM provider_requests')
                ->fetchColumn()
        );
        $this->assertSame(2, $this->effects());
    }

    public function testAProcessingRefundKeepsItsObligationUntilTheOriginalProviderCompletion(): void
    {
        $origin = $this->start();
        $run = $origin->runId();
        $this->provider->plan(TestRetainedProviderWorkflow::forward($run), Status::Confirmed);
        $this->provider->plan(TestRetainedProviderWorkflow::refund($run), Status::Processing);
        $this->drain();
        $history = $this->history($run);
        $scope = TestRetainedProviderWorkflow::scope($run);
        $first = app(ScopeRecovery::class)->request($scope, 'cancel-pending', 'provider-worker', 'customer-cancel');
        $this->drain();
        $this->assertSame(
            RunStatus::Failed,
            WorkflowRun::query()->findOrFail($first['runId'])->status,
            $this->progress()
        );
        $this->assertSame('requested', (new ScopeRepository())->metadata($scope)['status']);
        $this->assertSame(1, $this->effects());
        $this->provider->resolve(TestRetainedProviderWorkflow::refund($run), Status::Confirmed);
        $again = app(ScopeRecovery::class)->request($scope, 'cancel-reconcile', 'provider-worker', 'customer-cancel');
        $this->drain();
        $this->assertSame('verified', (new ScopeRepository())->metadata($scope)['status'], $this->progress());
        $this->assertSame(RunStatus::Completed, WorkflowRun::query()->findOrFail($again['runId'])->status);
        $this->assertSame(2, $this->effects());
        $this->assertSame($history, $this->history($run));
    }

    private function start(): WorkflowStub
    {
        $stub = WorkflowStub::make(TestRetainedProviderWorkflow::class, 'provider-order-1', 'provider-example');
        $stub->start();
        $run = $stub->runId();
        $scope = TestRetainedProviderWorkflow::scope($run);
        $input = PortableDescriptor::encode(TestRetainedProviderWorkflow::descriptor($run));
        $this->app->instance(ScopeAuthorization::class, new class($scope) implements ScopeAuthorization {
            public function __construct(
                private readonly ScopeIdentity $expected
            ) {
            }

            public function assertAllowed(ScopeIdentity $scope, string $principal, string $operation): string
            {
                if ($scope->toArray() !== $this->expected->toArray() || $principal !== 'provider-worker') {
                    throw new LogicException('The fixture scope or principal was substituted.');
                }
                return 'provider-fixture-policy-v1';
            }
        });
        $this->app->instance(HandlerRegistry::class, new HandlerRegistry([
            'example.provider' => [
                '1' => new RetainedOperation($this->provider, static function (
                    OperationContext $context,
                    string $mode
                ) use ($run, $scope, $input): void {
                    if ($context->scope->toArray() !== $scope->toArray() || $context->originalRunId !== $run
                        || $context->obligation->key !== 'payment.capture' || $context->obligation->handlerVersion !== '1'
                        || PortableDescriptor::encode($context->obligation->input) !== $input
                        || ! in_array($mode, ['forward', 'compensate'], true)) {
                        throw new LogicException('The fixture provider operation has a different original owner.');
                    }
                }),
            ],
        ]));
        return $stub;
    }

    private function effects(): int
    {
        return (int) $this->providerDb->query('SELECT COUNT(*) FROM provider_effects')
            ->fetchColumn();
    }

    private function history(string $run): array
    {
        return WorkflowHistoryEvent::query()->where('workflow_run_id', $run)->orderBy('sequence')->get()
            ->map(
                static fn (WorkflowHistoryEvent $event): array => [$event->event_type->value, $event->payload]
            )->all();
    }

    private function progress(): string
    {
        return json_encode([
            'runs' => WorkflowRun::query()->get(['id', 'status', 'output'])->toArray(),
            'failures' => WorkflowFailure::query()->pluck('message')->all(),
            'tasks' => WorkflowTask::query()->get(['task_type', 'status', 'last_error'])->toArray(),
        ], JSON_THROW_ON_ERROR);
    }

    private function drain(): void
    {
        for ($i = 0; $i < 250; ++$i) {
            $task = WorkflowTask::query()->whereRelation('run', 'namespace', 'provider-example')->where(
                'status',
                TaskStatus::Ready
            )
                ->orderBy('available_at')
                ->orderBy('created_at')
                ->orderBy('id')
                ->first();
            if ($task === null) {
                return;
            }
            if ($task->available_at !== null && $task->available_at->isFuture()) {
                Carbon::setTestNow($task->available_at->copy()->addSecond());
            }
            $job = match ($task->task_type) {
                TaskType::Workflow => new RunWorkflowTask($task->id),
                TaskType::Activity => new RunActivityTask($task->id),
                TaskType::Timer => new RunTimerTask($task->id),
            };
            $this->app->call([$job, 'handle']);
        }
        $this->fail('The bounded native provider task drain did not settle.');
    }
}
