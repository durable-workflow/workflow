<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Carbon\CarbonInterface;
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
     *     max_missing_run_age_ms: int
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
        ];
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
