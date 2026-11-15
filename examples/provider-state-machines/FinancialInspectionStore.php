<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use Closure;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\FinancialDescriptor;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Journal;
use Illuminate\Database\ConnectionInterface;
use LogicException;
use Workflow\V2\Compensation\Definition\PortableDescriptor;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\Models\WorkflowRun;

/** A recorded inspection never grants forward authority or proves that a missing operation did not execute. */
final readonly class FinancialInspectionStore
{
    /**
     * @param Closure(WorkflowRun, ScopeIdentity, FinancialDescriptor): void $authorize
     * The host must verify the original actor, business grant, graph and paired native obligations before any provider SQL.
     */
    public function __construct(
        private ConnectionInterface $db,
        private Journal $provider,
        private Closure $authorize,
    ) {
    }

    public function inspect(WorkflowRun $actual, string $activityId, array $scopeValue, array $descriptorValue): array
    {
        if ($this->db->transactionLevel() !== 0) {
            throw new LogicException('Financial inspection requires its own application transaction boundary.');
        }
        $scope = ScopeIdentity::fromArray($scopeValue);
        $descriptor = FinancialDescriptor::fromArray($descriptorValue);
        PortableDescriptor::identifier($activityId, 'original financial inspection activity');
        if ($actual->namespace !== $scope->namespace) {
            throw new LogicException('The financial inspection belongs to another native namespace.');
        }
        ($this->authorize)($actual, $scope, $descriptor);
        $ledger = (new ScopeRepository())->metadata($scope);
        if ($ledger['original_run_id'] !== $actual->id) {
            throw new LogicException('Financial inspection requires the genuine original native scope owner.');
        }
        // Native activity identity stays fixed when a worker loses its completion acknowledgement.
        $id = hash('sha256', PortableDescriptor::encode(['financial-inspection-v1', $actual->id, $activityId]));
        $request = PortableDescriptor::encode([$scope->toArray(), $descriptor->toArray()]);
        $hash = hash('sha256', $request);
        $existing = $this->db->table('provider_machine_assessments')
            ->where('id', $id)
            ->first();
        if ($existing !== null) {
            return $this->read($existing, $scope, $hash);
        }
        $snapshot = [
            'snapshotId' => $id,
            'originalRunId' => $actual->id,
            'activityId' => $activityId,
            'scope' => $scope->toArray(),
            'retainedScopeStatus' => $ledger['status'],
            'financial' => $this->provider->inspectFinancial($descriptor)
                ->toArray(),
        ];
        $payload = PortableDescriptor::encode($snapshot);
        return $this->db->transaction(function () use ($id, $scope, $hash, $payload): array {
            $existing = $this->db->table('provider_machine_assessments')
                ->where('id', $id)
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                return $this->read($existing, $scope, $hash);
            }
            $this->db->table('provider_machine_assessments')
                ->insert([
                    'id' => $id,
                    'actor_id' => $scope->id(),
                    'request_sha256' => $hash,
                    'payload' => $payload,
                    'payload_sha256' => hash('sha256', $payload),
                ]);
            return json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        });
    }

    private function read(object $row, ScopeIdentity $scope, string $requestHash): array
    {
        if ($row->actor_id !== $scope->id() || ! hash_equals($row->request_sha256, $requestHash)
            || ! hash_equals($row->payload_sha256, hash('sha256', $row->payload))) {
            throw new LogicException('The original financial inspection identity or recorded contents changed.');
        }
        return json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
    }
}
