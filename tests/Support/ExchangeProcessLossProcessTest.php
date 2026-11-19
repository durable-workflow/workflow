<?php

declare(strict_types=1);

namespace Tests\Support;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Journal;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\UnitRefundGateway;
use DurableWorkflow\Examples\ProviderStateMachines\Authority;
use DurableWorkflow\Examples\ProviderStateMachines\Catalogue;
use DurableWorkflow\Examples\ProviderStateMachines\ExchangeStore;
use DurableWorkflow\Examples\ProviderStateMachines\FinancialCatalogue;
use DurableWorkflow\Examples\ProviderStateMachines\MachineStore;
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
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\TaskWatchdog;

require_once __DIR__ . '/../../examples/provider-state-machines/bootstrap.php';

/**
 * A fresh worker for one same-price exchange. The crash phase dies with SIGKILL inside the selected
 * exchange operation after its provider effect commits and before DW records the outcome. The resume
 * phase is a new process: the production watchdog repairs the abandoned lease.
 */
final class ExchangeProcessLossProcessTest extends TestCase
{
    public function testRunTheOriginalExchangeWorker(): void
    {
        $input = $this->input();
        Carbon::setTestNow($input['clock']);
        Queue::fake();
        $this->configure($input);
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
            $run = $this->exchangeRun($input);
            if ($input['phase'] === 'resume' && ($run->status->isTerminal() || $this->idle())) {
                file_put_contents($input['report'], json_encode([
                    'pid' => getmypid(),
                    'status' => $run->fresh()
                        ->status->value,
                    'clock' => now()
                        ->toISOString(),
                ], JSON_THROW_ON_ERROR));
                return;
            }
            $this->nextTask();
        }
        $this->fail('The bounded original exchange worker did not reach its selected cut or outcome.');
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
            'database.default' => 'exchange_process',
            'database.connections.exchange_process' => $input['database'],
            'database.redis' => $input['redis'],
            'workflows.storage.connection' => 'exchange_process',
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
            $key => FinancialCatalogue::AFTER_STOCK,
        ], unitAmounts: [
            $key => $input['unitAmounts'],
        ]);
        return MachineStore::configure(
            $this->app,
            $authority,
            $provider,
            unitRefunds: new UnitRefundGateway($db, 'shop-a')
        );
    }

    private function exchangeRun(array $input): WorkflowRun
    {
        $row = DB::table(ExchangeStore::TABLE)->where(
            'id',
            ExchangeStore::id($input['key'], $input['exchangeKey'])
        )->sole();
        return WorkflowRun::query()->findOrFail($row->native_run);
    }

    private function armCut(array $input): void
    {
        $handlers = (new ReflectionProperty(HandlerRegistry::class, 'handlers'))->getValue(
            app(HandlerRegistry::class)
        );
        $target = $handlers[$input['handler']]['1'] ?? throw new LogicException('The cut handler is unavailable.');
        $handlers[$input['handler']]['1'] = new class($target, $input['report']) implements ScopedOperation {
            public function __construct(
                private readonly ScopedOperation $inner,
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
                if ($result->observed) {
                    // The provider committed and acknowledged; no DW outcome is returned or recorded.
                    file_put_contents($this->report, json_encode([
                        'pid' => getmypid(),
                        'scope' => $context->scope->toArray(),
                        'operationId' => $context->operationId,
                        'clock' => now()
                            ->toISOString(),
                    ], JSON_THROW_ON_ERROR));
                    posix_kill(getmypid(), SIGKILL);
                    throw new LogicException('The worker-loss signal did not terminate this process.');
                }
                return $result;
            }

            public function compensate(OperationContext $context): Resolution
            {
                return $this->inner->compensate($context);
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
        $this->assertNotNull($task, 'The original exchange has no runnable task before its required outcome.');
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
        $path = getenv('DWF_EXCHANGE_PROCESS_INPUT');
        if (! is_string($path) || $path === '') {
            $this->markTestSkipped('Invoked only by the exchange process-loss control.');
        }
        return json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }
}
