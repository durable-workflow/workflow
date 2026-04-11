<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;

final class RunTaskLinkMap
{
    /**
     * @param list<array<string, mixed>> $tasks
     * @return array{
     *     commands: array<string, array{
     *         current_task_id: string|null,
     *         current_task_status: string|null,
     *         task_transport_state: string|null,
     *         task_ids: list<string>,
     *         task_missing: bool
     *     }>,
     *     signals: array<string, array{
     *         current_task_id: string|null,
     *         current_task_status: string|null,
     *         task_transport_state: string|null,
     *         task_ids: list<string>,
     *         task_missing: bool
     *     }>,
     *     updates: array<string, array{
     *         current_task_id: string|null,
     *         current_task_status: string|null,
     *         task_transport_state: string|null,
     *         task_ids: list<string>,
     *         task_missing: bool
     *     }>
     * }
     */
    public static function forRun(WorkflowRun $run, array $tasks): array
    {
        $run->loadMissing('historyEvents');

        $commandLinks = [];
        $signalLinks = [];
        $updateLinks = [];

        foreach ($tasks as $task) {
            if (! is_array($task)) {
                continue;
            }

            self::recordTaskLinkFromTask(
                $commandLinks,
                self::stringValue($task['workflow_command_id'] ?? null),
                $task,
            );
            self::recordTaskLinkFromTask(
                $signalLinks,
                self::stringValue($task['workflow_signal_id'] ?? null),
                $task,
            );
            self::recordTaskLinkFromTask(
                $updateLinks,
                self::stringValue($task['workflow_update_id'] ?? null),
                $task,
            );
        }

        foreach ($run->historyEvents->sortBy('sequence') as $event) {
            if (! $event instanceof WorkflowHistoryEvent) {
                continue;
            }

            $taskId = self::stringValue($event->workflow_task_id);

            if ($taskId === null) {
                continue;
            }

            self::recordTaskLinkFromHistory($commandLinks, self::commandIdForEvent($event), $taskId);
            self::recordTaskLinkFromHistory($signalLinks, self::signalIdForEvent($event), $taskId);
            self::recordTaskLinkFromHistory($updateLinks, self::updateIdForEvent($event), $taskId);
        }

        return [
            'commands' => self::finalize($commandLinks),
            'signals' => self::finalize($signalLinks),
            'updates' => self::finalize($updateLinks),
        ];
    }

    /**
     * @param array<string, array{
     *     task_ids: array<string, true>,
     *     current_task_id: string|null,
     *     current_task_status: string|null,
     *     task_transport_state: string|null,
     *     task_missing: bool
     * }> $links
     * @param array<string, mixed> $task
     */
    private static function recordTaskLinkFromTask(array &$links, ?string $key, array $task): void
    {
        if ($key === null) {
            return;
        }

        self::initialize($links, $key);

        $taskId = self::stringValue($task['id'] ?? null);
        $taskMissing = ($task['task_missing'] ?? false) === true || ($task['synthetic'] ?? false) === true;

        if ($taskMissing) {
            $links[$key]['task_missing'] = true;
            $links[$key]['task_transport_state'] ??= self::stringValue($task['transport_state'] ?? null) ?? 'missing';

            return;
        }

        if ($taskId === null) {
            return;
        }

        $links[$key]['task_ids'][$taskId] = true;

        if (($task['is_open'] ?? false) === true && $links[$key]['current_task_id'] === null) {
            $links[$key]['current_task_id'] = $taskId;
            $links[$key]['current_task_status'] = self::stringValue($task['status'] ?? null);
            $links[$key]['task_transport_state'] = self::stringValue($task['transport_state'] ?? null);
        }
    }

    /**
     * @param array<string, array{
     *     task_ids: array<string, true>,
     *     current_task_id: string|null,
     *     current_task_status: string|null,
     *     task_transport_state: string|null,
     *     task_missing: bool
     * }> $links
     */
    private static function recordTaskLinkFromHistory(array &$links, ?string $key, string $taskId): void
    {
        if ($key === null) {
            return;
        }

        self::initialize($links, $key);
        $links[$key]['task_ids'][$taskId] = true;
    }

    /**
     * @param array<string, array{
     *     task_ids: array<string, true>,
     *     current_task_id: string|null,
     *     current_task_status: string|null,
     *     task_transport_state: string|null,
     *     task_missing: bool
     * }> $links
     */
    private static function initialize(array &$links, string $key): void
    {
        if (isset($links[$key])) {
            return;
        }

        $links[$key] = [
            'task_ids' => [],
            'current_task_id' => null,
            'current_task_status' => null,
            'task_transport_state' => null,
            'task_missing' => false,
        ];
    }

    /**
     * @param array<string, array{
     *     task_ids: array<string, true>,
     *     current_task_id: string|null,
     *     current_task_status: string|null,
     *     task_transport_state: string|null,
     *     task_missing: bool
     * }> $links
     * @return array<string, array{
     *     current_task_id: string|null,
     *     current_task_status: string|null,
     *     task_transport_state: string|null,
     *     task_ids: list<string>,
     *     task_missing: bool
     * }>
     */
    private static function finalize(array $links): array
    {
        $finalized = [];

        foreach ($links as $key => $link) {
            $finalized[$key] = [
                'current_task_id' => $link['current_task_id'],
                'current_task_status' => $link['current_task_status'],
                'task_transport_state' => $link['task_transport_state'] ?? ($link['task_missing'] ? 'missing' : null),
                'task_ids' => array_keys($link['task_ids']),
                'task_missing' => $link['task_missing'],
            ];
        }

        return $finalized;
    }

    private static function commandIdForEvent(WorkflowHistoryEvent $event): ?string
    {
        return self::stringValue($event->workflow_command_id)
            ?? self::stringValue($event->payload['workflow_command_id'] ?? null);
    }

    private static function signalIdForEvent(WorkflowHistoryEvent $event): ?string
    {
        return self::stringValue($event->payload['signal_id'] ?? null)
            ?? self::stringValue($event->payload['workflow_signal_id'] ?? null);
    }

    private static function updateIdForEvent(WorkflowHistoryEvent $event): ?string
    {
        return self::stringValue($event->payload['update_id'] ?? null)
            ?? self::stringValue($event->payload['workflow_update_id'] ?? null);
    }

    private static function stringValue(mixed $value): ?string
    {
        return is_string($value) && $value !== ''
            ? $value
            : null;
    }
}
