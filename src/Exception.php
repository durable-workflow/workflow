<?php

declare(strict_types=1);

namespace Workflow;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use ReflectionClass;
use Throwable;
use Workflow\Exceptions\TransitionNotFound;
use Workflow\Middleware\WithoutOverlappingMiddleware;
use Workflow\Models\StoredWorkflow;
use Workflow\Models\StoredWorkflowLog;
use Workflow\Serializers\Serializer;

final class Exception implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private const LOCK_RETRY_DELAY = 1;

    private const PROBE_SKIP = 'skip';

    private const PROBE_PERSIST = 'persist';

    private const PROBE_RETRY = 'retry';

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
        $lock = Cache::lock('laravel-workflow-exception:' . $this->storedWorkflow->id, 15);

        if (! $lock->get()) {
            $this->release(self::LOCK_RETRY_DELAY);

            return;
        }

        try {
            $workflow = $this->storedWorkflow->toWorkflow();

            try {
                if ($this->storedWorkflow->hasLogByIndex($this->index)) {
                    $workflow->resume();
                } else {
                    $probeDecision = $this->probeReplayDecision();

                    if ($probeDecision === self::PROBE_PERSIST) {
                        $workflow->next($this->index, $this->now, self::class, $this->exceptionPayload());
                    } elseif ($probeDecision === self::PROBE_RETRY) {
                        $this->release(self::LOCK_RETRY_DELAY);
                    }
                }
            } catch (TransitionNotFound) {
                if ($workflow->running()) {
                    $this->release();
                }
            }
        } finally {
            $lock->release();
        }
    }

    public function middleware()
    {
        return [
            new WithoutOverlappingMiddleware(
                $this->storedWorkflow->id,
                WithoutOverlappingMiddleware::WORKFLOW,
                self::LOCK_RETRY_DELAY,
                15
            ),
        ];
    }

    private function probeReplayDecision(): string
    {
        $workflowClass = $this->storedWorkflow->class;

        if (! is_string($workflowClass) || $workflowClass === '') {
            return self::PROBE_PERSIST;
        }

        try {
            if (! class_exists($workflowClass) || ! is_subclass_of($workflowClass, Workflow::class)) {
                return self::PROBE_PERSIST;
            }

            if (! (new ReflectionClass($workflowClass))->isInstantiable()) {
                return self::PROBE_PERSIST;
            }
        } catch (Throwable) {
            return self::PROBE_PERSIST;
        }

        $previousContext = WorkflowStub::getContext();
        $probeDecision = self::PROBE_SKIP;

        try {
            try {
                $probeNow = Carbon::parse($this->now);
            } catch (Throwable) {
                return self::PROBE_PERSIST;
            }

            $tentativeWorkflow = $this->createTentativeWorkflowState();
            $workflow = new $workflowClass($tentativeWorkflow, ...$tentativeWorkflow->workflowArguments());
            $workflow->replaying = true;

            WorkflowStub::setContext([
                'storedWorkflow' => $tentativeWorkflow,
                'index' => 0,
                'now' => $probeNow,
                'replaying' => true,
                'probing' => true,
                'probeIndex' => $this->index,
                'probeClass' => $this->sourceClass,
                'probeMatched' => false,
                'probePendingBeforeMatch' => false,
            ]);

            try {
                $workflow->handle();
            } catch (Throwable) {
                // The replay path may still throw; we only care whether it matched this tentative log.
            }

            if (WorkflowStub::probeMatched()) {
                $probeDecision = WorkflowStub::probePendingBeforeMatch()
                    ? self::PROBE_RETRY
                    : self::PROBE_PERSIST;
            }
        } finally {
            WorkflowStub::setContext($previousContext);
        }

        return $probeDecision;
    }

    private function createTentativeWorkflowState(): StoredWorkflow
    {
        $storedWorkflowClass = $this->storedWorkflow::class;

        /** @var StoredWorkflow $tentativeWorkflow */
        $tentativeWorkflow = $storedWorkflowClass::query()
            ->findOrFail($this->storedWorkflow->id);

        $tentativeWorkflow->loadMissing(['logs', 'signals']);

        /** @var StoredWorkflowLog $tentativeLog */
        $tentativeLog = $tentativeWorkflow->logs()
            ->make([
                'index' => $this->index,
                'now' => $this->now,
                'class' => self::class,
                'result' => Serializer::serialize($this->exceptionPayload()),
            ]);

        $tentativeWorkflow->setRelation(
            'logs',
            $tentativeWorkflow->getRelation('logs')
                ->push($tentativeLog)
                ->sortBy(static fn ($log): string => sprintf('%020d:%020d', $log->index, $log->id ?? PHP_INT_MAX))
                ->values()
        );

        return $tentativeWorkflow;
    }

    private function exceptionPayload()
    {
        if (! is_array($this->exception) || $this->sourceClass === null) {
            return $this->exception;
        }

        return array_merge($this->exception, [
            'sourceClass' => $this->sourceClass,
        ]);
    }
}
