<?php

declare(strict_types=1);

namespace Workflow\V2\Contracts;

use Workflow\V2\Models\WorkflowRun;

/**
 * @internal Optional scope admission role for the unfrozen 1.20 candidate.
 * This validates canonical membership. It does not advertise scope execution.
 */
interface CancellationScopeAdmission
{
    /**
     * Validate exact-run membership before resolving payloads or applying effects.
     * The caller has already validated scope field syntax and command types.
     * Recheck inside the admission transaction under the authoritative run lock.
     *
     * @param list<array{type: string, ...}> $commands
     * @return string|null The durable refusal reason, or null when valid.
     */
    public function validateCancellationScopeMembership(WorkflowRun $run, array $commands, int $sequence): ?string;
}
