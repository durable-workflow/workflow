<?php

declare(strict_types=1);

namespace Workflow;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Workflow\Exceptions\TransitionNotFound;
use Workflow\Middleware\WithoutOverlappingMiddleware;
use Workflow\Models\StoredWorkflow;

final class ChildWorkflow implements ShouldBeEncrypted, ShouldBeUnique, ShouldQueue
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
        public $return,
        public StoredWorkflow $parentWorkflow,
        $connection = null,
        $queue = null
    ) {
        $connection = $connection ?? $this->storedWorkflow->effectiveConnection() ?? config('queue.default');
        $queue = $queue ?? $this->storedWorkflow->effectiveQueue() ?? config(
            'queue.connections.' . $connection . '.queue',
            'default'
        );
        $this->onConnection($connection);
        $this->onQueue($queue);
    }

    public function uniqueId()
    {
        return $this->parentWorkflow->id . ':' . $this->index;
    }

    public function handle()
    {
        $workflow = $this->parentWorkflow->toWorkflow();

        try {
            if ($this->parentWorkflow->hasLogByIndex($this->index)) {
                $workflow->resume();
            } else {
                $workflow->next($this->index, $this->now, $this->storedWorkflow->class, $this->return);
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
            new WithoutOverlappingMiddleware($this->parentWorkflow->id, WithoutOverlappingMiddleware::ACTIVITY, 0, 15),
        ];
    }
}
