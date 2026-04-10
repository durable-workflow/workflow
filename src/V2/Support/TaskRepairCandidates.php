<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowTask;

final class TaskRepairCandidates
{
    /**
     * @return list<string>
     */
    public static function taskIds(?int $limit = null, ?CarbonInterface $now = null): array
    {
        /** @var list<string> $ids */
        $ids = self::existingTaskQuery($now)
            ->orderBy('available_at')
            ->orderBy('created_at')
            ->limit($limit ?? TaskRepairPolicy::scanLimit())
            ->pluck('id')
            ->all();

        return $ids;
    }

    /**
     * @return list<string>
     */
    public static function runIds(?int $limit = null): array
    {
        /** @var list<string> $ids */
        $ids = self::missingTaskRunQuery()
            ->orderBy('wait_started_at')
            ->orderBy('started_at')
            ->orderBy('id')
            ->limit($limit ?? TaskRepairPolicy::scanLimit())
            ->pluck('id')
            ->all();

        return $ids;
    }

    /**
     * @return array{
     *     existing_task_candidates: int,
     *     missing_task_candidates: int,
     *     total_candidates: int,
     *     scan_limit: int,
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
            'scopes' => self::scopeSnapshot($now, $taskCandidates >= $scanLimit || $missingTaskCandidates >= $scanLimit),
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
     *     oldest_task_candidate_created_at: string|null,
     *     oldest_missing_run_started_at: string|null,
     *     max_task_candidate_age_ms: int,
     *     max_missing_run_age_ms: int,
     *     scan_limited_by_global_policy: bool
     * }>
     */
    private static function scopeSnapshot(CarbonInterface $now, bool $scanPressure): array
    {
        $scopes = [];

        foreach (self::existingTaskScopeRows($now) as $row) {
            $connection = self::nullableString($row->connection ?? null);
            $queue = self::nullableString($row->queue ?? null);
            $compatibility = self::nullableString($row->compatibility ?? null);
            $scopeKey = self::scopeKey($connection, $queue, $compatibility);
            $oldestAt = self::timestamp($row->oldest_candidate_at ?? null);

            $scopes[$scopeKey] ??= self::emptyScope($scopeKey, $connection, $queue, $compatibility);
            $scopes[$scopeKey]['existing_task_candidates'] = (int) ($row->candidate_count ?? 0);
            $scopes[$scopeKey]['oldest_task_candidate_created_at'] = $oldestAt?->toJSON();
            $scopes[$scopeKey]['max_task_candidate_age_ms'] = $oldestAt === null
                ? 0
                : (int) $oldestAt->diffInMilliseconds($now);
        }

        foreach (self::missingTaskScopeRows() as $row) {
            $connection = self::nullableString($row->connection ?? null);
            $queue = self::nullableString($row->queue ?? null);
            $compatibility = self::nullableString($row->compatibility ?? null);
            $scopeKey = self::scopeKey($connection, $queue, $compatibility);
            $oldestAt = self::timestamp($row->oldest_candidate_at ?? null);

            $scopes[$scopeKey] ??= self::emptyScope($scopeKey, $connection, $queue, $compatibility);
            $scopes[$scopeKey]['missing_task_candidates'] = (int) ($row->candidate_count ?? 0);
            $scopes[$scopeKey]['oldest_missing_run_started_at'] = $oldestAt?->toJSON();
            $scopes[$scopeKey]['max_missing_run_age_ms'] = $oldestAt === null
                ? 0
                : (int) $oldestAt->diffInMilliseconds($now);
        }

        foreach ($scopes as &$scope) {
            $scope['total_candidates'] = $scope['existing_task_candidates'] + $scope['missing_task_candidates'];
            $scope['scan_limited_by_global_policy'] = $scanPressure && $scope['total_candidates'] > 0;
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

    private static function existingTaskScopeRows(CarbonInterface $now)
    {
        return self::existingTaskQuery($now)
            ->select(['connection', 'queue', 'compatibility'])
            ->selectRaw('COUNT(*) as candidate_count')
            ->selectRaw('MIN(created_at) as oldest_candidate_at')
            ->groupBy('connection', 'queue', 'compatibility')
            ->get();
    }

    private static function missingTaskScopeRows()
    {
        return self::missingTaskRunQuery()
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
            'oldest_task_candidate_created_at' => null,
            'oldest_missing_run_started_at' => null,
            'max_task_candidate_age_ms' => 0,
            'max_missing_run_age_ms' => 0,
            'scan_limited_by_global_policy' => false,
        ];
    }

    private static function scopeKey(?string $connection, ?string $queue, ?string $compatibility): string
    {
        return implode(':', [
            $connection ?? 'default',
            $queue ?? 'default',
            $compatibility ?? 'any',
        ]);
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

    private static function oldestTaskCandidate(?CarbonInterface $now = null): ?WorkflowTask
    {
        /** @var WorkflowTask|null $task */
        $task = self::existingTaskQuery($now)
            ->oldest('created_at')
            ->oldest('id')
            ->first();

        return $task;
    }

    private static function oldestMissingRunCandidate(): ?WorkflowRunSummary
    {
        /** @var WorkflowRunSummary|null $summary */
        $summary = self::missingTaskRunQuery()
            ->orderBy('wait_started_at')
            ->orderBy('started_at')
            ->orderBy('id')
            ->first();

        return $summary;
    }

    private static function existingTaskQuery(?CarbonInterface $now = null)
    {
        $now ??= now();
        $staleDispatchCutoff = $now->copy()
            ->subSeconds(TaskRepairPolicy::redispatchAfterSeconds());

        return WorkflowTask::query()
            ->where(static function ($query) use ($now, $staleDispatchCutoff): void {
                $query->where(static function ($ready) use ($now, $staleDispatchCutoff): void {
                    $ready->where('status', TaskStatus::Ready->value)
                        ->where(static function ($available) use ($now): void {
                            $available->whereNull('available_at')
                                ->orWhere('available_at', '<=', $now);
                        })
                        ->where(static function ($repairable) use ($staleDispatchCutoff): void {
                            $repairable
                                ->where(static function ($dispatchFailed): void {
                                    self::applyDispatchFailed($dispatchFailed);
                                })
                                ->orWhere(static function ($claimFailed) use ($staleDispatchCutoff): void {
                                    self::applyClaimFailed($claimFailed);
                                    $claimFailed->where('last_claim_failed_at', '<=', $staleDispatchCutoff);
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
                                                ->orWhere(static function ($neverDispatched) use ($staleDispatchCutoff): void {
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
    }

    private static function missingTaskRunQuery()
    {
        return WorkflowRunSummary::query()
            ->where('liveness_state', 'repair_needed')
            ->whereNull('next_task_id')
            ->whereIn('status', [
                RunStatus::Pending->value,
                RunStatus::Running->value,
                RunStatus::Waiting->value,
            ]);
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

    private static function applyClaimHealthy($query): void
    {
        $query
            ->whereNull('last_claim_failed_at')
            ->orWhereNull('last_claim_error')
            ->orWhere('last_claim_error', '');
    }
}
