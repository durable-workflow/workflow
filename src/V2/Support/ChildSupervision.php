<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\WorkflowChildSupervision;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRun;

/**
 * Supervision of open children across continue-as-new (#868).
 *
 * A run that continues as new hands every child it still supervises to the
 * continued run: the children it started itself and the children an earlier
 * run of the chain handed to it. The child link, child call, child identity,
 * deadlines and parent-close policy stay exactly as recorded. Only the run
 * that applies the policy changes, and the chain's final run applies it when
 * it closes. Abandon stays abandon.
 *
 * The transfer is part of the continue-as-new transaction, which holds the
 * closing run's lock. A child that closes at the same time waits for that
 * lock before it decides whether to resume the closing run, and then sees the
 * run closed. The transfer only inserts rows, so it never waits on a link that
 * a closing child has locked.
 */
final class ChildSupervision
{
    /**
     * Hand the closing run's open children to the continued run.
     *
     * Call inside the continue-as-new transaction, after the continued run
     * exists and before the closing run is released.
     */
    public static function transferToContinuedRun(WorkflowRun $closingRun, WorkflowRun $continuedRun): void
    {
        foreach (self::supervisedChildren($closingRun) as $child) {
            if (! $child['open']) {
                continue;
            }

            ConfiguredV2Models::query('child_supervision_model', WorkflowChildSupervision::class)->create([
                'supervising_workflow_run_id' => $continuedRun->id,
                'transferred_from_workflow_run_id' => $closingRun->id,
                'workflow_link_id' => $child['workflow_link_id'],
                'parent_workflow_instance_id' => $child['parent_workflow_instance_id'],
                'parent_workflow_run_id' => $child['parent_workflow_run_id'],
                'sequence' => $child['sequence'],
                'child_workflow_instance_id' => $child['child_workflow_instance_id'],
                'child_workflow_run_id' => $child['child_run']?->id ?? $child['child_workflow_run_id'],
                'parent_close_policy' => $child['parent_close_policy'],
            ]);
        }
    }

    /**
     * Children whose parent-close policy the given run applies when it closes:
     * the children it started and those handed to it by an earlier run of its
     * continue-as-new chain.
     *
     * @return list<array{
     *     workflow_link_id: string,
     *     parent_workflow_instance_id: string,
     *     parent_workflow_run_id: string,
     *     sequence: int|null,
     *     child_workflow_instance_id: string,
     *     child_workflow_run_id: string,
     *     parent_close_policy: string,
     *     child_run: WorkflowRun|null,
     *     open: bool,
     *     transferred: bool
     * }>
     */
    public static function supervisedChildren(WorkflowRun $run): array
    {
        $children = [];

        // Children the run started itself. A child that continued as new
        // while this run was open has one link per child run; the first link
        // keeps the child call identity, and the latest names the child run.
        $links = ConfiguredV2Models::query('link_model', WorkflowLink::class)
            ->where('parent_workflow_run_id', $run->id)
            ->where('link_type', 'child_workflow')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        foreach ($links as $link) {
            $key = sprintf('%s|%s', $link->sequence ?? '', $link->child_workflow_instance_id);
            $childRun = $link->childRun;

            if (isset($children[$key])) {
                $children[$key]['child_workflow_run_id'] = $link->child_workflow_run_id;
                $children[$key]['child_run'] = $childRun instanceof WorkflowRun ? $childRun : null;

                continue;
            }

            $children[$key] = [
                'workflow_link_id' => (string) $link->id,
                'parent_workflow_instance_id' => (string) $link->parent_workflow_instance_id,
                'parent_workflow_run_id' => (string) $link->parent_workflow_run_id,
                'sequence' => is_int($link->sequence) ? $link->sequence : null,
                'child_workflow_instance_id' => (string) $link->child_workflow_instance_id,
                'child_workflow_run_id' => (string) $link->child_workflow_run_id,
                'parent_close_policy' => (string) $link->parent_close_policy,
                'child_run' => $childRun instanceof WorkflowRun ? $childRun : null,
                'open' => false,
                'transferred' => false,
            ];
        }

        foreach (self::transferredTo($run) as $supervision) {
            /** @var WorkflowRun|null $childRun */
            $childRun = ConfiguredV2Models::query('run_model', WorkflowRun::class)
                ->find($supervision->child_workflow_run_id);

            $children['supervision|' . $supervision->id] = [
                'workflow_link_id' => (string) $supervision->workflow_link_id,
                'parent_workflow_instance_id' => (string) $supervision->parent_workflow_instance_id,
                'parent_workflow_run_id' => (string) $supervision->parent_workflow_run_id,
                'sequence' => is_int($supervision->sequence) ? $supervision->sequence : null,
                'child_workflow_instance_id' => (string) $supervision->child_workflow_instance_id,
                'child_workflow_run_id' => (string) $supervision->child_workflow_run_id,
                'parent_close_policy' => (string) $supervision->parent_close_policy,
                'child_run' => $childRun,
                'open' => false,
                'transferred' => true,
            ];
        }

        $resolved = [];

        foreach ($children as $child) {
            $childRun = self::currentChildRun($child['child_run'], $child['child_workflow_instance_id']);
            $child['child_run'] = $childRun;
            $child['open'] = $childRun instanceof WorkflowRun && ! self::isTerminal($childRun);
            $resolved[] = $child;
        }

        return $resolved;
    }

