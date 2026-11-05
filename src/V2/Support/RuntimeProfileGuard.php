<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Database\Connection;
use Throwable;
use Workflow\V2\Exceptions\RuntimeProfileRefusal;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowServiceCall;
use Workflow\V2\Models\WorkflowTask;

/** @internal Phase G supports legacy NULL only. No configuration can widen it. */
final class RuntimeProfileGuard
{
    /**
     * @var list<array{model: WorkflowRun, attributes: array<string, array<string, mixed>>}>
     */
    private static array $hydration = [];

    /**
     * Verify a complete Eloquent batch before any retrieved observer runs.
     * This lexical proof is never used by a durable actor and is always removed.
     *
     * @template T
     * @param list<object> $items
     * @param callable(): T $hydrate
     * @return T
     */
    public static function hydrateBatch(
        WorkflowRun $model,
        array $items,
        callable $hydrate,
        string|bool|null $lock = null,
    ): mixed {
        $key = $model->getKeyName();
        $attributes = [];
        foreach ($items as $item) {
            $row = (array) $item;
            self::assertLegacySnapshot($row);
            $id = $row[$key] ?? null;
            if (! is_string($id) || $id === '') {
                throw new RuntimeProfileRefusal('runtime_profile_identity');
            }
            $attributes[$id] = $row;
        }
        foreach (array_chunk(array_map('strval', array_keys($attributes)), 500) as $ids) {
            try {
                $query = $model->getConnection()
                    ->table($model->getTable())
                    ->useWritePdo()
                    ->whereIn($key, $ids);
                // These exact rows were already selected with this lock.
                // Dropping it here would use an older MySQL consistent-read
                // snapshot instead of the authority that was just locked.
                if ($lock !== null) {
                    $query->lock($lock);
                }
                $rows = $query->orderBy($key)
                    ->get(self::profileColumns($model->getConnection(), $model->getTable(), $key))
                    ->keyBy($key);
            } catch (Throwable $error) {
                throw new RuntimeProfileRefusal('runtime_profile_storage', $error);
            }
            foreach ($ids as $id) {
                self::assertStoredRow($rows->get($id), $key, $id, $attributes[$id]);
            }
        }
        self::$hydration[] = [
            'model' => $model,
            'attributes' => $attributes,
        ];
        try {
            return $hydrate();
        } finally {
            array_pop(self::$hydration);
        }
    }

    public static function assertStorage(): void
    {
        $run = self::runModel();
        try {
            $run->getConnection()
                ->table($run->getTable())
                ->useWritePdo()
                ->whereRaw('1 = 0')
                ->first([$run->getConnection()->raw(
                    $run->getConnection()
                        ->getQueryGrammar()
                        ->wrapTable($run->getTable()) . '.runtime_profile',
                )]);
        } catch (Throwable $error) {
            throw new RuntimeProfileRefusal('runtime_profile_storage', $error);
        }
    }

    /**
     * Preflight a batch before throttle/heartbeat or partial maintenance effects.
     *
     * @param list<string> $runIds
     */
    public static function assertScope(
        ?string $queueConnection = null,
        ?string $queue = null,
        array $runIds = [],
        ?string $instanceId = null,
        ?string $namespace = null,
    ): void {
        $run = self::runModel();
        $table = $run->getTable();
        $query = $run->getConnection()
            ->table($table)
            ->useWritePdo()
            ->whereNotNull($run->getConnection()->raw(
                $run->getConnection()
                    ->getQueryGrammar()
                    ->wrapTable($table) . '.runtime_profile',
            ));
        if ($runIds !== []) {
            $query->whereIn($run->getKeyName(), $runIds);
        }
        if ($instanceId !== null) {
            $query->where('workflow_instance_id', $instanceId);
        }
        if ($namespace !== null) {
            $query->where('namespace', $namespace);
        }
        if ($queueConnection !== null || $queue !== null) {
            $taskClass = ConfiguredV2Models::resolve('task_model', WorkflowTask::class);
            $task = new $taskClass();
            if ($task->getConnection() !== $run->getConnection()) {
                throw new RuntimeProfileRefusal('runtime_profile_storage');
            }
            $query->where(static function ($scope) use ($queueConnection, $queue, $task, $run, $table): void {
                $scope->where(static function ($runScope) use ($queueConnection, $queue): void {
                    RequestedTaskScope::apply($runScope, $queueConnection, $queue);
                })->orWhereExists(static function ($taskScope) use (
                    $queueConnection,
                    $queue,
                    $task,
                    $run,
                    $table
                ): void {
                    $taskScope->selectRaw('1')
                        ->from($task->getTable())
                        ->whereColumn($task->getTable() . '.workflow_run_id', $table . '.' . $run->getKeyName());
                    RequestedTaskScope::apply($taskScope, $queueConnection, $queue);
                });
            });
        }
        try {
            $row = $query->first([$run->getKeyName()]);
        } catch (Throwable $error) {
            throw new RuntimeProfileRefusal('runtime_profile_storage', $error);
        }
        if ($row !== null) {
            self::assertStored(
                $run->getConnection(),
                $table,
                $run->getKeyName(),
                (string) $row->{$run->getKeyName()},
                []
            );
        }
    }

