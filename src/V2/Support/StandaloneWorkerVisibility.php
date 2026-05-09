<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Namespace-scoped worker visibility helpers for service-mode deployments.
 *
 * @api Stable class surface consumed by the standalone workflow-server.
 *      The public static method signatures on this class are covered by
 *      the workflow package's semver guarantee. See docs/api-stability.md.
 */
final class StandaloneWorkerVisibility
{
    /**
     * @param class-string<Model> $workerRegistrationModel
     */
    public static function queueSnapshot(
        string $namespace,
        string $workerRegistrationModel,
        ?CarbonInterface $now = null,
        ?int $staleAfterSeconds = null,
    ): QueueVisibilitySnapshot {
        $staleAfterSeconds ??= self::staleAfterSeconds();

        return OperatorQueueVisibility::forNamespace(
            $namespace,
            self::pollersByQueue($namespace, $workerRegistrationModel),
            $now,
            $staleAfterSeconds,
        );
    }

    /**
     * @param class-string<Model> $workerRegistrationModel
     */
    public static function queueDetail(
        string $namespace,
        string $taskQueue,
        string $workerRegistrationModel,
        ?CarbonInterface $now = null,
        ?int $staleAfterSeconds = null,
    ): QueueVisibilityDetail {
        $staleAfterSeconds ??= self::staleAfterSeconds();

        return OperatorQueueVisibility::forQueue(
            $namespace,
            $taskQueue,
            self::pollersForQueue($namespace, $taskQueue, $workerRegistrationModel),
            $now,
            $staleAfterSeconds,
        );
    }

    /**
     * @param class-string<Model> $workerRegistrationModel
     * @return array<string, list<array<string, mixed>>>
     */
    public static function pollersByQueue(string $namespace, string $workerRegistrationModel): array
    {
        $pollers = [];

        foreach (
            self::workerQuery($workerRegistrationModel)
                ->where('namespace', $namespace)
                ->orderBy('task_queue')
                ->orderByDesc('last_heartbeat_at')
                ->orderBy('worker_id')
                ->get() as $worker
        ) {
            $taskQueue = self::stringValue(data_get($worker, 'task_queue'));

            if ($taskQueue === null) {
                continue;
            }

            $pollers[$taskQueue] ??= [];
            $pollers[$taskQueue][] = self::poller($worker);
        }

        return $pollers;
    }

    /**
     * @param class-string<Model> $workerRegistrationModel
     * @return list<array<string, mixed>>
     */
    public static function pollersForQueue(
        string $namespace,
        string $taskQueue,
        string $workerRegistrationModel,
    ): array {
        return self::workerQuery($workerRegistrationModel)
            ->where('namespace', $namespace)
            ->where('task_queue', $taskQueue)
            ->orderByDesc('last_heartbeat_at')
            ->orderBy('worker_id')
            ->get()
            ->map(static fn (Model $worker): array => self::poller($worker))
            ->all();
    }

    public static function staleAfterSeconds(
        ?int $configuredStaleAfterSeconds = null,
        ?int $pollingTimeoutSeconds = null,
    ): int {
        if ($configuredStaleAfterSeconds !== null) {
            return max(1, $configuredStaleAfterSeconds);
        }

        return max(($pollingTimeoutSeconds ?? 30) * 2, 60);
    }

    public static function recordCompatibility(
        string $namespace,
        string $workerId,
        ?string $taskQueue,
        ?string $buildId,
    ): void {
        WorkerCompatibilityFleet::recordForNamespace(
            $namespace,
            self::uniqueStrings([$buildId]),
            connection: null,
            queue: $taskQueue,
            workerId: $workerId,
        );
    }

    /**
     * @return array{
     *     namespace: string,
     *     active_workers: int,
     *     active_worker_scopes: int,
     *     queues: list<string>,
     *     build_ids: list<string>,
     *     workers: list<array{
     *         worker_id: string,
     *         queues: list<string>,
     *         build_ids: list<string>,
     *         recorded_at: string|null,
     *         expires_at: string|null
     *     }>
     * }
     */
    public static function fleetSummary(string $namespace): array
    {
        return WorkerCompatibilityFleet::summaryForNamespace($namespace);
    }

