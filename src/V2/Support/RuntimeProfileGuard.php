<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Database\Connection;
use Throwable;
use Workflow\V2\Exceptions\RuntimeProfileRefusal;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;

/** @internal Phase G supports legacy NULL only. No configuration can widen it. */
final class RuntimeProfileGuard
{
    /** Validate the discriminator returned by this read before model observers. */
    public static function assertHydrated(WorkflowRun $run): void
    {
        self::assertStorageModel($run);
        $attributes = $run->getAttributes();
        if (! array_key_exists('runtime_profile', $attributes)) {
            // A projected select must not silently manufacture legacy NULL.
            self::assertRun($run);

            return;
        }
        if (! is_string($attributes[$run->getKeyName()] ?? null)) {
            throw new RuntimeProfileRefusal('runtime_profile_identity');
        }
        self::assertValue($attributes['runtime_profile'], null);
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
        self::assertStored(
            $model->getConnection(),
            $model->getTable(),
            $model->getKeyName(),
            $id,
            $attributes,
        );
    }

    /** Refuse before the queued job's heartbeat, claim, cache or failure path. */
    public static function taskExistsAndIsSupported(string $taskId): bool
    {
        $taskModel = ConfiguredV2Models::resolve('task_model', WorkflowTask::class);
        $model = new $taskModel();
        try {
            $task = $model->getConnection()->table($model->getTable())
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
        $expected = $task->namespace === null ? [] : ['namespace' => $task->namespace];
        self::assertStored($run->getConnection(), $run->getTable(), $run->getKeyName(), $task->workflow_run_id, $expected);

        return true;
    }

    public static function assertTask(WorkflowTask $task): void
    {
        $model = ConfiguredV2Models::resolve('task_model', WorkflowTask::class);
        $configured = new $model();
        if ($task->getConnection() !== $configured->getConnection()
            || $task->getTable() !== $configured->getTable()
            || ! self::taskExistsAndIsSupported((string) $task->getKey())) {
            throw new RuntimeProfileRefusal('runtime_profile_identity');
        }
    }

    /** Reject engine-owned input before an import/admission can copy it. */
    public static function assertNoProfileInput(array $attributes): void
    {
        if (array_key_exists('runtime_profile', $attributes)) {
            throw new RuntimeProfileRefusal('runtime_profile_assignment');
        }
    }

    /** @param array<string, mixed> $attributes */
    public static function assertLegacySnapshot(array $attributes): void
    {
        if (array_key_exists('runtime_profile', $attributes)) {
            self::assertValue($attributes['runtime_profile'], null);
        }
    }

    private static function runModel(): WorkflowRun
    {
        $model = ConfiguredV2Models::resolve('run_model', WorkflowRun::class);

        return new $model();
    }

    private static function assertStorageModel(WorkflowRun $run): WorkflowRun
    {
        $model = self::runModel();
        if ($run->getTable() !== $model->getTable()
            || $run->getConnection() !== $model->getConnection()) {
            throw new RuntimeProfileRefusal('runtime_profile_storage');
        }

        return $model;
    }

    /** @param array<string, mixed> $expected */
    private static function assertStored(
        Connection $connection,
        string $table,
        string $key,
        string $id,
        array $expected,
    ): void {
        $column = $connection->getQueryGrammar()->wrap('runtime_profile');
        [$length, $prefix] = match ($connection->getDriverName()) {
            'sqlite' => ["length(CAST({$column} AS BLOB))", "substr(CAST({$column} AS BLOB), 1, 65)"],
            'mysql', 'mariadb' => ["OCTET_LENGTH({$column})", "SUBSTRING(CAST({$column} AS BINARY), 1, 65)"],
            'pgsql' => ["octet_length({$column})", "substring(convert_to({$column}, 'UTF8') from 1 for 65)"],
            default => throw new RuntimeProfileRefusal('runtime_profile_storage'),
        };
        try {
            $row = $connection->table($table)->useWritePdo()->where($key, $id)
                ->first([
                    $key,
                    'workflow_instance_id',
                    'namespace',
                    $connection->raw("{$length} AS runtime_profile_bytes"),
                    $connection->raw("{$prefix} AS runtime_profile_value"),
                ]);
        } catch (Throwable $error) {
            throw new RuntimeProfileRefusal('runtime_profile_storage', $error);
        }
        if ($row === null || (string) $row->{$key} !== $id) {
            throw new RuntimeProfileRefusal('runtime_profile_identity');
        }
        foreach (['workflow_instance_id', 'namespace'] as $field) {
            if (array_key_exists($field, $expected) && $expected[$field] !== $row->{$field}) {
                throw new RuntimeProfileRefusal('runtime_profile_identity');
            }
        }
        $value = $row->runtime_profile_value;
        if (is_resource($value)) {
            $value = stream_get_contents($value, 65);
        }
        self::assertValue($value, $row->runtime_profile_bytes);
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