    /**
     * Validate the discriminator returned by this read before model observers.
     */
    public static function assertHydrated(WorkflowRun $run): void
    {
        $attributes = $run->getAttributes();
        self::assertLegacySnapshot($attributes);
        $key = $run->getKeyName();
        $id = $attributes[$key] ?? null;
        if (! is_string($id) || $id === '') {
            throw new RuntimeProfileRefusal('runtime_profile_identity');
        }
        foreach (array_reverse(self::$hydration) as $proof) {
            if ($proof['model']->getConnection() === $run->getConnection()
                && $proof['model']->getTable() === $run->getTable()
                && $proof['model']->getKeyName() === $key
                && ($proof['attributes'][$id] ?? null) === $attributes) {
                return;
            }
        }
        // Hydration binds the connection/table used by this explicit read,
        // including supported relationship-local storage. Durable actors still
        // require the configured authority through assertRun/assertTask.
        self::assertStored($run->getConnection(), $run->getTable(), $key, $id, $attributes);
    }

    public static function assertRun(WorkflowRun $run): void
    {
        $model = self::assertStorageModel($run);

        $attributes = $run->getAttributes();
        $key = $run->getKeyName();
        $id = $attributes[$key] ?? null;
        if (! is_string($id) || $id === '') {
            throw new RuntimeProfileRefusal('runtime_profile_identity');
        }
        self::assertStored($model->getConnection(), $model->getTable(), $model->getKeyName(), $id, $attributes);
    }

    /**
     * Validate the persisted owner before an ordinary legacy metadata update.
     */
    public static function assertRunForUpdate(WorkflowRun $run): void
    {
        $model = self::assertStorageModel($run);
        $original = $run->getRawOriginal();
        $key = $run->getKeyName();
        $id = $original[$key] ?? null;
        if (! is_string($id) || $id === '') {
            throw new RuntimeProfileRefusal('runtime_profile_identity');
        }
        self::assertStored($model->getConnection(), $model->getTable(), $key, $id, $original, forUpdate: true);
    }

    /**
     * Refuse before the queued job's heartbeat, claim, cache or failure path.
     */
    public static function taskExistsAndIsSupported(string $taskId, bool $bindNamespace = true): bool
    {
        $taskModel = ConfiguredV2Models::resolve('task_model', WorkflowTask::class);
        $model = new $taskModel();
        try {
            $task = $model->getConnection()
                ->table($model->getTable())
                ->useWritePdo()
                ->where($model->getKeyName(), $taskId)
                ->first(['workflow_run_id', 'namespace']);
        } catch (Throwable $error) {
            throw new RuntimeProfileRefusal('runtime_profile_storage', $error);
        }
        if ($task === null) {
            return false;
        }

        $run = self::runModel();
        if ($run->getConnection() !== $model->getConnection()
            || ! is_string($task->workflow_run_id) || $task->workflow_run_id === '') {
            throw new RuntimeProfileRefusal('runtime_profile_identity');
        }
        $expected = ! $bindNamespace || $task->namespace === null ? [] : [
            'namespace' => $task->namespace,
        ];
        self::assertStored(
            $run->getConnection(),
            $run->getTable(),
            $run->getKeyName(),
            $task->workflow_run_id,
            $expected
        );

        return true;
    }

    /**
     * Resolve linked authority without invoking model accessors or observers.
     */
    public static function assertActivityExecution(string $executionId): void
    {
        $class = ConfiguredV2Models::resolve('activity_execution_model', ActivityExecution::class);
        $model = new $class();
        $run = self::runModel();
        if ($model->getConnection() !== $run->getConnection()) {
            throw new RuntimeProfileRefusal('runtime_profile_storage');
        }
        try {
            $row = $model->getConnection()
                ->table($model->getTable())
                ->useWritePdo()
                ->where($model->getKeyName(), $executionId)
                ->first(['workflow_run_id']);
        } catch (Throwable $error) {
            throw new RuntimeProfileRefusal('runtime_profile_storage', $error);
        }
        if ($row !== null) {
            self::assertStored(
                $run->getConnection(),
                $run->getTable(),
                $run->getKeyName(),
                (string) $row->workflow_run_id,
                []
            );
        }
    }

