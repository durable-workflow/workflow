<?php

declare(strict_types=1);

namespace Workflow\V2\Compensation\Recovery;

use LogicException;
use Workflow\V2\Compensation\Definition\PortableDescriptor;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\Compensation\Security\ScopeAuthorization;
use Workflow\V2\Contracts\WorkflowControlPlane;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\StartOptions;
use Workflow\V2\Support\ConfiguredV2Models;
use Workflow\V2\Support\TypeRegistry;
use Workflow\V2\WorkflowStub;

/**
 * Routes retained work through native cancellation, start/adopt and redrive. It does not execute work.
 *
 * @experimental The Compensation API is not covered by stability guarantees and may change in a minor release.
 */
final readonly class ScopeRecovery
{
    public function __construct(
        private ScopeRepository $repository,
        private ScopeAuthorization $authorization,
        private WorkflowControlPlane $runtime,
    ) {
    }

    public function request(ScopeIdentity $scope, string $requestKey, string $principal, string $reason): array
    {
        $policyVersion = $this->authorization->assertAllowed($scope, $principal, 'request');
        PortableDescriptor::identifier($policyVersion, 'authorization policy version');
        $snapshot = $this->repository->inspect($scope);
        if ($snapshot['status'] === 'released') {
            throw new LogicException('The retained compensation scope has been released.');
        }
        $origin = ConfiguredV2Models::query('run_model', WorkflowRun::class)->find($snapshot['original_run_id']);
        if (! $origin instanceof WorkflowRun || $origin->namespace !== $scope->namespace) {
            throw new LogicException('The original compensation run is unavailable in the exact namespace.');
        }
        $this->repository->request($scope, $requestKey, $principal, $reason, $policyVersion);
        if ($snapshot['status'] === 'verified') {
            return [
                'route' => 'verified',
                'runId' => null,
            ];
        }
        if (! $origin->status->isTerminal()) {
            $command = WorkflowStub::loadRun($origin->id)->attemptRequestCancellation($reason);
            if ($command->accepted()) {
                return [
                    'route' => 'origin',
                    'runId' => $origin->id,
                    'commandId' => $command->commandId(),
                ];
            }
            // Closure can win after the gate transaction. Route that same retained request below.
            $origin->refresh();
            if (! $origin->status->isTerminal()) {
                throw new LogicException('The originating run refused cooperative compensation.');
            }
        }
        $run = ConfiguredV2Models::query('run_model', WorkflowRun::class)
            ->where('workflow_instance_id', $scope->recoveryInstanceId())
            ->where('namespace', $scope->namespace)
            ->orderByDesc('run_number')
            ->first();
        if (! $run instanceof WorkflowRun || in_array(
            $run->status,
            [RunStatus::Cancelled, RunStatus::Terminated],
            true
        )) {
            $result = WorkflowStub::make(RecoveryWorkflow::class, $scope->recoveryInstanceId(), $scope->namespace)
                ->attemptSignalWithStart('recover', [], StartOptions::returnExistingActive(), $scope->toArray());
            $runId = $result->runId();
            if ($result->accepted() && is_string($runId)) {
                return [
                    'route' => 'recovery',
                    'runId' => $runId,
                ];
            }
            $run = ConfiguredV2Models::query('run_model', WorkflowRun::class)
                ->where('workflow_instance_id', $scope->recoveryInstanceId())
                ->where('namespace', $scope->namespace)
                ->orderByDesc('run_number')
                ->first();
        }
        if (! $run instanceof WorkflowRun || $run->workflow_type !== TypeRegistry::for(RecoveryWorkflow::class)) {
            throw new LogicException('The canonical recovery identity is unavailable or reserved by another type.');
        }
        if (in_array($run->status, [RunStatus::Pending, RunStatus::Running, RunStatus::Waiting], true)) {
            return [
                'route' => 'recovery',
                'runId' => $run->id,
            ];
        }
        if ($this->repository->inspect($scope)['status'] === 'verified') {
            return [
                'route' => 'verified',
                'runId' => $run->id,
            ];
        }
        if ($run->status !== RunStatus::Failed) {
            throw new LogicException('The recovery is terminal without verified compensation.');
        }
        $redrive = $this->runtime->redrive($scope->recoveryInstanceId(), $run->id, [
            'namespace' => $scope->namespace,
            'request_id' => hash('sha256', PortableDescriptor::encode([$scope->id(), $requestKey, $run->id])),
        ]);
        if (($redrive['accepted'] ?? false) !== true) {
            throw new LogicException(
                'Native DW redrive refused unresolved recovery: ' . ($redrive['reason'] ?? 'unknown') . '.'
            );
        }
        return [
            'route' => 'recovery',
            'runId' => $redrive['workflow_run_id'],
        ];
    }
}
