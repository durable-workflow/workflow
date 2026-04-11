<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\WorkflowRun;

final class RepairBlockedReason
{
    /**
     * @return array<string, array{
     *     label: string,
     *     description: string,
     *     tone: string,
     *     badge_visible: bool
     * }>
     */
    public static function catalog(): array
    {
        return [
            'unsupported_history' => [
                'label' => 'Replay Blocked',
                'description' => 'Repair is blocked because only unsupported diagnostic history remains.',
                'tone' => 'dark',
                'badge_visible' => true,
            ],
            'waiting_for_compatible_worker' => [
                'label' => 'Compat Blocked',
                'description' => 'Repair is blocked because the task is waiting for a compatible worker.',
                'tone' => 'warning',
                'badge_visible' => true,
            ],
            'selected_run_not_current' => [
                'label' => 'Historical Run',
                'description' => 'Repair is blocked because the selected run is no longer current.',
                'tone' => 'secondary',
                'badge_visible' => false,
            ],
            'run_closed' => [
                'label' => 'Run Closed',
                'description' => 'Repair is blocked because the selected run is already closed.',
                'tone' => 'secondary',
                'badge_visible' => false,
            ],
            'repair_not_needed' => [
                'label' => 'Repair Not Needed',
                'description' => 'Repair is blocked because the run already has a healthy durable resume path.',
                'tone' => 'secondary',
                'badge_visible' => false,
            ],
        ];
    }

    /**
     * @return list<array{
     *     label: string,
     *     value: string,
     *     description: string,
     *     tone: string,
     *     badge_visible: bool
     * }>
     */
    public static function filterOptions(): array
    {
        $options = [];

        foreach (self::catalog() as $code => $metadata) {
            $options[] = [
                'label' => $metadata['label'],
                'value' => $code,
                'description' => $metadata['description'],
                'tone' => $metadata['tone'],
                'badge_visible' => $metadata['badge_visible'],
            ];
        }

        return $options;
    }

    /**
     * @return array{
     *     code: string,
     *     label: string,
     *     description: string,
     *     tone: string,
     *     badge_visible: bool
     * }|null
     */
    public static function metadata(?string $code): ?array
    {
        if ($code === null) {
            return null;
        }

        $metadata = self::catalog()[$code] ?? null;

        if ($metadata === null) {
            return null;
        }

        return [
            'code' => $code,
            'label' => $metadata['label'],
            'description' => $metadata['description'],
            'tone' => $metadata['tone'],
            'badge_visible' => $metadata['badge_visible'],
        ];
    }

    public static function forRun(
        WorkflowRun $run,
        bool $isCurrentRun,
        ?string $livenessState,
        bool $hasReplayBlockedTask,
    ): ?string {
        if (! $isCurrentRun) {
            return 'selected_run_not_current';
        }

        if (self::isClosed($run)) {
            return 'run_closed';
        }

        if ($livenessState === 'repair_needed') {
            return null;
        }

        if ($livenessState === 'workflow_replay_blocked') {
            return $hasReplayBlockedTask
                ? null
                : 'unsupported_history';
        }

        if (is_string($livenessState) && str_contains($livenessState, 'waiting_for_compatible_worker')) {
            return 'waiting_for_compatible_worker';
        }

        return 'repair_not_needed';
    }

    private static function isClosed(WorkflowRun $run): bool
    {
        return in_array($run->status, [
            RunStatus::Completed,
            RunStatus::Failed,
            RunStatus::Cancelled,
            RunStatus::Terminated,
        ], true);
    }
}
