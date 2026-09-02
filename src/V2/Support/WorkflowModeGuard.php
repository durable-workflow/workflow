<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Support\Facades\Log;
use Workflow\V2\Workflow;

/**
 * Boot-time guardrail that runs replay-safety diagnostics on registered
 * workflow classes and surfaces warnings before the first workflow task
 * ever executes.
 *
 * Configured via `workflows.v2.guardrails.boot`:
 *   - 'warn'   (default) — log warnings for detected non-deterministic patterns
 *   - 'silent' — skip boot-time scanning entirely
 *   - 'throw'  — throw on the first detected finding (CI-friendly)
 */
final class WorkflowModeGuard
{
    public static function check(): void
    {
        $mode = config('workflows.v2.guardrails.boot', 'warn');

        if ($mode === 'silent') {
            return;
        }

        /** @var array<string, class-string>|null $workflows */
        $workflows = config('workflows.v2.types.workflows');

        if (! is_array($workflows) || $workflows === []) {
            return;
        }

        foreach ($workflows as $typeKey => $workflowClass) {
            if (! is_string($workflowClass) || ! class_exists($workflowClass)) {
                continue;
            }

            if (! is_subclass_of($workflowClass, Workflow::class)) {
                continue;
            }

            $diagnostics = WorkflowDeterminismDiagnostics::forWorkflowClass($workflowClass);

            if ($diagnostics['status'] !== WorkflowDeterminismDiagnostics::STATUS_WARNING) {
                continue;
            }

            if ($mode === 'throw') {
                $finding = $diagnostics['findings'][0] ?? null;

                throw new \LogicException(sprintf(
                    'Workflow determinism guardrail failed for [%s] (%s): %s at %s:%d. '
                    . 'Set workflows.v2.guardrails.boot to "warn" or "silent" to downgrade this to a log warning.',
                    $workflowClass,
                    $typeKey,
                    $finding['message'] ?? 'unknown finding',
                    $finding['file'] ?? 'unknown',
                    $finding['line'] ?? 0,
                ));
            }

            foreach ($diagnostics['findings'] as $finding) {
                Log::warning(sprintf(
                    '[Durable Workflow] Replay-safety warning for [%s] (%s): %s [%s] at %s:%d',
                    $workflowClass,
                    $typeKey,
                    $finding['message'],
                    $finding['symbol'],
                    $finding['file'] ?? 'unknown',
                    $finding['line'] ?? 0,
                ));
            }
        }
    }
}
