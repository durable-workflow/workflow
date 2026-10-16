<?php

declare(strict_types=1);

namespace Workflow\Queue;

use Illuminate\Queue\DatabaseQueue;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\QueueManager;
use Throwable;
use WeakMap;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowTask;

/** Inspect one known database-queue row. A transport reference grants no task authority. */
final class DatabaseTaskDelivery extends DatabaseQueue
{
    private static ?WeakMap $queued = null;

    public static function install(): void
    {
        $callback = [self::class, 'observePayload'];
        if (! in_array($callback, static::$createPayloadCallbacks, true)) {
            static::createPayloadUsing($callback);
        }
    }

    public static function observePayload(mixed $connection, mixed $queue, array $payload): array
    {
        $job = $payload['data']['command'] ?? null;
        if (self::isTaskJob($job)) {
            self::$queued ??= new WeakMap();
            self::$queued[$job] = [
                'connection' => $connection,
                'uuid' => self::validUuid($payload['uuid'] ?? null),
            ];
        }
        return [];
    }

    public static function observe(JobQueued $event): void
    {
        $job = $event->job;
        if (! self::isTaskJob($job)) {
            return;
        }
        self::$queued ??= new WeakMap();
        $before = self::$queued[$job] ?? [];
        self::$queued[$job] = [
            'id' => $event->id,
            'connection' => $event->connectionName,
            'uuid' => self::payloadUuid($event->payload ?? null)
                ?? (($before['connection'] ?? null) === $event->connectionName ? ($before['uuid'] ?? null) : null),
        ];
    }

    public static function capture(WorkflowTask $task, ?object $job): ?array
    {
        $observed = $job === null ? null : (self::$queued[$job] ?? null);
        $jobId = $observed['id'] ?? null;
        if (! array_key_exists('queue_delivery_reference', $task->getAttributes()) || ! is_array($observed)
            || ! is_string($observed['uuid'] ?? null)
            || ! (is_int($jobId) || is_string($jobId))
            || filter_var((string) $jobId, FILTER_VALIDATE_INT, [
                'options' => [
                    'min_range' => 1,
                ],
            ]) === false
            || preg_match('/^[1-9]\d{0,19}$/D', (string) $jobId) !== 1) {
            return null;
        }
        try {
            $queue = self::queueFor($task);
            return $queue === null || $observed['connection'] !== $queue->getConnectionName()
                ? null : self::reference($queue, $task, (string) $jobId, $observed['uuid']);
        } catch (Throwable) {
            // Optional lookup metadata cannot turn an already-published job into a dispatch failure.
            return null;
        }
    }

    public static function hasUnreservedDelivery(WorkflowTask $task): bool
    {
        if ($task->status !== TaskStatus::Ready || config('workflows.v2.task_dispatch_mode') === 'poll') {
            return false;
        }
        $reference = self::decodeReference($task->getRawOriginal('queue_delivery_reference'));
        if ($reference === null) {
            return false;
        }
        $queue = self::queueFor($task);
        return $queue !== null && self::matches($reference, $task, $queue)
            && $task->getConnection()
                ->transactionLevel() === 0 && $queue->database->transactionLevel() === 0
            && self::rowMatches($task, $queue, $reference);
    }

    private static function decodeReference(mixed $raw): ?array
    {
        if (! is_string($raw) || strlen($raw) > 2048) {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    private static function reference(DatabaseQueue $queue, WorkflowTask $task, string $jobId, string $uuid): array
    {
        return [
            'version' => 1,
            'task_id' => $task->id,
            'run_id' => $task->workflow_run_id,
            'namespace' => $task->namespace,
            'connection' => $queue->getConnectionName(),
            'queue' => $queue->getQueue($task->queue),
            'database' => $queue->database->getName(),
            'table' => $queue->table,
            'job_id' => $jobId,
            'payload_uuid' => $uuid,
        ];
    }

    private static function rowMatches(WorkflowTask $task, DatabaseQueue $queue, array $reference): bool
    {
        $row = $queue->database->table($queue->table)
            ->where('id', $reference['job_id'])
            ->where('queue', $reference['queue'])->whereNull('reserved_at')->first(['payload', 'queue']);
        if ($row === null || $row->queue !== $reference['queue']
            || ! is_string($row->payload) || strlen($row->payload) > 65536) {
            return false;
        }
        $payload = json_decode($row->payload, true);
        $jobClass = match ($task->task_type) {
            TaskType::Workflow => RunWorkflowTask::class,
            TaskType::Activity => RunActivityTask::class,
            TaskType::Timer => RunTimerTask::class,
        };
        return is_array($payload) && ($payload['uuid'] ?? null) === $reference['payload_uuid']
            && ($payload['data']['commandName'] ?? null) === $jobClass;
    }

    private static function payloadUuid(mixed $payload): ?string
    {
        if (! is_string($payload) || strlen($payload) > 65536) {
            return null;
        }
        $decoded = json_decode($payload, true);
        $uuid = is_array($decoded) ? ($decoded['uuid'] ?? null) : null;
        return self::validUuid($uuid);
    }

    private static function validUuid(mixed $uuid): ?string
    {
        return is_string($uuid) && $uuid !== '' && strlen($uuid) <= 128 ? $uuid : null;
    }

    private static function isTaskJob(mixed $job): bool
    {
        return $job instanceof RunWorkflowTask || $job instanceof RunActivityTask || $job instanceof RunTimerTask;
    }

    private static function queueFor(WorkflowTask $task): ?DatabaseQueue
    {
        $manager = app('queue');
        if ($manager::class !== QueueManager::class) {
            return null;
        }
        $queue = $manager->connection($task->connection);
        return in_array($queue::class, [DatabaseQueue::class, SqliteDatabaseQueue::class,
            SqliteAcknowledgingDatabaseQueue::class], true) ? $queue : null;
    }

    private static function matches(array $reference, WorkflowTask $task, DatabaseQueue $queue): bool
    {
        return ($reference['version'] ?? null) === 1 && ($reference['task_id'] ?? null) === $task->id
            && ($reference['run_id'] ?? null) === $task->workflow_run_id
            && array_key_exists('namespace', $reference) && $reference['namespace'] === $task->namespace
            && ($reference['connection'] ?? null) === $queue->getConnectionName()
            && ($reference['queue'] ?? null) === $queue->getQueue($task->queue)
            && ($reference['database'] ?? null) === $queue->database->getName()
            && ($reference['table'] ?? null) === $queue->table
            && is_string($reference['payload_uuid'] ?? null) && $reference['payload_uuid'] !== ''
            && strlen($reference['payload_uuid']) <= 128
            && is_string($reference['job_id'] ?? null)
            && filter_var($reference['job_id'], FILTER_VALIDATE_INT, [
                'options' => [
                    'min_range' => 1,
                ],
            ]) !== false
            && preg_match('/^[1-9]\d{0,19}$/D', $reference['job_id']) === 1;
    }
}
