<?php

declare(strict_types=1);

namespace Workflow\V2\Compensation\Security;

use Closure;
use Workflow\Exceptions\NonRetryableException;
use Workflow\V2\Activity;
use Workflow\V2\Compensation\Definition\PortableDescriptor;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\Compensation\Persistence\ScopeStorage;
use Workflow\V2\Compensation\Recovery\RecoveryWorkflow;
use Workflow\V2\Support\DefaultActivityTaskBridge;
use Workflow\V2\Support\SqliteTransaction;

/** @internal Retains native attempt locks until the ledger transaction commits; owns no new lease. */
final readonly class ScopeBoundary
{
    public function __construct(
        private ScopeAuthorization $authorization,
        private ScopeRepository $repository
    ) {
    }

    public function run(
        Activity $activity,
        ScopeIdentity $scope,
        string $operation,
        ?string $principal,
        Closure $body
    ): mixed {
        if ($activity->taskId === null || $activity->attemptId() === null || $activity->run->namespace !== $scope->namespace) {
            throw new NonRetryableException('scope_native_identity_mismatch');
        }
        return SqliteTransaction::forTask($activity->taskId, function () use (
            $activity,
            $scope,
            $operation,
            $principal,
            $body
        ): mixed {
            $this->assertNativeAuthority($activity);
            $snapshot = null;
            if ($operation !== 'register') {
                $snapshot = $this->repository->metadata($scope);
                $origin = $snapshot['original_run_id'] === $activity->runId();
                $recovery = $activity->workflowId() === $scope->recoveryInstanceId()
                    && $activity->run->workflow_class === RecoveryWorkflow::class;
                if ((! $origin && ! $recovery) || ($operation === 'forward' && ! $origin)) {
                    throw new NonRetryableException('scope_execution_owner_mismatch');
                }
                if ($principal === null) {
                    $principal = $this->repository->primaryRequest($scope)['principal'];
                }
            }
            if ($principal === null) {
                throw new NonRetryableException('scope_principal_missing');
            }
            try {
                $policyVersion = $this->authorization->assertAllowed($scope, $principal, $operation);
                PortableDescriptor::identifier($policyVersion, 'authorization policy version');
            } catch (\Throwable) {
                throw new NonRetryableException('scope_authorization_refused');
            }
            $snapshot = $operation === 'register' ? null : $this->repository->inspect($scope);
            $result = $body($snapshot, $policyVersion);
            // The callback can outlive its grant even while its SQL rows remain locked.
            $this->assertNativeAuthority($activity);
            ScopeStorage::append(ScopeStorage::locked($scope), 'authorized', [
                'principal' => $principal,
                'operation' => $operation,
                'policyVersion' => $policyVersion,
                'runId' => $activity->runId(),
                'activityId' => $activity->activityId(),
                'attemptId' => $activity->attemptId(),
            ]);
            return $result;
        });
    }

    private function assertNativeAuthority(Activity $activity): void
    {
        $status = app(DefaultActivityTaskBridge::class)->status($activity->attemptId());
        if (($status['can_continue'] ?? false) !== true
            || ($status['workflow_run_id'] ?? null) !== $activity->runId()
            || ($status['workflow_task_id'] ?? null) !== $activity->taskId
            || ($status['activity_execution_id'] ?? null) !== $activity->activityId()) {
            throw new NonRetryableException('scope_native_attempt_unavailable');
        }
    }
}
