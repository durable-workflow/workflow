<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowServiceCall;
use Workflow\V2\Models\WorkflowTask;

/**
 * The set of workflow namespaces this application may repair, redispatch,
 * time out and execute.
 *
 * Embedded apps that share one database can share connection and queue names.
 * The namespace is what keeps one app's background work from acting on
 * another app's runs with the wrong code, configuration or credentials.
 *
 * Resolution from `workflows.v2` config:
 *
 * - `all_namespaces` true: every namespace. This is an administrative
 *   opt-in (for example the standalone server's matching role) and is
 *   never a default.
 * - `namespaces` absent (null): exactly the configured `namespace`. An
 *   unset `namespace` means the unscoped default namespace.
 * - `namespaces` set: exactly the listed namespaces. An empty list or empty
 *   string serves no namespace.
 *
 * Null, empty and `default` namespace values are one bucket, the unscoped
 * default namespace, as they are for task routing. A task without its own
 * namespace belongs to its run's namespace.
 */
final class NamespaceScope
{
    public const DEFAULT_NAMESPACE = 'default';

    /**
     * @param list<string>|null $namespaces Canonical names, or null for all.
     */
    private function __construct(
        private readonly ?array $namespaces,
    ) {
    }

    public static function configured(): self
    {
        if (filter_var(config('workflows.v2.all_namespaces', false), FILTER_VALIDATE_BOOL)) {
            return self::all();
        }

        $configured = config('workflows.v2.namespaces');

        if ($configured === null) {
            $namespace = config('workflows.v2.namespace');

            return self::of([is_string($namespace) ? $namespace : null]);
        }

        if (is_string($configured)) {
            $configured = trim($configured) === '' ? [] : explode(',', $configured);
        }

        if (! is_array($configured)) {
            throw new InvalidArgumentException(
                'workflows.v2.namespaces must be null, a comma-separated string or a list of namespaces.'
            );
        }

        $namespaces = [];

        foreach ($configured as $namespace) {
            if (! is_string($namespace)) {
                throw new InvalidArgumentException('workflows.v2.namespaces entries must be strings.');
            }

            if (trim($namespace) !== '') {
                $namespaces[] = $namespace;
            }
        }

        return self::of($namespaces);
    }

    public static function all(): self
    {
        return new self(null);
    }

    /**
     * @param iterable<string|null> $namespaces Null or empty means the default namespace.
     */
    public static function of(iterable $namespaces): self
    {
        $canonical = [];

        foreach ($namespaces as $namespace) {
            $namespace = self::canonical($namespace);

            if ($namespace === '*') {
                throw new InvalidArgumentException(
                    'A namespace scope cannot contain "*". Set workflows.v2.all_namespaces (DW_V2_ALL_NAMESPACES) '
                    . 'to serve every namespace.'
                );
            }

            $canonical[$namespace] = true;
        }

        $canonical = array_keys($canonical);
        sort($canonical);

        return new self(array_map('strval', $canonical));
    }

    public static function canonical(?string $namespace): string
    {
        $namespace = $namespace === null ? '' : trim($namespace);

        return $namespace === '' ? self::DEFAULT_NAMESPACE : $namespace;
    }

    public function isAll(): bool
    {
        return $this->namespaces === null;
    }

    public function isEmpty(): bool
    {
        return $this->namespaces === [];
    }

    /**
     * @return list<string>|null Null when every namespace is allowed.
     */
    public function namespaces(): ?array
    {
        return $this->namespaces;
    }

    public function allows(?string $namespace): bool
    {
        return $this->namespaces === null || in_array(self::canonical($namespace), $this->namespaces, true);
    }

    /**
     * Stable identity for caches keyed by the scope.
     */
    public function key(): string
    {
        return $this->namespaces === null ? '*' : json_encode($this->namespaces, JSON_THROW_ON_ERROR);
    }

    /**
     * Limit rows whose own column names their namespace. Null and empty
     * values count as the default namespace.
     */
    public function apply($query, string $column = 'namespace'): void
    {
        if ($this->namespaces === null) {
            return;
        }

        if ($this->namespaces === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function ($scoped) use ($column): void {
            $this->whereColumnAllowed($scoped, $column, nullIsDefault: true);
        });
    }

