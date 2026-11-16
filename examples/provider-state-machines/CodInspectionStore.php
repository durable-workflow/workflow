<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use Closure;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\CodDescriptor;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\CodJournal;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\CodPaymentOperation;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\CodPaymentProvider;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\CodReceipt;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\CodSnapshot;
use Illuminate\Database\ConnectionInterface;
use LogicException;
use Workflow\V2\Compensation\Definition\PortableDescriptor;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\Models\WorkflowRun;

/** Recorded original COD and physical-order facts; merchant remittance remains distinct from payer collection. */
final readonly class CodInspectionStore
{
    /**
     * @param Closure(WorkflowRun, ScopeIdentity, CodDescriptor, string): void $authorize
     * The host binds the original run, business grant, carrier contract and retained money duties before SQL.
     */
    public function __construct(
        private ConnectionInterface $db,
        private CodPaymentProvider $provider,
        private Closure $authorize,
    ) {
    }

    public function inspect(WorkflowRun $actual, string $activityId, array $scopeValue, array $descriptorValue): array
    {
        if ($this->db->transactionLevel() !== 0) {
            throw new LogicException('COD inspection requires its own application transaction boundary.');
        }
        $scope = ScopeIdentity::fromArray($scopeValue);
        [$descriptor, $contract] = CodPaymentOperation::originalInput($descriptorValue);
        PortableDescriptor::identifier($activityId, 'original cod inspection activity');
        if ($actual->namespace !== $scope->namespace) {
            throw new LogicException('The cod inspection belongs to another native namespace.');
        }
        ($this->authorize)($actual, $scope, $descriptor, $contract);
        $ledger = (new ScopeRepository())->metadata($scope);
        if ($ledger['original_run_id'] !== $actual->id) {
            throw new LogicException('COD inspection requires the genuine original native scope owner.');
        }
        $id = hash('sha256', PortableDescriptor::encode(['cod-inspection-v1', $actual->id, $activityId]));
        $requestHash = hash('sha256', PortableDescriptor::encode([$scope->toArray(), $descriptorValue]));
        $existing = $this->db->table('provider_machine_assessments')
            ->where('id', $id)
            ->first();
        if ($existing !== null) {
            return $this->read($existing, $actual, $activityId, $scope, $descriptor, $contract, $requestHash);
        }
        $cod = $this->provider->inspect($descriptor);
        $this->assertCodFacts($cod, $descriptor, $contract);
        $payload = PortableDescriptor::encode([
            'snapshotId' => $id,
            'originalRunId' => $actual->id,
            'activityId' => $activityId,
            'scope' => $scope->toArray(),
            'retainedScopeStatus' => $ledger['status'],
            'cod' => $cod,
        ]);
        return $this->db->transaction(function () use (
            $id,
            $scope,
            $requestHash,
            $payload,
            $actual,
            $activityId,
            $descriptor,
            $contract
        ): array {
            $existing = $this->db->table('provider_machine_assessments')
                ->where('id', $id)
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                return $this->read($existing, $actual, $activityId, $scope, $descriptor, $contract, $requestHash);
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
        CodDescriptor $descriptor,
        string $contract,
        string $requestHash,
    ): array {
        $id = hash('sha256', PortableDescriptor::encode(['cod-inspection-v1', $actual->id, $activityId]));
        if ($row->actor_id !== $scope->id() || ! hash_equals($row->request_sha256, $requestHash)
            || strlen($row->payload) > PortableDescriptor::MAX_BYTES
            || ! hash_equals($row->payload_sha256, hash('sha256', $row->payload))) {
            throw new LogicException('The original cod inspection identity or recorded contents changed.');
        }
        $snapshot = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($snapshot) || ($snapshot['snapshotId'] ?? null) !== $id
            || ($snapshot['originalRunId'] ?? null) !== $actual->id || ($snapshot['activityId'] ?? null) !== $activityId
            || ! is_array($snapshot['scope'] ?? null)
            || ScopeIdentity::fromArray($snapshot['scope'])->toArray() !== $scope->toArray()
            || ! is_array($snapshot['cod']['original'] ?? null)
            || CodDescriptor::fromArray($snapshot['cod']['original'])->fingerprint()
                !== $descriptor->fingerprint()) {
            throw new LogicException(
                'The recorded cod snapshot substituted its original business or native owner.'
            );
        }
        $this->assertCodFacts($snapshot['cod'], $descriptor, $contract);
        return $snapshot;
    }

    private function assertCodFacts(array $cod, CodDescriptor $descriptor, string $contract): void
    {
        $observedAt = $cod['observedAt'] ?? null;
        $receipts = $cod['receipts'] ?? null;
        if (! is_int($observedAt) || $observedAt < 0 || ! is_array($receipts)
            || ! array_is_list($receipts) || count($receipts) > 128
            || ($cod['admissionContract'] ?? null) !== $contract
            || ! is_bool($cod['collectionAdmissionClosed'] ?? null)
            || ! array_key_exists('closedAt', $cod)
            || ($cod['collectionAdmissionClosed'] ? (! is_int($cod['closedAt']) || $cod['closedAt'] < 0
                || $cod['closedAt'] > $observedAt || $contract !== CodJournal::CLOSABLE) : $cod['closedAt'] !== null)) {
            throw new LogicException('The recorded COD inventory or pinned collection contract is incomplete.');
        }
        $typed = array_map(static function ($value): CodReceipt {
            if (! is_array($value)) {
                throw new LogicException('The recorded COD receipt has an invalid shape.');
            }
            return CodReceipt::fromArray($value);
        }, $receipts);
        $pure = (new CodSnapshot($descriptor, $observedAt, $typed))->toArray();
        if (PortableDescriptor::encode(array_intersect_key($cod, $pure)) !== PortableDescriptor::encode($pure)) {
            throw new LogicException('Recorded COD facts contradict their original complete receipt inventory.');
        }
        $carrier = $cod['carrierContract'] ?? null;
        if (! is_array($carrier) || ($carrier['originalFingerprint'] ?? null) !== $descriptor->fingerprint()
            || ($carrier['handoverPolicy'] ?? null) !== $descriptor->handoverPolicy
            || ! is_array($carrier['collections'] ?? null) || ! array_is_list($carrier['collections'])) {
            throw new LogicException('Recorded COD facts lost their original physical carrier contract.');
        }
        $expected = [];
        foreach ($typed as $receipt) {
            if ($receipt->kind === CodReceipt::COLLECTION) {
                $expected[] = [
                    'receipt' => $receipt->receipt,
                    'sha256' => hash('sha256', PortableDescriptor::encode($receipt->toArray())),
                    'phase' => $descriptor->handoverPolicy,
                ];
            }
        }
        if (PortableDescriptor::encode($carrier['collections']) !== PortableDescriptor::encode($expected)) {
            throw new LogicException('The recorded COD carrier order proof substituted an original payer collection.');
        }
    }
}