    /**
     * @param class-string<Model> $workerRegistrationModel
     */
    private static function workerQuery(string $workerRegistrationModel): Builder
    {
        if (! is_a($workerRegistrationModel, Model::class, true)) {
            throw new InvalidArgumentException(sprintf(
                'Worker registration model [%s] must extend %s.',
                $workerRegistrationModel,
                Model::class,
            ));
        }

        return $workerRegistrationModel::query();
    }

    /**
     * @return array<string, mixed>
     */
    private static function poller(Model $worker): array
    {
        return [
            'worker_id' => self::stringValue(data_get($worker, 'worker_id')),
            'runtime' => self::stringValue(data_get($worker, 'runtime')),
            'sdk_version' => self::stringValue(data_get($worker, 'sdk_version')),
            'build_id' => self::stringValue(data_get($worker, 'build_id')),
            'last_heartbeat_at' => self::carbon(data_get($worker, 'last_heartbeat_at')),
            'status' => self::stringValue(data_get($worker, 'status')) ?? 'active',
            'supported_workflow_types' => self::stringList(data_get($worker, 'supported_workflow_types')),
            'supported_activity_types' => self::stringList(data_get($worker, 'supported_activity_types')),
            'max_concurrent_workflow_tasks' => max(0, (int) data_get($worker, 'max_concurrent_workflow_tasks', 0)),
            'max_concurrent_activity_tasks' => max(0, (int) data_get($worker, 'max_concurrent_activity_tasks', 0)),
            'available_workflow_slots' => self::nonNegativeIntOrNull(data_get($worker, 'available_workflow_slots')),
            'available_activity_slots' => self::nonNegativeIntOrNull(data_get($worker, 'available_activity_slots')),
            'available_session_slots' => self::nonNegativeIntOrNull(data_get($worker, 'available_session_slots')),
            'process_metrics' => self::processMetrics(data_get($worker, 'process_metrics')),
        ];
    }

    private static function nonNegativeIntOrNull(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return max(0, $value);
        }

        if (is_string($value) && ctype_digit($value)) {
            return max(0, (int) $value);
        }

        return null;
    }

    /**
     * @return array<string, float|int|string>|null
     */
    private static function processMetrics(mixed $value): ?array
    {
        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : null;
        }

        if (! is_array($value) || $value === []) {
            return null;
        }

        $allowed = ['cpu_percent', 'memory_bytes', 'process_uptime_seconds', 'process_id', 'host'];
        $clean = [];

        foreach ($allowed as $key) {
            if (! array_key_exists($key, $value) || $value[$key] === null) {
                continue;
            }

            if ($key === 'host' && is_string($value[$key]) && trim($value[$key]) !== '') {
                $clean[$key] = mb_substr(trim($value[$key]), 0, 255);

                continue;
            }

            if ($key === 'cpu_percent' && (is_int($value[$key]) || is_float($value[$key]))) {
                $clean[$key] = max(0.0, (float) $value[$key]);

                continue;
            }

            if (is_int($value[$key])) {
                $clean[$key] = max(0, $value[$key]);
            } elseif (is_string($value[$key]) && ctype_digit($value[$key])) {
                $clean[$key] = max(0, (int) $value[$key]);
            }
        }

        return $clean === [] ? null : $clean;
    }

    private static function stringValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private static function carbon(mixed $value): CarbonInterface|string|null
    {
        if ($value instanceof CarbonInterface) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }

        if (is_string($value) && trim($value) !== '') {
            return $value;
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : explode(',', $value);
        }

        if (! is_array($value)) {
            return [];
        }

        return self::uniqueStrings($value);
    }

    /**
     * @param array<int, mixed> $values
     * @return list<string>
     */
    private static function uniqueStrings(array $values): array
    {
        $strings = array_values(array_unique(array_filter(
            array_map(static fn (mixed $value): ?string => self::stringValue($value), $values),
            static fn (?string $value): bool => $value !== null,
        )));

        sort($strings);

        return $strings;
    }
}
