<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use Closure;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\IncomingPaymentDescriptor;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\IncomingPaymentJournal;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\IncomingPaymentReceipt;
use Illuminate\Database\ConnectionInterface;
use LogicException;
use Workflow\V2\Compensation\Definition\PortableDescriptor;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\Models\WorkflowRun;

/** Recorded incoming facts; neither expiry nor an empty snapshot closes future payment admission. */
final readonly class IncomingPaymentInspectionStore
{
    /**
     * @param Closure(WorkflowRun, ScopeIdentity, IncomingPaymentDescriptor): void $authorize
     * The host must bind the original run, business grant, receivable and retained money duty before any SQL.
     */
    public function __construct(
        private ConnectionInterface $db,
        private IncomingPaymentJournal $provider,
        private Closure $authorize,
    ) {
    }

    public function inspect(WorkflowRun $actual, string $activityId, array $scopeValue, array $descriptorValue): array
    {
        if ($this->db->transactionLevel() !== 0) {
            throw new LogicException('Incoming inspection requires its own application transaction boundary.');
        }
        $scope = ScopeIdentity::fromArray($scopeValue);
        $descriptor = IncomingPaymentDescriptor::fromArray($descriptorValue);
        PortableDescriptor::identifier($activityId, 'original incoming inspection activity');
        if ($actual->namespace !== $scope->namespace) {
            throw new LogicException('The incoming inspection belongs to another native namespace.');
        }
        ($this->authorize)($actual, $scope, $descriptor);
        $ledger = (new ScopeRepository())->metadata($scope);
        if ($ledger['original_run_id'] !== $actual->id) {
            throw new LogicException('Incoming inspection requires the genuine original native scope owner.');
        }
        $id = hash('sha256', PortableDescriptor::encode(['incoming-inspection-v1', $actual->id, $activityId]));
        $requestHash = hash('sha256', PortableDescriptor::encode([$scope->toArray(), $descriptor->toArray()]));
        $existing = $this->db->table('provider_machine_assessments')
            ->where('id', $id)
            ->first();
        if ($existing !== null) {
            return $this->read($existing, $actual, $activityId, $scope, $descriptor, $id, $requestHash);
        }
        $payload = PortableDescriptor::encode([
            'snapshotId' => $id,
            'originalRunId' => $actual->id,
            'activityId' => $activityId,
            'scope' => $scope->toArray(),
            'retainedScopeStatus' => $ledger['status'],
            'incoming' => $this->provider->inspect($descriptor),
        ]);
        return $this->db->transaction(function () use (
            $id,
            $scope,
            $requestHash,
            $payload,
            $actual,
            $activityId,
            $descriptor
        ): array {
            $existing = $this->db->table('provider_machine_assessments')
                ->where('id', $id)
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                return $this->read($existing, $actual, $activityId, $scope, $descriptor, $id, $requestHash);
            }
            $this->db->table('provider_machine_assessments')
                ->insert([
                    'id' => $id,
                    'actor_id' => $scope->id(),
                    'request_sha256' => $requestHash,
                    'payload' => $payload,
                    'payload_sha256' => hash('sha256', $payload),
                ]);
            return json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        });
    }

    private function read(
        object $row,
        WorkflowRun $actual,
        string $activityId,
        ScopeIdentity $scope,
        IncomingPaymentDescriptor $descriptor,
        string $id,
        string $requestHash,
    ): array {
        if ($row->actor_id !== $scope->id() || ! hash_equals($row->request_sha256, $requestHash)
            || strlen($row->payload) > PortableDescriptor::MAX_BYTES
            || ! hash_equals($row->payload_sha256, hash('sha256', $row->payload))) {
            throw new LogicException('The original incoming inspection identity or recorded contents changed.');
        }
        $snapshot = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($snapshot) || ($snapshot['snapshotId'] ?? null) !== $id
            || ($snapshot['originalRunId'] ?? null) !== $actual->id || ($snapshot['activityId'] ?? null) !== $activityId
            || ! is_array($snapshot['scope'] ?? null)
            || ScopeIdentity::fromArray($snapshot['scope'])->toArray() !== $scope->toArray()
            || ! is_array($snapshot['incoming']['original'] ?? null)
            || IncomingPaymentDescriptor::fromArray($snapshot['incoming']['original'])->fingerprint()
                !== $descriptor->fingerprint()) {
            throw new LogicException(
                'The recorded incoming snapshot substituted its original business or native owner.'
            );
        }
        $this->assertIncomingFacts($snapshot['incoming'], $descriptor);
        return $snapshot;
    }

    private function assertIncomingFacts(array $incoming, IncomingPaymentDescriptor $descriptor): void
    {
        $observedAt = $incoming['observedAt'] ?? null;
        $receipts = $incoming['receipts'] ?? null;
        if (! is_int($observedAt) || $observedAt < 0 || ! is_array($receipts)
            || ! array_is_list($receipts) || count($receipts) > 64) {
            throw new LogicException('The recorded incoming snapshot has incomplete receipt evidence.');
        }
        $total = 0;
        $onTime = 0;
        $seen = [];
        foreach ($receipts as $value) {
            if (! is_array($value)) {
                throw new LogicException('The recorded incoming receipt has an invalid shape.');
            }
            $receipt = IncomingPaymentReceipt::fromArray($value);
            if ($receipt->receivable !== $descriptor->identity->key() || $receipt->currency !== $descriptor->currency
                || $receipt->receivedAt > $observedAt || isset($seen[$receipt->transfer])
                || $receipt->amountMinor > PHP_INT_MAX - $total) {
                throw new LogicException('The recorded incoming snapshot substituted its original receipt evidence.');
            }
            $seen[$receipt->transfer] = true;
            $total += $receipt->amountMinor;
            if ($receipt->receivedAt < $descriptor->deadline) {
                $onTime += $receipt->amountMinor;
            }
        }
        foreach ([
            'deadlinePassed' => $observedAt >= $descriptor->deadline,
            'receivedAmountMinor' => $total,
            'receivedBeforeDeadlineMinor' => $onTime,
            'fullAmountReceived' => $total >= $descriptor->amountMinor,
            'requiredAmountReceivedBeforeDeadline' => $onTime >= $descriptor->amountMinor,
            'remainingAmountMinor' => max(0, $descriptor->amountMinor - $total),
            'overpaymentAmountMinor' => max(0, $total - $descriptor->amountMinor),
            'nonExecutionEstablished' => false,
        ] as $key => $expected) {
            if (($incoming[$key] ?? null) !== $expected) {
                throw new LogicException('The recorded incoming totals or deadline contradict their receipt evidence.');
            }
        }
    }
}
