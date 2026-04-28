<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowTask;

/**
 * Scans the task table for stuck or expired workflow tasks that need to be
 * redispatched, returning a fair round-robin batch of candidate task ids.
 *
 * @api Stable class surface consumed by the standalone workflow-server.
 *      The public static method signatures on this class are covered by
 *      the workflow package's semver guarantee. See docs/api-stability.md.
 */
final class TaskRepairCandidates
{
    /**
     * @return list<string>
     */
    public static function taskIds(
        ?int $limit = null,
        ?CarbonInterface $now = null,
        array $runIds = [],
        ?string $instanceId = null,
        ?string $connection = null,
        ?string $queue = null,
    ): array {
        return self::roundRobinCandidateIds(
            self::existingTaskIdsByScope(
                $limit ?? TaskRepairPolicy::scanLimit(),
                $now,
                $runIds,
                $instanceId,
                $connection,
                $queue,
            ),
            $limit ?? TaskRepairPolicy::scanLimit(),
        );
    }

    /**
     * @return list<string>
     */
    public static function runIds(
        ?int $limit = null,
        array $runIds = [],
        ?string $instanceId = null,
        ?string $connection = null,
        ?string $queue = null,
    ): array {
        return self::roundRobinCandidateIds(
            self::missingTaskRunIdsByScope(
                $limit ?? TaskRepairPolicy::scanLimit(),
                $runIds,
                $instanceId,
                $connection,
                $queue,
            ),
            $limit ?? TaskRepairPolicy::scanLimit(),
        );
    }

    /**
     * @return array{
     *     existing_task_candidates: int,
     *     missing_task_candidates: int,
     *     total_candidates: int,
     *     scan_limit: int,
     *     scan_strategy: string,
     *     selected_existing_task_candidates: int,
     *     selected_missing_task_candidates: int,
     *     selected_total_candidates: int,
     *     existing_task_scan_limit_reached: bool,
     *     missing_task_scan_limit_reached: bool,
     *     scan_pressure: bool,
     *     oldest_task_candidate_created_at: string|null,
     *     oldest_missing_run_started_at: string|null,
     *     max_task_candidate_age_ms: int,
     *     max_missing_run_age_ms: int,
     *     scopes: list<array{
     *         scope_key: string,
     *         connection: string|null,
     *         queue: string|null,
     *         compatibility: string|null,
     *         existing_task_candidates: int,
     *         missing_task_candidates: int,
     *         total_candidates: int,
     *         selected_existing_task_candidates: int,
     *         selected_missing_task_candidates: int,
     *         selected_total_candidates: int,
     *         oldest_task_candidate_created_at: string|null,
     *         oldest_missing_run_started_at: string|null,
     *         max_task_candidate_age_ms: int,
     *         max_missing_run_age_ms: int,
     *         scan_limited_by_global_policy: bool
     *     }>
     * }
     */
    public static function snapshot(?CarbonInterface $now = null): array
    {
        $now ??= now();
        $scanLimit = TaskRepairPolicy::scanLimit();
        $taskCandidates = self::existingTaskQuery($now)->count();
        $missingTaskCandidates = self::missingTaskRunQuery()->count();
        $selectedTaskCounts = self::selectedScopeCounts(
            self::existingTaskIdsByScope($scanLimit, $now),
            $scanLimit,
        );
        $selectedMissingRunCounts = self::selectedScopeCounts(
            self::missingTaskRunIdsByScope($scanLimit),
            $scanLimit,
        );
        $oldestTaskCandidate = self::oldestTaskCandidate($now);
        $oldestMissingRunCandidate = self::oldestMissingRunCandidate();
        $oldestTaskCandidateAt = $oldestTaskCandidate?->created_at;
        $oldestMissingRunCandidateAt = $oldestMissingRunCandidate === null
            ? null
            : ($oldestMissingRunCandidate->wait_started_at
                ?? $oldestMissingRunCandidate->started_at
                ?? $oldestMissingRunCandidate->created_at);

        return [
            'existing_task_candidates' => $taskCandidates,
            'missing_task_candidates' => $missingTaskCandidates,
            'total_candidates' => $taskCandidates + $missingTaskCandidates,
            'scan_limit' => $scanLimit,
            'scan_strategy' => TaskRepairPolicy::SCAN_STRATEGY,
            'selected_existing_task_candidates' => array_sum($selectedTaskCounts),
            'selected_missing_task_candidates' => array_sum($selectedMissingRunCounts),
            'selected_total_candidates' => array_sum($selectedTaskCounts) + array_sum($selectedMissingRunCounts),
            'existing_task_scan_limit_reached' => $taskCandidates >= $scanLimit,
            'missing_task_scan_limit_reached' => $missingTaskCandidates >= $scanLimit,
            'scan_pressure' => $taskCandidates >= $scanLimit || $missingTaskCandidates >= $scanLimit,
            'oldest_task_candidate_created_at' => $oldestTaskCandidateAt?->toJSON(),
            'oldest_missing_run_started_at' => $oldestMissingRunCandidateAt?->toJSON(),
            'max_task_candidate_age_ms' => $oldestTaskCandidateAt === null
                ? 0
                : (int) $oldestTaskCandidateAt->diffInMilliseconds($now),
            'max_missing_run_age_ms' => $oldestMissingRunCandidateAt === null
                ? 0
                : (int) $oldestMissingRunCandidateAt->diffInMilliseconds($now),
            'scopes' => self::scopeSnapshot($now, $selectedTaskCounts, $selectedMissingRunCounts),
        ];
    }

