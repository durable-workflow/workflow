<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

final class WorkflowTaskProblem
{
    /**
     * @return array{
     *     code: string,
     *     label: string,
     *     description: string,
     *     tone: string,
     *     badge_visible: bool
     * }|null
     */
    public static function metadata(bool $taskProblem, ?string $livenessState, ?string $waitKind = null): ?array
    {
        $code = self::code($taskProblem, $livenessState, $waitKind);

        if ($code === null) {
            return null;
        }

        $metadata = self::catalog()[$code];

        return [
            'code' => $code,
            'label' => $metadata['label'],
            'description' => $metadata['description'],
            'tone' => $metadata['tone'],
            'badge_visible' => $metadata['badge_visible'],
        ];
    }

    /**
     * @return array<string, array{
     *     label: string,
     *     description: string,
     *     tone: string,
     *     badge_visible: bool
     * }>
     */
    private static function catalog(): array
    {
        return [
            'replay_blocked' => [
                'label' => 'Task Failure',
                'description' => 'The selected run has a workflow-task replay failure or unsupported history.',
                'tone' => 'dark',
                'badge_visible' => true,
            ],
            'active' => [
                'label' => 'Task Problem',
                'description' => 'The selected run currently has missing, retried, or transport-unhealthy workflow-task work.',
                'tone' => 'warning',
                'badge_visible' => true,
            ],
            'history' => [
                'label' => 'Task History',
                'description' => 'The selected run previously needed workflow-task repair or replay recovery.',
                'tone' => 'secondary',
                'badge_visible' => true,
            ],
        ];
    }

    private static function code(bool $taskProblem, ?string $livenessState, ?string $waitKind): ?string
    {
        if (! $taskProblem) {
            return null;
        }

        if ($livenessState === 'workflow_replay_blocked') {
            return 'replay_blocked';
        }

        if (self::active($livenessState, $waitKind)) {
            return 'active';
        }

        return 'history';
    }

    private static function active(?string $livenessState, ?string $waitKind): bool
    {
        if ($livenessState === null) {
            return false;
        }

        if ($livenessState === 'repair_needed') {
            return in_array($waitKind, ['workflow-task', 'update', 'signal', 'child', 'condition'], true)
                || $waitKind === null;
        }

        return str_contains($livenessState, 'task_claim_failed')
            || str_contains($livenessState, 'waiting_for_compatible_worker');
    }
}
