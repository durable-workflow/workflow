<?php

declare(strict_types=1);

namespace Workflow;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Workflow\Exceptions\TransitionNotFound;
use Workflow\Middleware\WithoutOverlappingMiddleware;
use Workflow\Models\StoredWorkflow;

final class Exception implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public ?string $key = null;

    public $tries = PHP_INT_MAX;

    public $maxExceptions = PHP_INT_MAX;

    public $timeout = 0;

    public function __construct(
        public int $index,
        public string $now,
        public StoredWorkflow $storedWorkflow,
        public $exception,
        $connection = null,
        $queue = null,
        public ?string $sourceClass = null
    ) {
        $connection = $connection ?? $this->storedWorkflow->effectiveConnection() ?? config('queue.default');
        $queue = $queue ?? $this->storedWorkflow->effectiveQueue() ?? config(
            'queue.connections.' . $connection . '.queue',
            'default'
        );
        $this->onConnection($connection);
        $this->onQueue($queue);
    }

    public function handle()
    {
        $workflow = $this->storedWorkflow->toWorkflow();

        try {
            if ($this->storedWorkflow->hasLogByIndex($this->index)) {
                $workflow->resume();
            } elseif ($this->isCurrentReplayFrontier()) {
                $workflow->next($this->index, $this->now, self::class, $this->exception);
            }
        } catch (TransitionNotFound) {
            if ($workflow->running()) {
                $this->release();
            }
        }
    }

    public function middleware()
    {
        return [
            new WithoutOverlappingMiddleware(
                $this->storedWorkflow->id,
                WithoutOverlappingMiddleware::ACTIVITY,
                0,
                15
            ),
        ];
    }

    private function isCurrentReplayFrontier(): bool
    {
        $workflowClass = $this->storedWorkflow->class;

        if (! is_string($workflowClass) || $workflowClass === '') {
            return true;
        }

        $workflow = new $workflowClass($this->storedWorkflow, ...$this->storedWorkflow->workflowArguments());
        $workflow->replaying = true;

        $previousContext = WorkflowStub::getContext();

        WorkflowStub::setContext([
            'storedWorkflow' => $this->storedWorkflow,
            'index' => 0,
            'now' => $this->now,
            'replaying' => true,
            'probeIndex' => $this->index,
            'probeClass' => $this->sourceClass,
            'probeMatched' => false,
        ]);

        try {
            $workflow->handle();

            return WorkflowStub::getContext()->probeMatched;
        } finally {
            WorkflowStub::setContext($previousContext);
        }
    }
}