    /**
     * @return list<array{
     *     scope_key: string,
     *     connection: string|null,
     *     queue: string|null,
     *     compatibility: string|null,
     *     existing_task_candidates: int,
     *     missing_task_candidates: int,
     *     total_candidates: int,
     *     selected_existing_task_candidates: int,
     *     selected_missing_task_candidates: int,
     *     selected_total_candidates: int,
     *     oldest_task_candidate_created_at: string|null,
     *     oldest_missing_run_started_at: string|null,
     *     max_task_candidate_age_ms: int,
     *     max_missing_run_age_ms: int,
     *     scan_limited_by_global_policy: bool
     * }>
     */
    private static function scopeSnapshot(
        CarbonInterface $now,
        array $selectedTaskCounts,
        array $selectedMissingRunCounts,
    ): array {
        $scopes = [];

        foreach (self::existingTaskScopeRows($now) as $row) {
            $connection = self::nullableString($row->connection ?? null);
            $queue = self::nullableString($row->queue ?? null);
            $compatibility = self::nullableString($row->compatibility ?? null);
            $scopeKey = self::scopeKey($connection, $queue, $compatibility);
            $oldestAt = self::timestamp($row->oldest_candidate_at ?? null);

            $scopes[$scopeKey] ??= self::emptyScope($scopeKey, $connection, $queue, $compatibility);
            $scopes[$scopeKey]['existing_task_candidates'] += (int) ($row->candidate_count ?? 0);

            $currentOldestAt = self::timestamp($scopes[$scopeKey]['oldest_task_candidate_created_at']);

            if ($oldestAt !== null && ($currentOldestAt === null || $oldestAt->lt($currentOldestAt))) {
                $scopes[$scopeKey]['oldest_task_candidate_created_at'] = $oldestAt->toJSON();
                $scopes[$scopeKey]['max_task_candidate_age_ms'] = (int) $oldestAt->diffInMilliseconds($now);
            }
        }

        foreach (self::missingTaskScopeRows() as $row) {
            $connection = self::nullableString($row->connection ?? null);
            $queue = self::nullableString($row->queue ?? null);
            $compatibility = self::nullableString($row->compatibility ?? null);
            $scopeKey = self::scopeKey($connection, $queue, $compatibility);
            $oldestAt = self::timestamp($row->oldest_candidate_at ?? null);

            $scopes[$scopeKey] ??= self::emptyScope($scopeKey, $connection, $queue, $compatibility);
            $scopes[$scopeKey]['missing_task_candidates'] += (int) ($row->candidate_count ?? 0);

            $currentOldestAt = self::timestamp($scopes[$scopeKey]['oldest_missing_run_started_at']);

            if ($oldestAt !== null && ($currentOldestAt === null || $oldestAt->lt($currentOldestAt))) {
                $scopes[$scopeKey]['oldest_missing_run_started_at'] = $oldestAt->toJSON();
                $scopes[$scopeKey]['max_missing_run_age_ms'] = (int) $oldestAt->diffInMilliseconds($now);
            }
        }

        foreach ($scopes as &$scope) {
            $scope['total_candidates'] = $scope['existing_task_candidates'] + $scope['missing_task_candidates'];
            $scope['selected_existing_task_candidates'] = $selectedTaskCounts[$scope['scope_key']] ?? 0;
            $scope['selected_missing_task_candidates'] = $selectedMissingRunCounts[$scope['scope_key']] ?? 0;
            $scope['selected_total_candidates'] = $scope['selected_existing_task_candidates']
                + $scope['selected_missing_task_candidates'];
            $scope['scan_limited_by_global_policy'] = $scope['total_candidates'] > $scope['selected_total_candidates'];
        }

        unset($scope);

        usort($scopes, static function (array $left, array $right): int {
            if ($left['total_candidates'] !== $right['total_candidates']) {
                return $right['total_candidates'] <=> $left['total_candidates'];
            }

            $leftAge = max($left['max_task_candidate_age_ms'], $left['max_missing_run_age_ms']);
            $rightAge = max($right['max_task_candidate_age_ms'], $right['max_missing_run_age_ms']);

            if ($leftAge !== $rightAge) {
                return $rightAge <=> $leftAge;
            }

            return $left['scope_key'] <=> $right['scope_key'];
        });

        return array_values($scopes);
    }

