<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use Illuminate\Contracts\Events\Dispatcher;
use LogicException;
use Workflow\V2\Compensation\Recovery\RecoveryWorkflow;
use Workflow\V2\Events\WorkflowCompleted;

/**
 * Recommended application pattern: commit the business cancellation once
 * compensation has verified, with no operator step.
 *
 * DW verifies retained compensation, including automatic recovery after the
 * Order closes (#1205). The business `cancelled` commit stays the
 * application's proof-only finalizer, CancellationStore::finish(). This
 * listener calls it when a canonical recovery run completes for the Order's
 * root scope. finish() refuses an unverified scope and is idempotent, so a
 * repeated or early call changes nothing.
 */
final readonly class CancellationFinalizer
{
    public function __construct(
        private Authority $authority,
        private CancellationStore $cancellations,
    ) {
    }

    public static function register(Dispatcher $events, Authority $authority, CancellationStore $cancellations): void
    {
        $finalizer = new self($authority, $cancellations);
        $events->listen(WorkflowCompleted::class, static function (WorkflowCompleted $event) use ($finalizer): void {
            if ($event->workflowClass === RecoveryWorkflow::class) {
                $finalizer->afterRecovery($event->instanceId);
            }
        });
    }

    /**
     * @return list<array{key: string, status: string}>
     */
    public function afterRecovery(string $recoveryInstanceId): array
    {
        $finished = [];
        $intents = $this->authority->db->table('provider_machine_cancellations')
            ->whereNotNull('request_key')
            ->orderBy('requested_at')
            ->orderBy('id')
            ->get(['root_key', 'root_run', 'request_key']);
        foreach ($intents as $intent) {
            if (isset($finished[$intent->root_key])) {
                continue;
            }
            try {
                [$assignment] = $this->authority->assignment($intent->root_key);
                if ($assignment->root_run !== $intent->root_run
                    || $this->authority->plan($intent->root_key)
                        ->rootScope()
                        ->recoveryInstanceId() !== $recoveryInstanceId
                    || $this->authority->subject($intent->root_key, 'order')
->state === 'cancelled') {
                    continue;
                }
            } catch (LogicException) {
                // Not a subject this host is granted, or its root never initialized.
                continue;
            }
            // The earliest business request names the finalizer, as an operator would.
            $finished[$intent->root_key] = $this->cancellations->finish(
                $intent->root_key,
                $intent->root_run,
                $intent->request_key,
            )['status'];
        }
        return array_map(
            static fn (string $key, string $status): array => [
                'key' => $key,
                'status' => $status,
            ],
            array_keys($finished),
            array_values($finished),
        );
    }
}
