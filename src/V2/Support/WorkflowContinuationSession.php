<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Database\Connection;
use ReflectionClass;
use ReflectionNamedType;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Workflow;

/** @internal One root transaction's unpublished continuation candidate. */
final class WorkflowContinuationSession
{
    private ?string $key = null;

    private ?string $scope = null;

    private int $generation = 0;

    private bool $enabled = true;

    private bool $resumed = false;

    private ?WorkflowContinuation $pending = null;

    private ?WorkflowContinuation $active = null;

    public function __construct(
        private readonly WorkflowContinuationCache $cache,
        private readonly Connection $database,
    ) {
    }

    /**
     * @param class-string<Workflow> $class
     */
    public function begin(WorkflowRun $run, WorkflowTask $task, string $class): ?WorkflowContinuation
    {
        $this->observe($run);
        $build = config('workflows.v2.live_execution.build');
        $eligible = is_string($build) && $build !== '' && $this->eligibleClass($class)
            && $this->database === $run->getConnection() && $this->database->transactionLevel() === 1
            && $this->hasAuthority($run, $task) && $this->safeHistory($run);
        $prefix = $eligible ? $this->prefix($run) : null;
        if ($prefix === null) {
            $this->disable($eligible ? 'cold_history_cursor' : 'cold_profile');
            return null;
        }
        $this->key = hash('sha256', serialize([
            $this->scope, $class, WorkflowDefinition::fingerprint($class),
            $build, config('workflows.v2.compatibility.current'), PHP_VERSION,
        ]));
        $entry = $this->cache->take($this->key, $this->scope);
        if ($entry !== null && ($entry->guard !== $this->guard($run)
            || $entry->prefix !== array_slice($prefix, 0, count($entry->prefix)))) {
            $this->cache->count('invalidate_history_or_run');
            return null;
        }
        if ($entry !== null) {
            // Keep references captured by authoring code attached to the refreshed model.
            $bound = $entry->workflow->run;
            $bound->setRawAttributes($run->getAttributes(), true);
            $bound->setRelations($run->getRelations());
            $this->resumed = true;
            $this->cache->count('hit');
        }

        return $entry;
    }

    public function hasAuthority(WorkflowRun $run, WorkflowTask $task): bool
    {
        $this->observe($run);
        return $task->workflow_run_id === $run->id && $task->namespace === $run->namespace
            && $task->status === TaskStatus::Leased && $task->lease_owner === $task->id
            && $task->attempt_count > 0 && $task->lease_expires_at !== null
            && $task->lease_expires_at->isFuture();
    }

    public function accepts(mixed $call): bool
    {
        return $this->enabled && ($call instanceof ActivityCall || ($call instanceof TimerCall && $call->seconds > 0));
    }

    public function resumed(): bool
    {
        return $this->resumed;
    }

    public function disable(string $reason = 'discard'): void
    {
        $this->cache->count($reason);
        if ($this->scope !== null) {
            $this->cache->invalidate($this->scope);
        }
        $this->enabled = false;
        $this->pending = null;
        $this->active = null;
    }

    public function track(WorkflowContinuation $entry): void
    {
        $this->active = $this->enabled ? $entry : null;
    }

    public function offer(WorkflowTask $task): void
    {
        $entry = $this->active;
        $this->active = null;
        if ($entry === null) {
            return;
        }
        $run = $entry->workflow->run;
        if (! $this->accepts($entry->current) || ! $entry->execution->valid()
            || $run->status !== RunStatus::Waiting || $task->status !== TaskStatus::Completed
            || ! $this->safeHistory($run)) {
            return;
        }
        $entry->guard = $this->guard($run);
        $prefix = $this->prefix($run);
        if ($prefix !== null) {
            $entry->scope = $this->scope ?? '';
            $entry->prefix = $prefix;
            $this->pending = $entry;
        }
    }

    public function committed(): void
    {
        $pending = $this->pending;
        $this->pending = null;
        if ($pending === null || $this->key === null || $this->database->transactionLevel() !== 0
            || $this->database->getPdo()
                ->inTransaction()) {
            return;
        }
        $this->cache->publish($this->key, $this->generation, $pending);
    }

    /**
     * @param class-string<Workflow> $class
     */
    private function eligibleClass(string $class): bool
    {
        $allowed = config('workflows.v2.live_execution.workflows', []);
        if (! is_array($allowed) || ! in_array($class, $allowed, true) || app()->bound($class)) {
            return false;
        }
        $reflection = new ReflectionClass($class);
        $unsupportedAttributes = array_filter(
            $reflection->getAttributes(),
            static fn ($attribute): bool => $attribute->getName() !== Type::class
        );
        $decoratedMethods = array_filter(
            $reflection->getMethods(),
            static fn ($method): bool => $method->getAttributes() !== []
        );
        $injectedParameters = array_filter(
            EntryMethod::forWorkflow($class)->getParameters(),
            static fn ($parameter): bool => ! $parameter->getType() instanceof ReflectionNamedType
                || ! $parameter->getType()
                    ->isBuiltin()
        );

        return $reflection->getConstructor() === null && $unsupportedAttributes === []
            && $decoratedMethods === [] && $injectedParameters === [];
    }

    private function safeHistory(WorkflowRun $run): bool
    {
        foreach ($run->historyEvents as $event) {
            if (! in_array($event->event_type, [HistoryEventType::StartAccepted, HistoryEventType::WorkflowStarted,
                HistoryEventType::ActivityScheduled, HistoryEventType::ActivityStarted,
                HistoryEventType::ActivityHeartbeatRecorded, HistoryEventType::ActivityCompleted,
                HistoryEventType::TimerScheduled, HistoryEventType::TimerFired], true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>|null
     */
    private function prefix(WorkflowRun $run): ?array
    {
        $events = $run->historyEvents()
            ->get();
        if ((int) $run->last_history_sequence !== $events->count()) {
            return null;
        }
        foreach ($events->values() as $index => $event) {
            if ((int) $event->sequence !== $index + 1) {
                return null;
            }
        }

        return $events->map(static fn ($event): string => hash('sha256', serialize($event->getRawOriginal())))
            ->all();
    }

    private function observe(WorkflowRun $run): void
    {
        if ($this->scope === null) {
            $this->scope = hash('sha256', serialize([
                spl_object_id(app()), $this->database->getName(), $this->database->getDriverName(),
                $this->database->getDatabaseName(), $run->getTable(), $run->namespace,
                $run->workflow_instance_id, $run->id,
            ]));
            $this->generation = $this->cache->observe($this->scope);
        }
    }

    private function guard(WorkflowRun $run): string
    {
        return hash('sha256', serialize($run->only([
            'namespace', 'workflow_instance_id', 'id', 'workflow_class', 'workflow_type', 'arguments',
            'payload_codec', 'started_at', 'execution_deadline_at', 'run_deadline_at',
            'connection', 'queue', 'compatibility',
        ])));
    }
}
