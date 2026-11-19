<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\BusinessStateMachines;

use Closure;
use Illuminate\Database\ConnectionInterface;
use LogicException;
use Workflow\V2\StateMachine\Execution\ExecutionReference;
use Workflow\V2\StateMachine\Execution\TransitionStatus;
use Workflow\V2\StateMachine\Persistence\CommitResult;
use Workflow\V2\StateMachine\Transition\SnapshotPhase;
use Workflow\V2\StateMachine\Transition\TransitionContext;

/**
 * Application-owned SQL boundary, called from a recorded activity, never workflow code.
 * This example owns no DW tables, provider requests, queue, or publication dispatcher.
 */
final readonly class SqlBusinessStore
{
    /**
     * The trusted host authenticates the principal and authorizes this subject/operation.
     * For a commit it must also validate the complete transition against the pinned profile
     * and the assessment against authoritative immutable facts; caller-supplied "satisfied"
     * is not evidence. It returns the authority version still required under the row lock.
     *
     * @param Closure(ExecutionReference, string, string, ?TransitionContext): string $authorize
     */
    public function __construct(
        private ConnectionInterface $db,
        private Closure $authorize
    ) {
    }

    public function commit(TransitionContext $context, string $principal): CommitResult
    {
        $execution = $context->execution();
        $authority = $this->authorize($execution, $principal, 'commit', $context);
        if (! in_array($context->snapshot->phase, [SnapshotPhase::Before, SnapshotPhase::After], true)
            || $context->snapshot->transitionKey !== $context->transition->key
            || ! in_array($execution->sourceState, $context->transition->from, true)
            || $context->snapshot->state !== $execution->sourceState
            || $context->snapshot->revision !== $execution->sourceRevision
            || ($context->snapshot->machineAssessment !== null && ! $context->snapshot->machineAssessment->decision->satisfied())
            || ! $context->snapshot->target->satisfied()) {
            throw new LogicException('The protected commit requires the matching satisfied assessment.');
        }
        $request = [
            'execution' => $execution->toArray(),
            'transition' => get_object_vars($context->transition),
            'snapshot' => [
                'id' => $context->snapshot->snapshotId,
                'phase' => $context->snapshot->phase->value,
                'target' => $context->snapshot->target->toArray(),
                'permission' => $context->snapshot->permission->toArray(),
            ],
        ];
        if ($context->snapshot->machineAssessment !== null) {
            $request['machineAssessment'] = $context->snapshot->machineAssessment->toArray();
        }
        $id = self::decisionId($execution, 'transition', $execution->executionId);
        return $this->decide($execution, $principal, $authority, $id, $request, false);
    }

    /**
     * Accepted business cancellation closes forward commits; it does not verify compensation.
     */
    public function requestCancellation(
        ExecutionReference $execution,
        string $requestId,
        string $principal,
    ): CommitResult {
        $authority = $this->authorize($execution, $principal, 'cancel');
        if (trim($requestId) === '') {
            throw new LogicException('Cancellation requires an immutable request identity.');
        }
        return $this->decide(
            $execution,
            $principal,
            $authority,
            self::decisionId($execution, 'cancellation', $requestId),
            [
                'execution' => $execution->toArray(),
                'requestId' => $requestId,
            ],
            true,
        );
    }

    public static function subjectId(ExecutionReference $execution): string
    {
        return hash('sha256', json_encode([$execution->scope, $execution->subject], JSON_THROW_ON_ERROR));
    }

    public static function decisionId(ExecutionReference $execution, string $kind, string $key): string
    {
        // A decision ID cannot be reused for another subject inside the same authorized scope.
        return hash('sha256', json_encode([$execution->scope, $kind, $key], JSON_THROW_ON_ERROR));
    }

    private function authorize(
        ExecutionReference $execution,
        string $principal,
        string $operation,
        ?TransitionContext $context = null,
    ): string {
        if ($this->db->transactionLevel() !== 0) {
            throw new LogicException('The business boundary must own its transaction.');
        }
        $authority = ($this->authorize)($execution, $principal, $operation, $context);
        if ($this->db->transactionLevel() !== 0 || trim($authority) === '' || strlen($authority) > 128) {
            throw new LogicException('Host authorization must return a bounded version outside SQL locks.');
        }
        return $authority;
    }

    /**
     * @param array<string, mixed> $request
     */
    private function decide(
        ExecutionReference $execution,
        string $principal,
        string $authority,
        string $id,
        array $request,
        bool $cancellation,
    ): CommitResult {
        $fingerprint = hash('sha256', json_encode($request, JSON_THROW_ON_ERROR));
        return $this->db->transaction(function () use (
            $execution,
            $principal,
            $authority,
            $id,
            $request,
            $fingerprint,
            $cancellation,
        ): CommitResult {
            // Cancellation, authority revocation and completion must all serialize on this row.
            $subject = $this->db->table('example_machine_subjects')
                ->where('id', self::subjectId($execution))->lockForUpdate()->first();
            if ($subject === null || $subject->scope !== $execution->scope
                || $subject->subject !== $execution->subject || $subject->principal !== $principal
                || $subject->authority_version !== $authority) {
                throw new LogicException('The original subject authority is not current.');
            }
            $existing = $this->db->table('example_machine_decisions')
                ->where('id', $id)
                ->first();
            if ($existing !== null) {
                if ($existing->subject_id !== $subject->id || $existing->request_sha256 !== $fingerprint) {
                    throw new LogicException('The decision identity was reused with different content.');
                }
                // Lost acknowledgement: return the original decision even after later cancellation/state changes.
                return new CommitResult($execution, TransitionStatus::from($existing->status), $existing->reason);
            }
            if ((bool) $subject->cancellation_requested) {
                $status = TransitionStatus::CancellationRequested;
                $reason = 'business_cancellation_requested';
            } elseif ($subject->state !== $execution->sourceState
                || (string) $subject->revision !== $execution->sourceRevision) {
                $status = TransitionStatus::Conflict;
                $reason = 'source_changed';
            } elseif ($cancellation) {
                $this->db->table('example_machine_subjects')
                    ->where('id', $subject->id)
                    ->update([
                        'cancellation_requested' => true,
                    ]);
                $status = TransitionStatus::CancellationRequested;
                $reason = 'business_cancellation_requested';
            } else {
                if ((int) $subject->revision >= PHP_INT_MAX) {
                    throw new LogicException('The subject revision is exhausted.');
                }
                $revision = (int) $subject->revision + 1;
                $this->db->table('example_machine_subjects')
                    ->where('id', $subject->id)
                    ->update([
                        'state' => $request['transition']['to'],
                        'revision' => $revision,
                    ]);
                $this->db->table('example_machine_publications')
                    ->insert([
                        'id' => $id,
                        'subject_id' => $subject->id,
                        'payload' => json_encode([
                            'execution' => $execution->toArray(),
                            'transitionKey' => $request['transition']['key'],
                            'state' => $request['transition']['to'],
                            'revision' => (string) $revision,
                        ], JSON_THROW_ON_ERROR),
                    ]);
                $status = TransitionStatus::Committed;
                $reason = 'business_state_committed';
            }
            $this->db->table('example_machine_decisions')
                ->insert([
                    'id' => $id,
                    'subject_id' => $subject->id,
                    'request_sha256' => $fingerprint,
                    'status' => $status->value,
                    'reason' => $reason,
                ]);
            return new CommitResult($execution, $status, $reason);
        });
    }
}