    public static function assertActivityAttempt(string $attemptId): void
    {
        $class = ConfiguredV2Models::resolve('activity_attempt_model', ActivityAttempt::class);
        $model = new $class();
        $run = self::runModel();
        if ($model->getConnection() !== $run->getConnection()) {
            throw new RuntimeProfileRefusal('runtime_profile_storage');
        }
        try {
            $row = $model->getConnection()
                ->table($model->getTable())
                ->useWritePdo()
                ->where($model->getKeyName(), $attemptId)
                ->first(['workflow_run_id']);
        } catch (Throwable $error) {
            throw new RuntimeProfileRefusal('runtime_profile_storage', $error);
        }
        if ($row !== null) {
            self::assertStored(
                $run->getConnection(),
                $run->getTable(),
                $run->getKeyName(),
                (string) $row->workflow_run_id,
                []
            );
        }
    }

    public static function assertServiceCall(string $callId): void
    {
        $class = ConfiguredV2Models::resolve('service_call_model', WorkflowServiceCall::class);
        $model = new $class();
        $run = self::runModel();
        if ($model->getConnection() !== $run->getConnection()) {
            throw new RuntimeProfileRefusal('runtime_profile_storage');
        }
        try {
            $call = $model->getConnection()
                ->table($model->getTable())
                ->useWritePdo()
                ->where($model->getKeyName(), $callId)
                ->first([
                    'caller_workflow_run_id', 'caller_workflow_instance_id', 'caller_namespace',
                    'linked_workflow_run_id', 'linked_workflow_instance_id', 'target_namespace',
                ]);
        } catch (Throwable $error) {
            throw new RuntimeProfileRefusal('runtime_profile_storage', $error);
        }
        if ($call === null) {
            return;
        }
        foreach ([['caller_workflow_run_id', 'caller_workflow_instance_id', 'caller_namespace'],
            [
                'linked_workflow_run_id',
                'linked_workflow_instance_id',
                'target_namespace',
            ]] as [$id, $owner, $namespace]) {
            if (is_string($call->{$id}) && $call->{$id} !== '') {
                // Missing legacy links retain their existing handling. An existing
                // link is checked by ID before namespace/owner filters can hide it.
                self::assertReference($call->{$id}, [
                    'workflow_instance_id' => $call->{$owner},
                    'namespace' => $call->{$namespace},
                ]);
            }
            if (is_string($call->{$owner}) && $call->{$owner} !== '') {
                self::assertScope(instanceId: $call->{$owner});
            }
        }
    }

    public static function assertTaskRun(WorkflowTask $task, WorkflowRun $run): void
    {
        self::assertRun($run);
        self::assertTask($task);
        $taskClass = ConfiguredV2Models::resolve('task_model', WorkflowTask::class);
        $model = new $taskClass();
        $storedRunId = $model->getConnection()
            ->table($model->getTable())
            ->useWritePdo()
            ->where($model->getKeyName(), $task->getKey())
            ->value('workflow_run_id');
        if ($storedRunId !== $run->getKey()) {
            throw new RuntimeProfileRefusal('runtime_profile_identity');
        }
    }

    public static function assertTask(WorkflowTask $task): void
    {
        if (! self::taskIsSupported($task)) {
            throw new RuntimeProfileRefusal('runtime_profile_identity');
        }
    }

    public static function taskIsSupported(WorkflowTask $task): bool
    {
        $model = ConfiguredV2Models::resolve('task_model', WorkflowTask::class);
        $configured = new $model();
        if ($task->getConnection() !== $configured->getConnection()
            || $task->getTable() !== $configured->getTable()) {
            throw new RuntimeProfileRefusal('runtime_profile_identity');
        }

        return self::taskExistsAndIsSupported((string) $task->getKey());
    }

