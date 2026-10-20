<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowSignal;

final class UnprojectedSignalDelivery
{
    /**
     * @param array<string, mixed>|null $contract
     */
    public static function allows(WorkflowRun $run, WorkflowSignal $signal, ?array $contract = null): bool
    {
        if (WorkflowExecutionGate::blockedReason($run)
            !== WorkflowExecutionGate::BLOCKED_WORKFLOW_DEFINITION_UNAVAILABLE) {
            return false;
        }
        $contract ??= RunCommandContract::forRun($run);
        if (($contract['source'] ?? null) === RunCommandContract::SOURCE_DURABLE_HISTORY) {
            $declaredSignals = $contract['signals'] ?? [];
            return is_array($declaredSignals) && in_array($signal->signal_name, $declaredSignals, true);
        }

        // An acceptance receipt cannot establish that workflow code has a
        // handler. Missing declarations remain unknown through terminal close.
        return false;
    }
}
