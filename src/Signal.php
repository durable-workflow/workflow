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

class Signal implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 0;

    public int $maxExceptions = 0;

    public function __construct(
        public StoredWorkflow $storedWorkflow,
        $connection = null,
        $queue = null
    ) {
        $connection = $connection ?? $this->storedWorkflow->effectiveConnection() ?? config('queue.default');
        $queue = $queue ?? $this->storedWorkflow->effectiveQueue() ?? config(
            'queue.connections.' . $connection . '.queue',
            'default'
        );
        $this->onConnection($connection);
        $this->onQueue(get_queue_name());
    }

    public function middleware()
    {
        return [
            new WithoutOverlappingMiddleware(
                $this->storedWorkflow->id,
                WithoutOverlappingMiddleware::WORKFLOW,
                0,
                15
            ),
        ];
    }

    public function handle(): void
    {
        $workflow = $this->storedWorkflow->toWorkflow();

        try {
            $workflow->resume();
        } catch (TransitionNotFound) {
            if ($workflow->running()) {
                $this->release();
            }
        }
    }
}