    /**
     * Reject engine-owned input before an import/admission can copy it.
     *
     * @param array<array-key, mixed> $attributes
     */
    public static function assertNoProfileInput(array $attributes): void
    {
        if (array_key_exists('runtime_profile', $attributes)) {
            throw new RuntimeProfileRefusal('runtime_profile_assignment');
        }
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public static function assertLegacySnapshot(array $attributes): void
    {
        if (array_key_exists('runtime_profile', $attributes)) {
            self::assertValue($attributes['runtime_profile'], null);
        }
    }

    /**
     * @param array<string, mixed> $expected
     */
    private static function assertReference(string $id, array $expected): void
    {
        $run = self::runModel();
        try {
            $exists = $run->getConnection()
                ->table($run->getTable())
                ->useWritePdo()
                ->where($run->getKeyName(), $id)
                ->exists();
        } catch (Throwable $error) {
            throw new RuntimeProfileRefusal('runtime_profile_storage', $error);
        }
        if ($exists) {
            self::assertStored($run->getConnection(), $run->getTable(), $run->getKeyName(), $id, $expected);
        }
    }

    private static function runModel(): WorkflowRun
    {
        $model = ConfiguredV2Models::resolve('run_model', WorkflowRun::class);

        return new $model();
    }

    private static function assertStorageModel(WorkflowRun $run): WorkflowRun
    {
        if ($run instanceof OfflineHistoryRun) {
            throw new RuntimeProfileRefusal('runtime_profile_identity');
        }
        $model = self::runModel();
        if ($run->getTable() !== $model->getTable()
            || $run->getConnection() !== $model->getConnection()) {
            throw new RuntimeProfileRefusal('runtime_profile_storage');
        }

        return $model;
    }

    /**
     * @return list<string|\Illuminate\Contracts\Database\Query\Expression>
     */
    private static function profileColumns(Connection $connection, string $table, string $key): array
    {
        // SQLite treats an unqualified double-quoted missing column as a
        // string literal. Qualify it so absent schema is a storage failure.
        $column = $connection->getQueryGrammar()
            ->wrapTable($table) . '.'
            . $connection->getQueryGrammar()->wrap('runtime_profile');
        [$length, $prefix] = match ($connection->getDriverName()) {
            'sqlite' => ["length(CAST({$column} AS BLOB))", "substr(CAST({$column} AS BLOB), 1, 65)"],
            'mysql', 'mariadb' => ["OCTET_LENGTH({$column})", "SUBSTRING(CAST({$column} AS BINARY), 1, 65)"],
            'pgsql' => ["octet_length({$column})", "substring(convert_to({$column}, 'UTF8') from 1 for 65)"],
            'sqlsrv' => [SqlServerRuntimeProfileSchema::canonicalBytes($column),
                "SUBSTRING({$column} COLLATE Latin1_General_100_BIN2, 1, 65)"],
            default => throw new RuntimeProfileRefusal('runtime_profile_storage'),
        };
        $columns = [$key, 'workflow_instance_id', 'namespace',
            $connection->raw("{$length} AS runtime_profile_bytes"),
            $connection->raw("{$prefix} AS runtime_profile_value")];
        if ($connection->getDriverName() === 'sqlsrv') {
            $columns[] = $connection->raw("DATALENGTH({$column}) AS runtime_profile_storage_bytes");
        }
        return $columns;
    }

    /**
     * @param array<string, mixed> $expected
     */
    private static function assertStored(
        Connection $connection,
        string $table,
        string $key,
        string $id,
        array $expected,
        bool $forUpdate = false,
    ): void {
        try {
            $query = $connection->table($table)
                ->useWritePdo()
                ->where($key, $id);
            // An impending update of this exact row must validate the current
            // MySQL authority, not an older caller-owned consistent snapshot.
            // Read-only guards do not acquire this writer lock.
            if ($forUpdate && in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
                $query->lockForUpdate();
            }
            $row = $query->first(self::profileColumns($connection, $table, $key));
        } catch (Throwable $error) {
            throw new RuntimeProfileRefusal('runtime_profile_storage', $error);
        }
        self::assertStoredRow($row, $key, $id, $expected);
    }

    /**
     * @param array<string, mixed> $expected
     */
    private static function assertStoredRow(?object $row, string $key, string $id, array $expected): void
    {
        if ($row === null || (string) $row->{$key} !== $id) {
            throw new RuntimeProfileRefusal('runtime_profile_identity');
        }
        $value = $row->runtime_profile_value;
        if (is_resource($value)) {
            $value = stream_get_contents($value, 65);
        }
        self::assertValue($value, $row->runtime_profile_bytes);
        foreach (['workflow_instance_id', 'namespace'] as $field) {
            if (array_key_exists($field, $expected) && $expected[$field] !== $row->{$field}) {
                throw new RuntimeProfileRefusal('runtime_profile_identity');
            }
        }
    }

    private static function assertValue(mixed $value, mixed $bytes): void
    {
        if ($value === null && $bytes === null) {
            return;
        }
        if (! is_string($value) || strlen($value) > 64
            || ($bytes !== null && (int) $bytes !== strlen($value))
            || preg_match('/\A[a-z][a-z0-9._-]{0,63}\z/D', $value) !== 1) {
            throw new RuntimeProfileRefusal('runtime_profile_invalid');
        }

        throw new RuntimeProfileRefusal('runtime_profile_unsupported');
    }
}