    /**
     * Children handed to the given run by the run it continued from.
     *
     * @return list<WorkflowChildSupervision>
     */
    public static function transferredTo(WorkflowRun $run): array
    {
        /** @var list<WorkflowChildSupervision> $supervisions */
        $supervisions = array_values(
            ConfiguredV2Models::query('child_supervision_model', WorkflowChildSupervision::class)
                ->where('supervising_workflow_run_id', $run->id)
                ->orderBy('created_at')
                ->orderBy('id')
                ->get()
                ->all()
        );

        return $supervisions;
    }

    /**
     * Whether the given run supervises the child instance through a transfer
     * from an earlier run of its continue-as-new chain.
     */
    public static function supervisesTransferredChild(string $supervisingRunId, string $childInstanceId): bool
    {
        return ConfiguredV2Models::query('child_supervision_model', WorkflowChildSupervision::class)
            ->where('supervising_workflow_run_id', $supervisingRunId)
            ->where('child_workflow_instance_id', $childInstanceId)
            ->exists();
    }

    /**
     * The open run that supervises a child its chain handed over, if any.
     *
     * A child that fails after the run that started it continued as new is
     * retried under the retry policy that run recorded, with the same call
     * identity and attempt count, but only while a run of the chain still
     * supervises it. Once the chain has closed, its parent-close policy has
     * been applied and a later failure of the child is final.
     */
    public static function openSupervisorOfHandedOverChild(
        WorkflowRun $parentRun,
        int $sequence,
        string $childInstanceId,
    ): ?WorkflowRun {
        if ($parentRun->closed_reason !== 'continued') {
            return null;
        }

        $supervisingRunIds = ConfiguredV2Models::query('child_supervision_model', WorkflowChildSupervision::class)
            ->where('parent_workflow_run_id', $parentRun->id)
            ->where('sequence', $sequence)
            ->where('child_workflow_instance_id', $childInstanceId)
            ->pluck('supervising_workflow_run_id')
            ->all();

        if ($supervisingRunIds === []) {
            return null;
        }

        return ConfiguredV2Models::query('run_model', WorkflowRun::class)
            ->whereIn('id', $supervisingRunIds)
            ->get()
            ->first(static fn (WorkflowRun $run): bool => ! self::isTerminal($run));
    }

    /**
     * Whether a failed child of a closed parent is a handed-over child that a
     * run of the chain still supervises. If so, the parent's child relations
     * are loaded for the retry.
     */
    public static function prepareHandedOverRetry(WorkflowRun $parentRun, int $sequence, WorkflowRun $childRun): bool
    {
        if (self::openSupervisorOfHandedOverChild(
            $parentRun,
            $sequence,
            (string) $childRun->workflow_instance_id,
        ) === null) {
            return false;
        }

        $parentRun->loadMissing([
            'historyEvents',
            'childLinks.childRun.instance.currentRun',
            'childLinks.childRun.failures',
            'childLinks.childRun.historyEvents',
        ]);

        return true;
    }

    /**
     * Point every supervision of a handed-over child at its retry run, so the
     * chain applies its parent-close policy to the run that is now open.
     */
    public static function followRetry(WorkflowRun $parentRun, int $sequence, WorkflowRun $retryRun): void
    {
        ConfiguredV2Models::query('child_supervision_model', WorkflowChildSupervision::class)
            ->where('parent_workflow_run_id', $parentRun->id)
            ->where('sequence', $sequence)
            ->where('child_workflow_instance_id', $retryRun->workflow_instance_id)
            ->update([
                'child_workflow_run_id' => $retryRun->id,
            ]);
    }

    /**
     * A child that continued as new after its parent stopped watching it is
     * still the same child: follow it to its current run.
     */
    public static function currentChildRun(?WorkflowRun $childRun, string $childInstanceId): ?WorkflowRun
    {
        if (! $childRun instanceof WorkflowRun || $childRun->closed_reason !== 'continued') {
            return $childRun;
        }

        $currentRun = CurrentRunResolver::forRun($childRun);

        return $currentRun instanceof WorkflowRun
            && $currentRun->workflow_instance_id === $childInstanceId
            && $currentRun->namespace === $childRun->namespace
                ? $currentRun
                : $childRun;
    }

    public static function isTerminal(WorkflowRun $run): bool
    {
        if ($run->status instanceof RunStatus) {
            return $run->status->isTerminal();
        }

        return in_array($run->status, ['completed', 'failed', 'cancelled', 'terminated'], true);
    }
}