    /**
     * Limit rows by the namespace of the run named in `$runColumn`.
     */
    public function applyThroughRun($query, string $runColumn = 'workflow_run_id'): void
    {
        if ($this->namespaces === null) {
            return;
        }

        if ($this->namespaces === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereIn($runColumn, $this->runIdsInScope());
    }

    /**
     * Limit workflow tasks by their authoritative namespace: the task's own
     * namespace, or its run's namespace when the task has none recorded.
     */
    public function applyToTasks(
        $query,
        string $namespaceColumn = 'namespace',
        string $runColumn = 'workflow_run_id'
    ): void {
        if ($this->namespaces === null) {
            return;
        }

        if ($this->namespaces === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function ($scoped) use ($namespaceColumn, $runColumn): void {
            $scoped->where(function ($recorded) use ($namespaceColumn): void {
                $this->whereColumnAllowed($recorded, $namespaceColumn, nullIsDefault: false);
            })->orWhere(function ($inherited) use ($namespaceColumn, $runColumn): void {
                $inherited->whereNull($namespaceColumn)
                    ->whereIn($runColumn, $this->runIdsInScope());
            });
        });
    }

    /**
     * Gate for a queued task job, including jobs serialized before this check
     * existed. A refused job leaves the task untouched for its own app's
     * worker or repair pass.
     */
    public static function admitsQueuedTask(string $taskId, string $job): bool
    {
        $scope = self::configured();

        if ($scope->admitsTask($taskId)) {
            return true;
        }

        self::logRefusal($scope, $job, [
            'task_id' => $taskId,
        ]);

        return false;
    }

    /**
     * Gate for a queued service-call attempt job.
     */
    public static function admitsQueuedServiceCall(string $serviceCallId, string $job): bool
    {
        $scope = self::configured();

        if ($scope->admitsServiceCall($serviceCallId)) {
            return true;
        }

        self::logRefusal($scope, $job, [
            'service_call_id' => $serviceCallId,
        ]);

        return false;
    }

    /**
     * Whether a queued task job may run here. Missing tasks are admitted so the
     * job's own lookup settles them exactly as before.
     */
    public function admitsTask(string $taskId): bool
    {
        if ($this->namespaces === null) {
            return true;
        }

        /** @var WorkflowTask|null $task */
        $task = ConfiguredV2Models::query('task_model', WorkflowTask::class)
            ->select(['id', 'namespace', 'workflow_run_id'])
            ->find($taskId);

        if ($task === null) {
            return true;
        }

        $namespace = $task->namespace;

        if ($namespace === null && is_string($task->workflow_run_id)) {
            $namespace = ConfiguredV2Models::query('run_model', WorkflowRun::class)
                ->whereKey($task->workflow_run_id)
                ->value('namespace');
        }

        return $this->allows(is_string($namespace) ? $namespace : null);
    }

    /**
     * Whether a queued service-call attempt may run here, by the caller's
     * namespace. Missing calls are admitted so the claim settles them.
     */
    public function admitsServiceCall(string $serviceCallId): bool
    {
        if ($this->namespaces === null) {
            return true;
        }

        $call = ConfiguredV2Models::query('service_call_model', WorkflowServiceCall::class)
            ->whereKey($serviceCallId)
            ->first(['id', 'caller_namespace']);

        return $call === null || $this->allows(
            is_string($call->caller_namespace) ? $call->caller_namespace : null
        );
    }

    /**
     * @return array{all: bool, namespaces: list<string>|null}
     */
    public function toArray(): array
    {
        return [
            'all' => $this->namespaces === null,
            'namespaces' => $this->namespaces,
        ];
    }

    /**
     * @param array<string, string> $context
     */
    private static function logRefusal(self $scope, string $job, array $context): void
    {
        Log::warning('Durable Workflow skipped a queued job outside this application\'s namespaces.', [
            'job' => $job,
            ...$context,
            'namespaces' => $scope->namespaces(),
        ]);
    }

    private function runIdsInScope()
    {
        $runs = ConfiguredV2Models::query('run_model', WorkflowRun::class);
        $runs->select($runs->getModel()->getKeyName());
        $this->apply($runs);

        return $runs;
    }

    private function whereColumnAllowed($query, string $column, bool $nullIsDefault): void
    {
        $named = array_values(array_filter(
            (array) $this->namespaces,
            static fn (string $namespace): bool => $namespace !== self::DEFAULT_NAMESPACE,
        ));

        if (in_array(self::DEFAULT_NAMESPACE, (array) $this->namespaces, true)) {
            $query->where(static function ($default) use ($column, $nullIsDefault): void {
                $default->where($column, self::DEFAULT_NAMESPACE)
                    ->orWhere($column, '');

                if ($nullIsDefault) {
                    $default->orWhereNull($column);
                }
            });

            if ($named !== []) {
                $query->orWhereIn($column, $named);
            }

            return;
        }

        $query->whereIn($column, $named);
    }
}