    private static function existingTaskScopeRows(
        CarbonInterface $now,
        array $runIds = [],
        ?string $instanceId = null,
        ?string $connection = null,
        ?string $queue = null,
    ) {
        return self::existingTaskQuery($now, $runIds, $instanceId, $connection, $queue)
            ->select(['connection', 'queue', 'compatibility'])
            ->selectRaw('COUNT(*) as candidate_count')
            ->selectRaw('MIN(created_at) as oldest_candidate_at')
            ->groupBy('connection', 'queue', 'compatibility')
            ->get();
    }

    private static function missingTaskScopeRows(
        array $runIds = [],
        ?string $instanceId = null,
        ?string $connection = null,
        ?string $queue = null,
    ) {
        return self::missingTaskRunQuery($runIds, $instanceId, $connection, $queue)
            ->select(['connection', 'queue', 'compatibility'])
            ->selectRaw('COUNT(*) as candidate_count')
            ->selectRaw('MIN(COALESCE(wait_started_at, started_at, created_at)) as oldest_candidate_at')
            ->groupBy('connection', 'queue', 'compatibility')
            ->get();
    }

    /**
     * @return array{
     *     scope_key: string,
     *     connection: string|null,
     *     queue: string|null,
     *     compatibility: string|null,
     *     existing_task_candidates: int,
     *     missing_task_candidates: int,
     *     total_candidates: int,
     *     selected_existing_task_candidates: int,
     *     selected_missing_task_candidates: int,
     *     selected_total_candidates: int,
     *     oldest_task_candidate_created_at: string|null,
     *     oldest_missing_run_started_at: string|null,
     *     max_task_candidate_age_ms: int,
     *     max_missing_run_age_ms: int,
     *     scan_limited_by_global_policy: bool
     * }
     */
    private static function emptyScope(
        string $scopeKey,
        ?string $connection,
        ?string $queue,
        ?string $compatibility,
    ): array {
        return [
            'scope_key' => $scopeKey,
            'connection' => $connection,
            'queue' => $queue,
            'compatibility' => $compatibility,
            'existing_task_candidates' => 0,
            'missing_task_candidates' => 0,
            'total_candidates' => 0,
            'selected_existing_task_candidates' => 0,
            'selected_missing_task_candidates' => 0,
            'selected_total_candidates' => 0,
            'oldest_task_candidate_created_at' => null,
            'oldest_missing_run_started_at' => null,
            'max_task_candidate_age_ms' => 0,
            'max_missing_run_age_ms' => 0,
            'scan_limited_by_global_policy' => false,
        ];
    }

    private static function scopeKey(?string $connection, ?string $queue, ?string $compatibility): string
    {
        return implode(':', [$connection ?? 'default', $queue ?? 'default', $compatibility ?? 'any']);
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== ''
            ? $value
            : null;
    }

    private static function timestamp(mixed $value): ?CarbonInterface
    {
        if ($value instanceof CarbonInterface) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            return Carbon::parse($value);
        }

