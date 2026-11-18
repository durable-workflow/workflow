<?php

declare(strict_types=1);

namespace Tests\Support;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Journal;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\UnitRefundGateway;
use DurableWorkflow\Examples\ProviderStateMachines\Authority;
use DurableWorkflow\Examples\ProviderStateMachines\Catalogue;
use DurableWorkflow\Examples\ProviderStateMachines\MachineStore;
use DurableWorkflow\Examples\ProviderStateMachines\StockWarehouse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use LogicException;
use Orchestra\Testbench\TestCase;
use PDO;
use ReflectionProperty;
use Workflow\Providers\WorkflowServiceProvider;
use Workflow\V2\Compensation\Execution\ForwardResult;
use Workflow\V2\Compensation\Execution\HandlerRegistry;
use Workflow\V2\Compensation\Execution\OperationContext;
use Workflow\V2\Compensation\Execution\Resolution;
use Workflow\V2\Compensation\Execution\ScopedOperation;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\TaskWatchdog;

require_once __DIR__ . '/../../examples/provider-state-machines/bootstrap.php';

/**
 * A fresh worker for the common payment-first checkout. The crash phase dies with SIGKILL inside the
 * selected native operation after its provider effect commits and before DW records the outcome.
 * The resume phase is a new process: the production watchdog repairs the abandoned lease.
 */
final class CheckoutProcessLossProcessTest extends TestCase
{
    public function testRunTheOriginalCheckoutWorker(): void
    {
        $input = $this->input();
        Carbon::setTestNow($input['clock']);
        Queue::fake();
        $store = $this->configure($input);
        if ($input['phase'] === 'crash') {
            $this->armCut($input);
        } else {
            // A replacement worker starts after the abandoned activity lease expired.
            $leases = WorkflowTask::query()->where('status', TaskStatus::Leased)->pluck('lease_expires_at')->filter();
            if ($leases->isNotEmpty()) {
                Carbon::setTestNow($leases->max()->copy()->addSecond());
            }
        }
        for ($step = 0; $step < 800; ++$step) {
            $state = $store->authority->subject($input['key'], 'order')->state;
            $idle = $input['phase'] === 'resume' && $this->idle();
            if ($input['phase'] === 'resume' && ($state === $input['until'] || $idle)) {
                // Idle means native recovery ended without settlement; the parent asserts which applies.
                file_put_contents($input['report'], json_encode([
                    'pid' => getmypid(),
                    'state' => $state,
                    'idle' => $state !== $input['until'],
                    'clock' => now()
                        ->toISOString(),
                ], JSON_THROW_ON_ERROR));
                return;
            }
            $this->nextTask();
        }
        $this->fail('The bounded original checkout worker did not reach its selected cut or outcome.');
    }

