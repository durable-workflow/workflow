<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\BusinessStateMachines;

use Closure;
use Illuminate\Database\ConnectionInterface;
use LogicException;
use Workflow\V2\Compensation\Execution\ForwardResult;
use Workflow\V2\Compensation\Execution\OperationContext;
use Workflow\V2\Compensation\Execution\Resolution;
use Workflow\V2\Compensation\Execution\ScopedOperation;
use Workflow\V2\StateMachine\Execution\ExecutionReference;

/**
 * A local SQL service used by the runnable example, not an adapter for a remote provider.
 * Every writer shares the subject lock; a permanent receipt fences later execution of the same operation.
 */
final readonly class SqlEffectOperation implements ScopedOperation
{
    /**
     * @param Closure(OperationContext, string): array{principal: string, version: string} $authorize
     */
    public function __construct(
        private ConnectionInterface $db,
        private string $action,
        private Closure $authorize,
    ) {
        if (! in_array($action, ['reserve', 'prepare', 'import', 'deliver'], true)) {
            throw new LogicException('The example action is unavailable.');
        }
    }

    public function assertInput(array $input): void
    {
        $keys = array_keys($input);
        sort($keys);
        if ($keys !== ['action', 'execution'] || $input['action'] !== $this->action
            || ! is_array($input['execution'])) {
            throw new LogicException('The SQL action input was substituted.');
        }
        $this->execution($input['execution']);
    }

    public function execute(OperationContext $context): ForwardResult
    {
        $grant = $this->authorize($context, 'forward');
        return $this->db->transaction(function () use ($context, $grant): ForwardResult {
            $subject = $this->subject($context, $grant);
            $receipt = $this->receipt($context);
            if ($receipt === null) {
                $status = (bool) $subject->cancellation_requested ? 'absent' : 'active';
                $this->insert($context, $subject->id, $status);
            } else {
                // Reconcile an earlier committed effect before considering a reissue, including reconcileBeforeForward.
                $status = $receipt->status;
            }
            return ForwardResult::observed([
                'operationId' => $context->operationId,
                'action' => $this->action,
                'status' => $status,
            ], [$this->evidence($context, $status)]);
        });
    }

    public function compensate(OperationContext $context): Resolution
    {
        $grant = $this->authorize($context, 'compensate');
        return $this->db->transaction(function () use ($context, $grant): Resolution {
            $subject = $this->subject($context, $grant);
            if (! (bool) $subject->cancellation_requested) {
                return Resolution::unknown('business_cancellation_required');
            }
            $receipt = $this->receipt($context);
            if ($receipt?->status === 'dispatched') {
                // The carrier holds the parcel. Voiding it now cannot be verified, so native
                // retries run and the obligation stays unresolved until the parcel returns.
                return Resolution::unknown('effect_dispatched');
            }
            if ($receipt === null) {
                // This is committed non-admission, not a transient lookup-absence inference.
                $status = 'absent';
                $this->insert($context, $subject->id, $status);
            } elseif ($receipt->status === 'active') {
                $status = 'voided';
                $this->db->table('example_machine_effects')
                    ->where('id', $context->operationId)
                    ->update([
                        'status' => $status,
                    ]);
            } else {
                $status = $receipt->status;
            }
            if (! $this->db->table('example_machine_compensation_log')->where(
                'operation_id',
                $context->operationId
            )->exists()) {
                $this->db->table('example_machine_compensation_log')
                    ->insert([
                        'operation_id' => $context->operationId,
                        'action' => $this->action,
                    ]);
            }
            // All service writes are synchronous SQL under this same fence. No external callback stop is inferred.
            return Resolution::verified([$this->evidence($context, $status)]);
        });
    }

    private function execution(array $value): ExecutionReference
    {
        $keys = array_keys($value);
        sort($keys);
        if ($keys !== ['executionId', 'policyFingerprint', 'scope', 'sourceRevision', 'sourceState', 'subject']) {
            throw new LogicException('The SQL action execution identity is malformed.');
        }
        foreach ($value as $part) {
            if (! is_string($part)) {
                throw new LogicException('The SQL action identity requires string fields.');
            }
        }
        return new ExecutionReference(
            $value['scope'],
            $value['subject'],
            $value['executionId'],
            $value['policyFingerprint'],
            $value['sourceState'],
            $value['sourceRevision'],
        );
    }

    private function authorize(OperationContext $context, string $operation): array
    {
        if ($this->db->transactionLevel() !== 0) {
            throw new LogicException('SQL service authorization must precede its owned transaction.');
        }
        $this->assertInput($context->obligation->input);
        $grant = ($this->authorize)($context, $operation);
        $keys = array_keys($grant);
        sort($keys);
        if ($this->db->transactionLevel() !== 0 || $keys !== ['principal', 'version']
            || ! is_string($grant['principal']) || trim($grant['principal']) === ''
            || ! is_string($grant['version']) || trim($grant['version']) === '') {
            throw new LogicException('The application must authorize the original operation owner.');
        }
        return $grant;
    }

    private function subject(OperationContext $context, array $grant): object
    {
        $execution = $this->execution($context->obligation->input['execution']);
        $row = $this->db->table('example_machine_subjects')
            ->where('id', SqlBusinessStore::subjectId($execution))->lockForUpdate()->first();
        if ($row === null || $row->scope !== $execution->scope || $row->subject !== $execution->subject
            || $row->principal !== $grant['principal'] || $row->authority_version !== $grant['version']) {
            throw new LogicException('The SQL service subject authority changed.');
        }
        return $row;
    }

    private function receipt(OperationContext $context): ?object
    {
        $row = $this->db->table('example_machine_effects')
            ->where('id', $context->operationId)
            ->first();
        if ($row !== null && ($row->owner_sha256 !== $this->ownerFingerprint($context)
            || $row->subject_id !== SqlBusinessStore::subjectId(
                $this->execution($context->obligation->input['execution'])
            )
            || $row->action !== $this->action
            || ! in_array($row->status, ['active', 'voided', 'absent', 'dispatched'], true))) {
            throw new LogicException('The original SQL effect owner or status was substituted.');
        }
        return $row;
    }

    private function insert(OperationContext $context, string $subject, string $status): void
    {
        $this->db->table('example_machine_effects')
            ->insert([
                'id' => $context->operationId,
                'subject_id' => $subject,
                'owner_sha256' => $this->ownerFingerprint($context),
                'action' => $this->action,
                'status' => $status,
            ]);
    }

    private function ownerFingerprint(OperationContext $context): string
    {
        return hash('sha256', json_encode([
            $context->scope->toArray(), $context->originalRunId, $context->obligation->toArray(),
        ], JSON_THROW_ON_ERROR));
    }

    private function evidence(OperationContext $context, string $status): string
    {
        return 'sql-effect:' . $context->operationId . ':' . $status;
    }
}
