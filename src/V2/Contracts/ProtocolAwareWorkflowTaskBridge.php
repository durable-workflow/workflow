<?php

declare(strict_types=1);

namespace Workflow\V2\Contracts;

/**
 * Optional external-worker completion role.
 *
 * Existing WorkflowTaskBridge implementations need not provide this role.
 * Hosts must check the bound bridge before advertising external worker
 * completion support.
 */
interface ProtocolAwareWorkflowTaskBridge extends WorkflowTaskBridge
{
    /**
     * Complete a workflow task under the authenticated worker protocol.
     *
     * @param list<array{type: string, ...}> $commands
     * @return array{
     *     completed: bool,
     *     task_id: string,
     *     workflow_run_id: string|null,
     *     run_status: string|null,
     *     created_task_ids: list<string>,
     *     reason: string|null,
     * }
     */
    public function completeForProtocol(string $taskId, array $commands, string $protocolVersion): array;
}