        return null;
    }

    private static function oldestTaskCandidate(
        ?CarbonInterface $now = null,
        array $runIds = [],
        ?string $instanceId = null,
    ): ?WorkflowTask {
        /** @var WorkflowTask|null $task */
        $task = self::existingTaskQuery($now, $runIds, $instanceId)
            ->oldest('created_at')
            ->oldest('id')
            ->first();

        return $task;
    }

    /**
     * @return array<string, list<string>>
     */
    private static function existingTaskIdsByScope(
        int $limit,
        ?CarbonInterface $now = null,
        array $runIds = [],
        ?string $instanceId = null,
        ?string $connection = null,
        ?string $queue = null,
    ): array {
        $now ??= now();
        $buckets = [];

        foreach (self::normalizedScopes(self::existingTaskScopeRows(
            $now,
            $runIds,
            $instanceId,
            $connection,
            $queue,
        )) as $scope) {
            /** @var list<string> $ids */
            $ids = self::existingTaskQuery($now, $runIds, $instanceId, $connection, $queue)
                ->where(static function ($query) use ($scope): void {
                    self::applyScope($query, $scope['connection'], $scope['queue'], $scope['compatibility']);
                })
                ->orderBy('available_at')
                ->orderBy('created_at')
                ->orderBy('id')
                ->limit($limit)
                ->pluck('id')
                ->all();

            if ($ids !== []) {
                $buckets[$scope['scope_key']] = $ids;
            }
        }

        return $buckets;
    }

    /**
     * @return array<string, list<string>>
     */
    private static function missingTaskRunIdsByScope(
        int $limit,
        array $runIds = [],
        ?string $instanceId = null,
        ?string $connection = null,
        ?string $queue = null,
    ): array {
        $buckets = [];

        foreach (self::normalizedScopes(self::missingTaskScopeRows(
            $runIds,
            $instanceId,
            $connection,
            $queue,
        )) as $scope) {
            /** @var list<string> $ids */
            $ids = self::missingTaskRunQuery($runIds, $instanceId, $connection, $queue)
                ->where(static function ($query) use ($scope): void {
                    self::applyScope($query, $scope['connection'], $scope['queue'], $scope['compatibility']);
                })
                ->orderBy('wait_started_at')
                ->orderBy('started_at')
                ->orderBy('id')
                ->limit($limit)
                ->pluck('id')
                ->all();

            if ($ids !== []) {
                $buckets[$scope['scope_key']] = $ids;
            }
        }

        return $buckets;
    }

    /**
     * @param iterable<object> $rows
     * @return list<array{scope_key: string, connection: string|null, queue: string|null, compatibility: string|null, oldest_candidate_at: CarbonInterface|null}>
     */
    private static function normalizedScopes(iterable $rows): array
    {
        $scopes = [];

        foreach ($rows as $row) {
            $connection = self::nullableString($row->connection ?? null);
            $queue = self::nullableString($row->queue ?? null);
            $compatibility = self::nullableString($row->compatibility ?? null);
            $scopeKey = self::scopeKey($connection, $queue, $compatibility);
            $oldestAt = self::timestamp($row->oldest_candidate_at ?? null);

            if (
                ! isset($scopes[$scopeKey])
                || (
                    $oldestAt !== null
                    && (
                        $scopes[$scopeKey]['oldest_candidate_at'] === null
                        || $oldestAt->lt($scopes[$scopeKey]['oldest_candidate_at'])
                    )
                )
            ) {
                $scopes[$scopeKey] = [
                    'scope_key' => $scopeKey,
                    'connection' => $connection,
                    'queue' => $queue,
                    'compatibility' => $compatibility,
                    'oldest_candidate_at' => $oldestAt,
                ];
            }
        }

        usort($scopes, static function (array $left, array $right): int {
            $leftOldest = $left['oldest_candidate_at']?->getTimestampMs() ?? PHP_INT_MAX;
            $rightOldest = $right['oldest_candidate_at']?->getTimestampMs() ?? PHP_INT_MAX;

            if ($leftOldest !== $rightOldest) {
                return $leftOldest <=> $rightOldest;
            }

            return $left['scope_key'] <=> $right['scope_key'];
        });

        return array_values($scopes);
    }

    /**
     * @param array<string, list<string>> $buckets
     * @return list<string>
     */
    private static function roundRobinCandidateIds(array $buckets, int $limit): array
    {
        return array_map(
            static fn (array $candidate): string => $candidate['id'],
            self::roundRobinCandidates($buckets, $limit),
        );
    }

    /**
     * @param array<string, list<string>> $buckets
     * @return array<string, int>
     */
    private static function selectedScopeCounts(array $buckets, int $limit): array
    {
        $counts = [];

        foreach (self::roundRobinCandidates($buckets, $limit) as $candidate) {
            $scopeKey = $candidate['scope_key'];
            $counts[$scopeKey] = ($counts[$scopeKey] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @param array<string, list<string>> $buckets
     * @return list<array{scope_key: string, id: string}>
     */
    private static function roundRobinCandidates(array $buckets, int $limit): array
    {
        $selected = [];

        while (count($selected) < $limit) {
            $madeProgress = false;

            foreach ($buckets as $scopeKey => $ids) {
                if ($ids === []) {
                    continue;
                }

                $id = array_shift($ids);
                $buckets[$scopeKey] = $ids;
                $selected[] = [
                    'scope_key' => $scopeKey,
                    'id' => $id,
                ];
                $madeProgress = true;

                if (count($selected) >= $limit) {
                    break;
                }
            }

            if (! $madeProgress) {
                break;
            }
        }

        return $selected;
    }

    private static function oldestMissingRunCandidate(
        array $runIds = [],
        ?string $instanceId = null,
    ): ?WorkflowRunSummary {
        /** @var WorkflowRunSummary|null $summary */
        $summary = self::missingTaskRunQuery($runIds, $instanceId)
            ->orderBy('wait_started_at')
            ->orderBy('started_at')
            ->orderBy('id')
            ->first();

        return $summary;
    }

    private static function existingTaskQuery(
        ?CarbonInterface $now = null,
        array $runIds = [],
        ?string $instanceId = null,
        ?string $connection = null,
        ?string $queue = null,
    ) {
        $now ??= now();
        $staleDispatchCutoff = $now->copy()
            ->subSeconds(TaskRepairPolicy::redispatchAfterSeconds());

        $query = WorkflowTask::query()
            ->where(static function ($query) use ($now, $staleDispatchCutoff): void {
                $query->where(static function ($ready) use ($now, $staleDispatchCutoff): void {
                    $ready->where('status', TaskStatus::Ready->value)
                        ->where(static function ($available) use ($now): void {
                            $available->whereNull('available_at')
                                ->orWhere('available_at', '<=', $now);
                        })
                        ->where(static function ($repairable) use ($now, $staleDispatchCutoff): void {
                            $repairable
                                ->where(static function ($dispatchFailed) use ($now): void {
                                    self::applyDispatchFailed($dispatchFailed);
                                    self::applyRepairBackoffReady($dispatchFailed, $now);
                                })
                                ->orWhere(static function ($claimFailed) use ($now, $staleDispatchCutoff): void {
                                    self::applyClaimFailed($claimFailed);
                                    $claimFailed->where(static function ($claimRepairable) use (
                                        $now,
                                        $staleDispatchCutoff
                                    ): void {
                                        $claimRepairable
                                            ->where(static function ($backoffReady) use ($now): void {
                                                $backoffReady->whereNotNull('repair_available_at')
                                                    ->where('repair_available_at', '<=', $now);
                                            })
                                            ->orWhere(static function ($legacyClaimFailure) use (
                                                $staleDispatchCutoff
                                            ): void {
                                                $legacyClaimFailure->whereNull('repair_available_at')
                                                    ->where('last_claim_failed_at', '<=', $staleDispatchCutoff);
                                            });
                                    });
                                })
                                ->orWhere(static function ($dispatchOverdue) use ($staleDispatchCutoff): void {
                                    $dispatchOverdue
                                        ->where(static function ($dispatchHealthy): void {
                                            self::applyDispatchHealthy($dispatchHealthy);
                                        })
                                        ->where(static function ($claimHealthy): void {
                                            self::applyClaimHealthy($claimHealthy);
                                        })
                                        ->where(static function ($dispatch) use ($staleDispatchCutoff): void {
                                            $dispatch->where('last_dispatched_at', '<=', $staleDispatchCutoff)
                                                ->orWhere(static function ($neverDispatched) use (
                                                    $staleDispatchCutoff
                                                ): void {
                                                    $neverDispatched->whereNull('last_dispatched_at')
                                                        ->where('created_at', '<=', $staleDispatchCutoff);
                                                });
                                        });
                                });
                        });
                })->orWhere(static function ($leased) use ($now): void {
                    $leased->where('status', TaskStatus::Leased->value)
                        ->whereNotNull('lease_expires_at')
                        ->where('lease_expires_at', '<=', $now);
                });
            });

        self::applyRunSelection($query, $runIds, $instanceId);
        RequestedTaskScope::apply($query, $connection, $queue);

        return $query;
    }

    private static function missingTaskRunQuery(
        array $runIds = [],
        ?string $instanceId = null,
        ?string $connection = null,
        ?string $queue = null,
    ) {
        $query = WorkflowRunSummary::query()
            ->where('liveness_state', 'repair_needed')
            ->whereNull('next_task_id')
            ->whereIn('status', [RunStatus::Pending->value, RunStatus::Running->value, RunStatus::Waiting->value]);

        if ($runIds !== []) {
            $query->whereKey($runIds);
        }

        if ($instanceId !== null) {
            $query->where('workflow_instance_id', $instanceId);
        }

        RequestedTaskScope::apply($query, $connection, $queue);

        return $query;
    }

    /**
     * @param list<string> $runIds
     */
    private static function applyRunSelection($query, array $runIds, ?string $instanceId): void
    {
        if ($runIds !== []) {
            $query->whereIn('workflow_run_id', $runIds);
        }

        if ($instanceId !== null) {
            $query->whereIn('workflow_run_id', self::runModel()::query()
                ->select('id')
                ->where('workflow_instance_id', $instanceId));
        }
    }

    private static function applyScope(
        $query,
        ?string $connection,
        ?string $queue,
        ?string $compatibility,
    ): void {
        self::applyNullableScopeValue($query, 'connection', $connection);
        self::applyNullableScopeValue($query, 'queue', $queue);
        self::applyNullableScopeValue($query, 'compatibility', $compatibility);
    }

    private static function applyNullableScopeValue($query, string $column, ?string $value): void
    {
        if ($value === null) {
            $publicDefault = $column === 'compatibility' ? 'any' : 'default';

            $query->where(static function ($nullable) use ($column, $publicDefault): void {
                $nullable->whereNull($column)
                    ->orWhere($column, '')
                    ->orWhere($column, $publicDefault);
            });

            return;
        }

        $query->where($column, $value);
    }

    private static function applyDispatchFailed($query): void
    {
        $query
            ->whereNotNull('last_dispatch_attempt_at')
            ->whereNotNull('last_dispatch_error')
            ->where('last_dispatch_error', '!=', '')
            ->where(static function ($dispatch): void {
                $dispatch->whereNull('last_dispatched_at')
                    ->orWhereColumn('last_dispatch_attempt_at', '>', 'last_dispatched_at');
            });
    }

    private static function applyDispatchHealthy($query): void
    {
        $query
            ->whereNull('last_dispatch_attempt_at')
            ->orWhereNull('last_dispatch_error')
            ->orWhere('last_dispatch_error', '')
            ->orWhere(static function ($successfulDispatch): void {
                $successfulDispatch->whereNotNull('last_dispatched_at')
                    ->whereColumn('last_dispatch_attempt_at', '<=', 'last_dispatched_at');
            });
    }

    private static function applyClaimFailed($query): void
    {
        $query
            ->whereNotNull('last_claim_failed_at')
            ->whereNotNull('last_claim_error')
            ->where('last_claim_error', '!=', '');
    }

    private static function applyRepairBackoffReady($query, CarbonInterface $now): void
    {
        $query->where(static function ($repairBackoff) use ($now): void {
            $repairBackoff->whereNull('repair_available_at')
                ->orWhere('repair_available_at', '<=', $now);
        });
    }

    private static function applyClaimHealthy($query): void
    {
        $query
            ->whereNull('last_claim_failed_at')
            ->orWhereNull('last_claim_error')
            ->orWhere('last_claim_error', '');
    }

    /**
     * @return class-string<\Workflow\V2\Models\WorkflowRun>
     */
    private static function runModel(): string
    {
        /** @var class-string<\Workflow\V2\Models\WorkflowRun> $model */
        $model = config('workflows.v2.run_model', \Workflow\V2\Models\WorkflowRun::class);

        return $model;
    }
}
