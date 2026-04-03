<?php

declare(strict_types=1);

namespace Workflow;

use BadMethodCallException;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use React\Promise\PromiseInterface;
use Throwable;
use Workflow\Events\WorkflowCompleted;
use Workflow\Exceptions\TransitionNotFound;
use Workflow\Middleware\WithoutOverlappingMiddleware;
use Workflow\Models\StoredWorkflow;
use Workflow\Serializers\Serializer;
use Workflow\States\WorkflowCompletedStatus;
use Workflow\States\WorkflowContinuedStatus;
use Workflow\States\WorkflowRunningStatus;
use Workflow\States\WorkflowWaitingStatus;
use Workflow\Traits\ResolvesMethodDependencies;
use Workflow\Traits\Sagas;
use Workflow\Traits\SerializesModels;

class Workflow implements ShouldBeEncrypted, ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use ResolvesMethodDependencies;
    use Sagas;
    use SerializesModels;

    public ?string $key = null;

    public int $tries = 0;

    public int $maxExceptions = 0;

    public $timeout = 0;

    public $arguments;

    public $coroutine;

    public int $index = 0;

    public $now;

    public bool $replaying = false;

    public Inbox $inbox;

    public Outbox $outbox;

    public array $updateMethodSignals = [];

    public bool $outboxWasConsumed = false;

    private Container $container;

    public function __construct(
        public StoredWorkflow $storedWorkflow,
        ...$arguments
    ) {
        $this->inbox = new Inbox();

        $this->outbox = new Outbox();

        $this->arguments = $arguments;

        $connection = $this->storedWorkflow->effectiveConnection();

        if ($connection !== null) {
            $this->onConnection($connection);
        } elseif (property_exists($this, 'connection')) {
            $this->onConnection($this->connection);
        }

        $queue = $this->storedWorkflow->effectiveQueue();

        if ($queue !== null) {
            $this->onQueue($queue);
        } elseif (property_exists($this, 'queue')) {
            $this->onQueue($this->queue);
        }

        $this->afterCommit = true;
    }

    public function uniqueId()
    {
        return $this->storedWorkflow->id;
    }

    public function query($method)
    {
        $this->replaying = true;
        $this->handle();

        foreach ($this->updateMethodSignals as $signal) {
            $this->{$signal->method}(...Serializer::unserialize($signal->arguments));
        }

        $sentBefore = $this->outbox->sent;
        $result = $this->{$method}();
        $this->outboxWasConsumed = $this->outbox->sent > $sentBefore;

        return $result;
    }

    public function child(): ?ChildWorkflowHandle
    {
        $storedChild = $this->storedWorkflow->children()
            ->wherePivot('parent_index', '<', WorkflowStub::getContext()->index)
            ->orderByDesc('child_workflow_id')
            ->first();

        return $storedChild ? new ChildWorkflowHandle($storedChild) : null;
    }

    public function children(): array
    {
        return $this->storedWorkflow->children()
            ->wherePivot('parent_index', '<', WorkflowStub::getContext()->index)
            ->orderByDesc('child_workflow_id')
            ->get()
            ->map(static fn ($child) => new ChildWorkflowHandle($child))
            ->toArray();
    }

    public function middleware()
    {
        $parentWorkflow = $this->storedWorkflow->parents()
            ->wherePivot('parent_index', '!=', StoredWorkflow::CONTINUE_PARENT_INDEX)
            ->wherePivot('parent_index', '!=', StoredWorkflow::ACTIVE_WORKFLOW_INDEX)
            ->first();

        if ($parentWorkflow) {
            return [
                new WithoutOverlappingMiddleware($parentWorkflow->id, WithoutOverlappingMiddleware::ACTIVITY, 0, 15),
            ];
        }
        return [
            new WithoutOverlappingMiddleware($this->storedWorkflow->id, WithoutOverlappingMiddleware::WORKFLOW, 0, 15),
        ];
    }

    public function failed(Throwable $throwable): void
    {
        try {
            $this->storedWorkflow->toWorkflow()
                ->fail($throwable);
        } catch (TransitionNotFound) {
            return;
        }
    }

    public function handle(): void
    {
        if (! method_exists($this, 'execute')) {
            throw new BadMethodCallException('Execute method not implemented.');
        }

        $this->container = App::make(Container::class);

        try {
            if (! $this->replaying) {
                $this->storedWorkflow->status->transitionTo(WorkflowRunningStatus::class);
            }
        } catch (TransitionNotFound) {
            if ($this->storedWorkflow->toWorkflow()->running()) {
                $this->release();
            }
            return;
        }

        $parentWorkflow = $this->storedWorkflow->parents()
            ->wherePivot('parent_index', '!=', StoredWorkflow::CONTINUE_PARENT_INDEX)
            ->wherePivot('parent_index', '!=', StoredWorkflow::ACTIVE_WORKFLOW_INDEX)
            ->first();

        $this->storedWorkflow->loadMissing(['logs', 'signals']);

        $log = $this->storedWorkflow->findLogByIndex($this->index);

        $this->storedWorkflow
            ->signals()
            ->orderBy('created_at')
            ->each(function ($signal): void {
                if (WorkflowStub::isUpdateMethod($this->storedWorkflow->class, $signal->method)) {
                    $this->updateMethodSignals[] = $signal;
                    return;
                }
                $this->{$signal->method}(...Serializer::unserialize($signal->arguments));
            });

        if ($parentWorkflow) {
            $this->now = Carbon::parse($parentWorkflow->pivot->parent_now);
        } else {
            $this->now = $log ? $log->now : Carbon::now();
        }

        WorkflowStub::setContext([
            'storedWorkflow' => $this->storedWorkflow,
            'index' => $this->index,
            'now' => $this->now,
            'replaying' => $this->replaying,
        ]);

        $this->coroutine = $this->{'execute'}(...$this->resolveClassMethodDependencies(
            $this->arguments,
            $this,
            'execute'
        ));

        while ($this->coroutine->valid()) {
            $this->index = WorkflowStub::getContext()->index;

            $log = $this->storedWorkflow->findLogByIndex($this->index);

            $this->now = $log ? $log->now : Carbon::now();

            WorkflowStub::setContext([
                'storedWorkflow' => $this->storedWorkflow,
                'index' => $this->index,
                'now' => $this->now,
                'replaying' => $this->replaying,
            ]);

            $current = $this->coroutine->current();

            if ($current instanceof PromiseInterface) {
                $resolved = false;
                $exception = null;

                $current->then(function ($value) use (&$resolved, &$exception): void {
                    $resolved = true;
                    try {
                        $this->coroutine->send($value);
                    } catch (Throwable $th) {
                        $exception = $th;
                    }
                });

                if ($exception) {
                    throw $exception;
                }

                if (! $resolved) {
                    if (! $this->replaying) {
                        $this->storedWorkflow->status->transitionTo(WorkflowWaitingStatus::class);
                    }

                    return;
                }
            } else {
                throw new Exception('something went wrong');
            }
        }

        if (! $this->replaying) {
            try {
                $return = $this->coroutine->getReturn();
            } catch (Throwable $th) {
                throw new Exception('Workflow failed.', 0, $th);
            }

            if ($return instanceof ContinuedWorkflow) {
                $this->storedWorkflow->status->transitionTo(WorkflowContinuedStatus::class);
                return;
            }

            $this->storedWorkflow->output = Serializer::serialize($return);

            $this->storedWorkflow->status->transitionTo(WorkflowCompletedStatus::class);

            WorkflowCompleted::dispatch(
                $this->storedWorkflow->id,
                json_encode($return),
                now()
                    ->format('Y-m-d\TH:i:s.u\Z')
            );

            if ($parentWorkflow) {
                $connection = $parentWorkflow->effectiveConnection() ?? config('queue.default');
                $queue = $parentWorkflow->effectiveQueue() ?? config(
                    'queue.connections.' . $connection . '.queue',
                    'default'
                );

                ChildWorkflow::dispatch(
                    $parentWorkflow->pivot->parent_index,
                    $this->now,
                    $this->storedWorkflow,
                    $return,
                    $parentWorkflow,
                    $connection,
                    $queue
                );
            }
        }
    }
}
