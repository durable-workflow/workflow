<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Carbon;
use LogicException;
use ReflectionProperty;
use RuntimeException;
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

/**
 * Worker side of the real process-loss controls. The crash phase dies with SIGKILL inside one selected native
 * operation after its provider effect commits and before DW records the outcome, or (mode "before") after DW
 * admitted the forward attempt and before the provider is called. A resume phase is a new process: the
 * production watchdog repairs the abandoned lease, like a replacement queue worker.
 */
trait WorkerLossDriver
{
    private function workerLossInput(string $variable): array
    {
        $path = getenv($variable);
        if (! is_string($path) || $path === '') {
            $this->markTestSkipped('Invoked only by its parent process-loss control.');
        }
        return json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    private function workerLossEnvironment($app, array $input, string $namespace): void
    {
        $app['config']->set([
            'workflows.v2.namespaces' => [$namespace],
            'workflows.v2.task_dispatch_mode' => 'queue',
            'database.default' => 'worker_loss',
            'database.connections.worker_loss' => $input['database'],
            'database.redis' => $input['redis'],
            'workflows.storage.connection' => 'worker_loss',
            'queue.default' => 'redis',
            'cache' => $input['cache'],
            'logging.default' => 'null',
        ]);
    }

    /**
     * A replacement worker starts after every abandoned activity lease expired, and never before $notBefore.
     */
    private function startReplacementWorker(?int $notBefore = null): void
    {
        $leases = WorkflowTask::query()->where('status', TaskStatus::Leased)->pluck('lease_expires_at')->filter();
        if ($leases->isNotEmpty() && $leases->max()->isFuture()) {
            Carbon::setTestNow($leases->max()->copy()->addSecond());
        }
        if ($notBefore !== null && Carbon::now()->getTimestamp() < $notBefore) {
            Carbon::setTestNow(Carbon::createFromTimestampUTC($notBefore));
        }
    }

    /**
     * Wraps one registered handler. With $action, only an obligation whose original forward request has that
     * provider action is cut; other operations of the same handler run normally.
     */
    private function armWorkerLoss(string $handler, string $mode, string $report, ?string $action = null): void
    {
        $handlers = (new ReflectionProperty(HandlerRegistry::class, 'handlers'))->getValue(
            app(HandlerRegistry::class)
        );
        $target = $handlers[$handler]['1'] ?? throw new LogicException('The cut handler is unavailable.');
        $handlers[$handler]['1'] = new class($target, $mode, $report, $action) implements ScopedOperation {
            public function __construct(
                private readonly ScopedOperation $inner,
                private readonly string $mode,
                private readonly string $report,
                private readonly ?string $action,
            ) {
            }

            public function assertInput(array $input): void
            {
                $this->inner->assertInput($input);
            }

            public function execute(OperationContext $context): ForwardResult
            {
                if ($this->mode === 'before' && $this->selected($context)) {
                    // DW admitted this forward attempt; no provider request was sent.
                    $this->stop($context);
                }
                $result = $this->inner->execute($context);
                if ($this->mode === 'forward' && $result->observed && $this->selected($context)) {
                    $this->stop($context);
                }
                return $result;
            }

            public function compensate(OperationContext $context): Resolution
            {
                $result = $this->inner->compensate($context);
                if ($this->mode === 'compensate' && $result->verified && $this->selected($context)) {
                    $this->stop($context);
                }
                return $result;
            }

            private function selected(OperationContext $context): bool
            {
                return $this->action === null
                    || ($context->obligation->input['forward']['action'] ?? null) === $this->action;
            }

            private function stop(OperationContext $context): never
            {
                // No DW outcome is returned or recorded for the provider effect (if any) of this attempt.
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

    private function runNextTask(string $namespace): void
    {
        $task = $this->readyTaskIn($namespace);
        if ($task === null || $task->available_at?->isFuture()) {
            // A live worker repairs abandoned leases before business time moves on to a later timer.
            TaskWatchdog::runPass(respectThrottle: false);
            $task = $this->readyTaskIn($namespace);
        }
        $this->assertNotNull($task, 'The original worker has no runnable task before its required outcome. '
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
                'runs' => WorkflowRun::query()->get()->map(
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
        } catch (RuntimeException $error) {
            // Like a queue worker: the engine already recorded this activity attempt's failure or retry.
            if ($task->task_type !== TaskType::Activity) {
                throw $error;
            }
        }
    }

    private function workerIdle(string $namespace): bool
    {
        if ($this->readyTaskIn($namespace) !== null) {
            return false;
        }
        TaskWatchdog::runPass(respectThrottle: false);
        return $this->readyTaskIn($namespace) === null
            && ! WorkflowTask::query()->where('status', TaskStatus::Leased)->exists();
    }

    /**
     * Like workerIdle(), but later business timers may stay pending: nothing is leased and nothing is due
     * within the ordinary short scheduling horizon.
     */
    private function workerCurrent(string $namespace, int $horizonSeconds = 30): bool
    {
        $due = static function () use ($namespace, $horizonSeconds): bool {
            $through = Carbon::now()->copy()->addSeconds($horizonSeconds)->format('Y-m-d H:i:s.u');
            return WorkflowTask::query()->whereRelation('run', 'namespace', $namespace)
                ->where('status', TaskStatus::Ready)
                ->where(static function ($query) use ($through): void {
                    $query->whereNull('available_at')
                        ->orWhere('available_at', '<=', $through);
                })->exists();
        };
        if ($due()) {
            return false;
        }
        TaskWatchdog::runPass(respectThrottle: false);
        return ! $due() && ! WorkflowTask::query()->where('status', TaskStatus::Leased)->exists();
    }

    /**
     * Due work first; a later task only when nothing is due.
     */
    private function readyTaskIn(string $namespace): ?WorkflowTask
    {
        $ready = static fn () => WorkflowTask::query()->whereRelation('run', 'namespace', $namespace)
            ->where('status', TaskStatus::Ready);
        return $ready()
            ->where(static function ($query): void {
                $query->whereNull('available_at')
                    ->orWhere('available_at', '<=', Carbon::now()->format('Y-m-d H:i:s.u'));
            })->orderBy('available_at')
            ->orderBy('created_at')
            ->orderBy('id')
            ->first()
            ?? $ready()
                ->orderBy('available_at')
                ->orderBy('created_at')
                ->orderBy('id')
                ->first();
    }
}