    protected function getPackageProviders($app): array
    {
        return [WorkflowServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $input = $this->input();
        $app['config']->set([
            'workflows.v2.namespaces' => [Catalogue::NAMESPACE],
            'workflows.v2.task_dispatch_mode' => 'queue',
            'database.default' => 'checkout_process',
            'database.connections.checkout_process' => $input['database'],
            'database.redis' => $input['redis'],
            'workflows.storage.connection' => 'checkout_process',
            'queue.default' => 'redis',
            'cache' => $input['cache'],
            'logging.default' => 'null',
        ]);
    }

    private function configure(array $input): MachineStore
    {
        $db = new PDO('sqlite:' . $input['provider']);
        $provider = new Journal($db, 'shop-a');
        $key = $input['key'];
        $authority = new Authority(DB::connection(), 'provider-worker', [$key], [
            $key => Catalogue::PAYMENT_FIRST,
        ], unitAmounts: [
            $key => $input['unitAmounts'],
        ], stockPolicies: [
            $key => $input['policy'],
        ]);
        return MachineStore::configure(
            $this->app,
            $authority,
            $provider,
            unitRefunds: new UnitRefundGateway($db, 'shop-a'),
            warehouse: new StockWarehouse($db)
        );
    }

    private function armCut(array $input): void
    {
        $handlers = (new ReflectionProperty(HandlerRegistry::class, 'handlers'))->getValue(
            app(HandlerRegistry::class)
        );
        $target = $handlers[$input['handler']]['1'] ?? throw new LogicException('The cut handler is unavailable.');
        $handlers[$input['handler']]['1'] = new class(
            $target,
            $input['mode'],
            $input['report']
        ) implements ScopedOperation {
            public function __construct(
                private readonly ScopedOperation $inner,
                private readonly string $mode,
                private readonly string $report,
            ) {
            }

            public function assertInput(array $input): void
            {
                $this->inner->assertInput($input);
            }

            public function execute(OperationContext $context): ForwardResult
            {
                $result = $this->inner->execute($context);
                if ($this->mode === 'forward' && $result->observed) {
                    $this->stop($context);
                }
                return $result;
            }

            public function compensate(OperationContext $context): Resolution
            {
                $result = $this->inner->compensate($context);
                if ($this->mode === 'compensate' && $result->verified) {
                    $this->stop($context);
                }
                return $result;
            }

            private function stop(OperationContext $context): never
            {
                // The provider committed and acknowledged; no DW outcome is returned or recorded.
                file_put_contents($this->report, json_encode([
                    'pid' => getmypid(),
                    'scope' => $context->scope->toArray(),
                    'operationId' => $context->operationId,
                    'attemptId' => $context->attemptId,
                    'clock' => now()
                        ->toISOString(),
                ], JSON_THROW_ON_ERROR));
                posix_kill(getmypid(), SIGKILL);
                throw new LogicException('The worker-loss signal did not terminate this process.');
            }
        };
        $this->app->instance(HandlerRegistry::class, new HandlerRegistry($handlers));
    }

    private function nextTask(): void
    {
        $task = $this->readyTask();
        if ($task === null) {
            TaskWatchdog::runPass(respectThrottle: false);
            $task = $this->readyTask();
        }
        $this->assertNotNull($task, 'The original checkout has no runnable task before its required outcome. '
            . json_encode([
                'now' => now()
                    ->toISOString(),
                'tasks' => WorkflowTask::query()->whereNotIn('status', [TaskStatus::Completed])->get()->map(
                    static fn (WorkflowTask $open): array => [
                        $open->task_type->value, $open->status->value, $open->run?->workflow_class,
                        $open->run?->status?->value, $open->run?->namespace, $open->available_at?->toISOString(),
                        $open->lease_expires_at?->toISOString(), $open->last_error,
                    ]
                )->all(),
                'runs' => \Workflow\V2\Models\WorkflowRun::query()->get()->map(
                    static fn ($run): array => [$run->workflow_class, $run->status->value, $run->namespace]
                )->all(),
            ]));
        if ($task->available_at !== null && $task->available_at->isFuture()) {
            Carbon::setTestNow($task->available_at->copy()->addSecond());
        }
        $job = match ($task->task_type) {
            TaskType::Workflow => new RunWorkflowTask($task->id),
            TaskType::Activity => new RunActivityTask($task->id),
            TaskType::Timer => new RunTimerTask($task->id),
        };
        try {
            $this->app->call([$job, 'handle']);
        } catch (\RuntimeException $error) {
            // Like a queue worker: the engine already recorded this activity attempt's failure or retry.
            if ($task->task_type !== TaskType::Activity) {
                throw $error;
            }
        }
    }

    private function idle(): bool
    {
        if ($this->readyTask() !== null) {
            return false;
        }
        TaskWatchdog::runPass(respectThrottle: false);
        return $this->readyTask() === null
            && ! WorkflowTask::query()->where('status', TaskStatus::Leased)->exists();
    }

    private function readyTask(): ?WorkflowTask
    {
        return WorkflowTask::query()->whereRelation('run', 'namespace', Catalogue::NAMESPACE)
            ->where('status', TaskStatus::Ready)->orderBy('available_at')->orderBy('created_at')->orderBy('id')
            ->first();
    }

    private function input(): array
    {
        $path = getenv('DWF_CHECKOUT_PROCESS_INPUT');
        if (! is_string($path) || $path === '') {
            $this->markTestSkipped('Invoked only by the common checkout process-loss control.');
        }
        return json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }
}
