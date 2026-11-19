<?php

declare(strict_types=1);

namespace Workflow\V2\Compensation\Recovery;

use LogicException;
use Workflow\V2\Compensation\Definition\PortableDescriptor;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\Compensation\Security\ScopeAuthorization;
use Workflow\V2\Contracts\WorkflowControlPlane;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\StartOptions;
use Workflow\V2\Support\ConfiguredV2Models;
use Workflow\V2\Support\CurrentRunResolver;
use Workflow\V2\Support\TypeRegistry;
use Workflow\V2\Support\WorkflowFiberContext;
use Workflow\V2\WorkflowStub;

/**
 * Routes retained work through native cancellation, start/adopt and redrive. It does not execute work.
 *
 * @api Stable compensation-scope API from 2.32.0 (#1063). See docs/api-stability.md#compensation-scopes.
 */
final readonly class ScopeRecovery
{
    /**
     * @internal Resolve this service from the container, for example `app(ScopeRecovery::class)`.
     */
    public function __construct(
        private ScopeRepository $repository,
        private ScopeAuthorization $authorization,
        private WorkflowControlPlane $runtime,
    ) {
    }

    /**
     * Accept an authorized compensation request and route it.
     *
     * Every result has exactly these keys:
     * - route: a RecoveryRoute value (`origin`, `recovery` or `verified`);
     * - runId: the origin or recovery run ID, or null for `verified`;
     * - commandId: the accepted cancellation or start command ID, or null;
     * - recoveryStatus: the recovery run's status (`pending`, `running`, `waiting`, `completed`, `failed`,
     *   `cancelled` or `terminated`), or null unless route is `recovery`;
     * - scopeStatus: a ScopeStatus value;
     * - nextAction: a NextAction value.
     *
     * Repeating a request key returns its original route and never starts another run.
     *
     * @return array{route: string, runId: ?string, commandId: ?string, recoveryStatus: ?string, scopeStatus: string, nextAction: string}
     */
    public function request(
        ScopeIdentity $scope,
        string $requestKey,
        string $principal,
        string $reason,
        ?StartOptions $profile = null,
    ): array {
        $result = $this->route($scope, $requestKey, $principal, $reason, $profile);
        if ($result['route'] !== RecoveryRoute::Verified->value) {
            // An authorized request is a reason to retry now rather than after the long wait.
            CompensationWake::scope($scope);
        }
        return $result;
    }

    /**
     * Read-only operator view of a scope. Requires `inspect` authorization.
     *
     * Every result has exactly these keys:
     * - scopeStatus: a ScopeStatus value;
     * - originStatus and recoveryStatus: native run statuses (`pending`, `running`, `waiting`, `completed`,
     *   `failed`, `cancelled`, `terminated`), or null when that run does not exist;
     * - recoveryRunId: the current canonical recovery run ID, or null;
     * - automaticRecovery: an AutomaticRecovery value;
     * - refusal: null, or `['code' => 'recovery_authorization_failed', 'message' => ?string]`. The code is
     *   stable; the message is the host's diagnostic text and is not machine-stable;
     * - nextAction: a NextAction value;
     * - unresolved: a list of `['key' => string, 'status' => ObligationStatus value, 'reason' => ?string]`
     *   in registration order. The reason is the handler's outcome reason.
     *
     * @return array{scopeStatus: string, originStatus: ?string, recoveryRunId: ?string, recoveryStatus: ?string, automaticRecovery: string, refusal: ?array{code: string, message: ?string}, nextAction: string, unresolved: list<array{key: string, status: string, reason: ?string}>}
     */
    public function describe(ScopeIdentity $scope, string $principal): array
    {
        $this->assertHostContext();
        $policyVersion = $this->authorization->assertAllowed($scope, $principal, 'inspect');
        PortableDescriptor::identifier($policyVersion, 'authorization policy version');
        $ledger = $this->repository->inspect($scope);
        $scopeStatus = self::scopeStatus($ledger['status']);
        $origin = ConfiguredV2Models::query('run_model', WorkflowRun::class)
            ->where('namespace', $scope->namespace)
            ->find($ledger['original_run_id']);
        $instance = ConfiguredV2Models::query('instance_model', WorkflowInstance::class)
            ->where('namespace', $scope->namespace)
            ->find($scope->recoveryInstanceId());
        $recovery = $instance instanceof WorkflowInstance ? CurrentRunResolver::forInstance($instance) : null;
        $database = ConfiguredV2Models::connection();
        $routed = $database->table('workflow_compensation_requests')
            ->where('scope_id', $scope->id())
            ->whereNotNull('recovery_route')
            ->exists();
        $refusal = null;
        foreach ($this->repository->journal($scope) as $entry) {
            if ($entry['kind'] === CompensationAutoRecovery::REFUSED) {
                $data = json_decode($entry['data'], true, flags: JSON_THROW_ON_ERROR);
                $message = $data['message'] ?? $data['reason'] ?? null;
                $refusal = [
                    'code' => AutomaticRecovery::RecoveryAuthorizationFailed->value,
                    'message' => is_string($message) ? $message : null,
                ];
            }
        }
        $settled = in_array($scopeStatus, [ScopeStatus::Verified, ScopeStatus::Released], true);
        $originClosed = $origin instanceof WorkflowRun && $origin->status->isTerminal();
        $automatic = match (true) {
            $routed => AutomaticRecovery::Routed,
            $settled => AutomaticRecovery::NotNeeded,
            $refusal !== null => AutomaticRecovery::RecoveryAuthorizationFailed,
            $scopeStatus === ScopeStatus::Requested && $originClosed => AutomaticRecovery::Pending,
            default => AutomaticRecovery::NotNeeded,
        };
        $nextAction = match (true) {
            $settled => NextAction::None,
            $recovery instanceof WorkflowRun && ! $recovery->status->isTerminal() => NextAction::ObserveRecovery,
            $recovery instanceof WorkflowRun && $recovery->status === RunStatus::Completed
                => NextAction::InspectUnresolvedObligations,
            $recovery instanceof WorkflowRun => NextAction::SubmitFreshAuthorizedRequest,
            $automatic === AutomaticRecovery::RecoveryAuthorizationFailed => NextAction::SubmitFreshAuthorizedRequest,
            $automatic === AutomaticRecovery::Pending => NextAction::AwaitAutomaticRecovery,
            $scopeStatus === ScopeStatus::Requested => NextAction::ObserveOrigin,
            default => NextAction::None,
        };
        $unresolved = [];
        foreach ($ledger['obligations'] as $obligation) {
            $status = self::obligationStatus($obligation['compensation_status']);
            if ($status === ObligationStatus::Verified) {
                continue;
            }
            $evidence = $obligation['evidence'] === null ? null
                : json_decode($obligation['evidence'], true, flags: JSON_THROW_ON_ERROR);
            $reason = $evidence['reason'] ?? null;
            $unresolved[] = [
                'key' => $obligation['descriptor']['key'],
                'status' => $status->value,
                'reason' => is_string($reason) ? $reason : null,
            ];
        }
        return [
            'scopeStatus' => $scopeStatus->value,
            'originStatus' => $origin instanceof WorkflowRun ? $origin->status->value : null,
            'recoveryRunId' => $recovery?->id,
            'recoveryStatus' => $recovery?->status->value,
            'automaticRecovery' => $automatic->value,
            'refusal' => $refusal,
            'nextAction' => $nextAction->value,
            'unresolved' => $unresolved,
        ];
    }

    /**
     * Maps a retained scope status to its public value; reserved roots are not opened scopes.
     */
    private static function scopeStatus(string $stored): ScopeStatus
    {
        return ScopeStatus::tryFrom($stored)
            ?? throw new LogicException('The compensation scope has not been opened.');
    }

    /**
     * Maps a retained compensation status to its public value.
     */
    private static function obligationStatus(string $stored): ObligationStatus
    {
        return match ($stored) {
            'verified' => ObligationStatus::Verified,
            'failed' => ObligationStatus::Failed,
            'unknown' => ObligationStatus::Unresolved,
            default => ObligationStatus::Pending,
        };
    }

    private function route(
        ScopeIdentity $scope,
        string $requestKey,
        string $principal,
        string $reason,
        ?StartOptions $profile,
    ): array {
        $this->assertHostContext();
        $policyVersion = $this->authorization->assertAllowed($scope, $principal, 'request');
        $this->assertHostContext();
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
        $profile ??= StartOptions::returnExistingActive();
        $profileFingerprint = self::profileFingerprint($profile);
        $boundRoute = $this->repository->recoveryRoute($scope, $requestKey, $profileFingerprint);
        if ($boundRoute === null && $snapshot['status'] === 'verified') {
            return [
                'route' => RecoveryRoute::Verified->value,
                'runId' => null,
                'commandId' => null,
                'recoveryStatus' => null,
                'scopeStatus' => ScopeStatus::Verified->value,
                'nextAction' => NextAction::None->value,
            ];
        }
        if ($boundRoute === null && ! $origin->status->isTerminal()) {
            $command = WorkflowStub::loadRun($origin->id)->attemptRequestCancellation($reason);
            if ($command->accepted()) {
                return [
                    'route' => RecoveryRoute::Origin->value,
                    'runId' => $origin->id,
                    'commandId' => $command->commandId(),
                    'recoveryStatus' => null,
                    'scopeStatus' => self::scopeStatus($this->repository->metadata($scope)['status'])->value,
                    'nextAction' => NextAction::ObserveOrigin->value,
                ];
            }
            // Closure can win after the gate transaction. Route that same retained request below.
            $origin->refresh();
            if (! $origin->status->isTerminal()) {
                throw new LogicException('The originating run refused cooperative compensation.');
            }
        }
        $route = $boundRoute ?? $this->repository->recoveryRoute(
            $scope,
            $requestKey,
            $profileFingerprint,
            fn (): array => $this->selectRecoveryRoute($scope, $profile),
        );
        if ($route === null) {
            throw new LogicException('The retained recovery route was not bound.');
        }
        $admission = new RecoveryRunAdmission(
            $scope,
            $origin->id,
            $requestKey,
            $route['predecessorRunId'],
            $profile,
        );
        if ($route['kind'] === 'start') {
            $result = WorkflowStub::make(RecoveryWorkflow::class, $scope->recoveryInstanceId(), $scope->namespace)
                ->attemptRecoveryStart($admission);
            if (! $result->accepted() || ! is_string($result->runId())) {
                throw new LogicException('Native recovery admission refused the retained request.');
            }
            return $this->recoveryResult($scope, $result->runId(), $route['predecessorRunId'], $result->commandId());
        }
        $redrive = $this->runtime->redrive($scope->recoveryInstanceId(), $route['predecessorRunId'], [
            'namespace' => $scope->namespace,
            'request_id' => $admission->token(),
        ]);
        if (($redrive['accepted'] ?? false) !== true || ! is_string($redrive['workflow_run_id'] ?? null)) {
            throw new LogicException(
                'Native DW redrive refused unresolved recovery: ' . ($redrive['reason'] ?? 'unknown') . '.'
            );
        }
        return $this->recoveryResult($scope, $redrive['workflow_run_id'], $route['predecessorRunId']);
    }

    private function recoveryResult(
        ScopeIdentity $scope,
        string $runId,
        ?string $expectedPredecessor,
        ?string $commandId = null,
    ): array {
        $run = ConfiguredV2Models::query('run_model', WorkflowRun::class)
            ->where('namespace', $scope->namespace)
            ->find($runId);
        $instance = ConfiguredV2Models::query('instance_model', WorkflowInstance::class)
            ->where('namespace', $scope->namespace)
            ->find($scope->recoveryInstanceId());
        if (! $run instanceof WorkflowRun || ! $instance instanceof WorkflowInstance
            || $run->workflow_instance_id !== $instance->id
            || $instance->workflow_class !== RecoveryWorkflow::class
            || $run->workflow_class !== RecoveryWorkflow::class
            || $instance->workflow_type !== TypeRegistry::for(RecoveryWorkflow::class)
            || $run->workflow_type !== $instance->workflow_type
            || PortableDescriptor::encode($run->workflowArguments())
                !== PortableDescriptor::encode([$scope->toArray()])) {
            throw new LogicException('Native recovery result does not belong to the exact canonical scope.');
        }
        $snapshot = $this->repository->metadata($scope);
        $receipt = RecoveryRunAdmission::sourceReceipt($instance, $run);
        if ($receipt === null
            || ($receipt['intent']['expected_predecessor_run_id'] ?? null) !== $expectedPredecessor
            || ($receipt['intent']['binding']['original_run_id'] ?? null) !== $snapshot['original_run_id']
            || PortableDescriptor::encode($receipt['intent']['binding']['scope'] ?? [])
                !== PortableDescriptor::encode($scope->toArray())) {
            throw new LogicException('Native recovery result does not match the retained predecessor and owner.');
        }
        $scopeStatus = self::scopeStatus($snapshot['status']);
        $nextAction = $scopeStatus === ScopeStatus::Verified ? NextAction::None : match ($run->status) {
            RunStatus::Failed, RunStatus::Cancelled, RunStatus::Terminated => NextAction::SubmitFreshAuthorizedRequest,
            RunStatus::Completed => NextAction::InspectUnresolvedObligations,
            default => NextAction::ObserveRecovery,
        };
        return [
            'route' => RecoveryRoute::Recovery->value,
            'runId' => $runId,
            'commandId' => $commandId,
            'recoveryStatus' => $run->status->value,
            'scopeStatus' => $scopeStatus->value,
            'nextAction' => $nextAction->value,
        ];
    }

    private function selectRecoveryRoute(ScopeIdentity $scope, StartOptions $profile): array
    {
        $instance = ConfiguredV2Models::query('instance_model', WorkflowInstance::class)
            ->where('namespace', $scope->namespace)
            ->find($scope->recoveryInstanceId());
        $run = $instance instanceof WorkflowInstance ? CurrentRunResolver::forInstance($instance) : null;
        if ($instance !== null && $instance->workflow_type !== TypeRegistry::for(RecoveryWorkflow::class)) {
            throw new LogicException('The canonical recovery identity is reserved by another type.');
        }
        $predecessor = $run?->id;
        $intent = null;
        if ($run !== null) {
            $started = $run->historyEvents()
                ->where('event_type', HistoryEventType::WorkflowStarted->value)->sole();
            $intent = $started->payload['compensation_recovery']['intent'] ?? null;
            if (! is_array($intent) || ($intent['version'] ?? null) !== 1
                || ! array_key_exists('expected_predecessor_run_id', $intent)
                || ! is_array($intent['binding']['profile'] ?? null)) {
                throw new LogicException('Recovery has no retained native predecessor and profile binding.');
            }
            $expected = self::profileDescriptor($profile);
            $recorded = [
                'version' => 1,
                ...array_intersect_key($intent['binding']['profile'], $expected),
            ];
            if (PortableDescriptor::encode($expected) !== PortableDescriptor::encode($recorded)) {
                throw new LogicException('Recovery requests must retain the initial recovery profile.');
            }
        }
        if ($run !== null && ! $run->status->isTerminal()) {
            $predecessor = $intent['expected_predecessor_run_id'];
        } elseif ($run !== null && ! in_array(
            $run->status,
            [RunStatus::Failed, RunStatus::Cancelled, RunStatus::Terminated],
            true,
        )) {
            throw new LogicException('The recovery is terminal without verified compensation.');
        }
        return [
            'kind' => $run?->status === RunStatus::Failed ? 'redrive' : 'start',
            'predecessorRunId' => $predecessor,
        ];
    }

    private function assertHostContext(): void
    {
        if (WorkflowFiberContext::active() || ConfiguredV2Models::connection()->transactionLevel() !== 0) {
            throw new LogicException(
                'Recovery authorization requires an unlocked host context outside workflow replay.'
            );
        }
    }

    private static function profileFingerprint(StartOptions $profile): string
    {
        return hash('sha256', PortableDescriptor::encode(self::profileDescriptor($profile)));
    }

    private static function profileDescriptor(StartOptions $profile): array
    {
        return [
            'version' => 1,
            'business_key' => $profile->businessKey,
            'labels' => $profile->labels,
            'memo' => $profile->memo,
            'search_attributes' => $profile->searchAttributes,
            'execution_timeout_seconds' => $profile->executionTimeoutSeconds,
            'run_timeout_seconds' => $profile->runTimeoutSeconds,
            'timeout_cleanup_seconds' => $profile->timeoutCleanupSeconds,
            'priority' => $profile->priority,
            'fairness_key' => $profile->fairnessKey,
            'fairness_weight' => $profile->fairnessWeight,
        ];
    }
}
