<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use DurableWorkflow\Examples\BusinessStateMachines\SqlBusinessStore;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Request;
use LogicException;
use Workflow\V2\Compensation\Definition\PortableDescriptor;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\Compensation\Recovery\ScopeRecovery;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\StartOptions;
use Workflow\V2\StateMachine\Execution\TransitionStatus;
use Workflow\V2\StateMachine\Transition\SnapshotPhase;
use Workflow\V2\StateMachine\Transition\TransitionContext;
use Workflow\V2\Support\CurrentRunResolver;
use Workflow\V2\WorkflowStub;

/** A business intent fences every protected writer before native recovery is requested. */
final readonly class CancellationStore
{
    public function __construct(
        private Authority $authority
    ) {
    }

    public function request(string $key, string $rootRun, string $requestKey, ?string $recoveryRequestKey = null): array
    {
        $this->authority->authorize($key, $this->authority->principal);
        PortableDescriptor::identifier($requestKey, 'original cancellation request');
        if ($recoveryRequestKey !== null) {
            PortableDescriptor::identifier($recoveryRequestKey, 'original compensation recovery request');
        }
        [$assignment] = $this->authority->assignment($key);
        $root = $this->authority->subject($key, 'order');
        $this->assertOriginalRoot($key, $rootRun, $assignment);
        $id = hash('sha256', PortableDescriptor::encode([$key, $rootRun, $requestKey]));
        $hash = hash('sha256', PortableDescriptor::encode([$key, $rootRun, $requestKey, $this->authority->principal]));
        $refused = $this->authority->db->transaction(function () use (
            $key,
            $rootRun,
            $root,
            $id,
            $hash,
            $requestKey
        ): ?string {
            $current = $this->authority->db->table('example_machine_subjects')
                ->where('id', $root->id)
                ->lockForUpdate()
                ->sole();
            $this->authority->assertGrant($current, $key, 'order');
            $assignment = $this->authority->db->table('provider_machine_assignments')
                ->where('id', $key)
                ->sole();
            if ($assignment->root_run !== null && $assignment->root_run !== $rootRun) {
                throw new LogicException('The original cancellation owner changed.');
            }
            if ($this->authority->transit->selected($key)) {
                // Serialized with incident admission on the Order lock; the incident owns the customer remedy.
                $remedy = IncidentStore::remedyStage($this->authority->db, $key);
                if ($remedy !== null) {
                    return $remedy;
                }
            }
            $existing = $this->authority->db->table('provider_machine_cancellations')
                ->where('id', $id)
                ->first();
            if ($existing !== null && ($existing->root_key !== $key || $existing->root_run !== $rootRun || $existing->request_sha256 !== $hash)) {
                throw new LogicException('The original cancellation request changed content.');
            }
            if ($existing === null) {
                $this->authority->db->table('provider_machine_cancellations')
                    ->insert([
                        'id' => $id,
                        'root_key' => $key,
                        'root_run' => $rootRun,
                        'request_sha256' => $hash,
                        'request_key' => $requestKey,
                        'requested_at' => now(),
                    ]);
            }
            // Child initialization locks the root first. Existing child commits and this fence serialize on
            // the same child subject rows; newly initialized children inherit the committed root fence.
            $ids = [
                Authority::subjectId($key, 'order'),
                Authority::subjectId($key, 'payment'),
                Authority::subjectId($key, 'shipment'),
            ];
            $this->authority->db->table('example_machine_subjects')
                ->whereIn('id', $ids)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $this->authority->db->table('example_machine_subjects')
                ->whereIn('id', $ids)
                ->update([
                    'cancellation_requested' => true,
                ]);
            return null;
        });
        if ($refused !== null) {
            // Explicit refusal: no intent, fence or compensation request is recorded, so nothing can wedge.
            return [
                'status' => 'refused',
                'reason' => 'transit_incident_owns_remedy',
                'remedy' => $refused,
                'requestId' => $id,
            ];
        }
        if ($assignment->root_run === null) {
            // A genuine recorded owner registration may still arrive. Never open a ledger from this lookup.
            return [
                'status' => 'unresolved',
                'reason' => 'original_scope_not_initialized',
                'requestId' => $id,
            ];
        }
        try {
            $route = app(ScopeRecovery::class)->request(
                $this->authority->plan($key)
                    ->rootScope(),
                $recoveryRequestKey ?? $requestKey,
                $this->authority->principal,
                'business-cancellation'
            );
        } catch (LogicException $error) {
            if ($error->getMessage() !== 'The exact compensation scope generation is unavailable.') {
                throw $error;
            }
            return [
                'status' => 'unresolved',
                'reason' => 'original_scope_not_initialized',
                'requestId' => $id,
            ];
        }
        return [
            'status' => 'requested',
            'requestId' => $id,
            'route' => $route,
        ];
    }

    public function commit(TransitionContext $context, object $row, BindingPlan $plan): array
    {
        $execution = $context->execution();
        if (! $context->snapshot->target->satisfied() || $context->transition->key !== 'cancel'
            || $context->transition->hasWork || $context->transition->to !== 'cancelled'
            || ! in_array($context->snapshot->phase, [SnapshotPhase::Before, SnapshotPhase::After], true)
            || $context->snapshot->state !== $execution->sourceState || $context->snapshot->revision !== $execution->sourceRevision) {
            throw new LogicException('Cancellation requires its original satisfied proof-only assessment.');
        }
        $proof = (new ScopeRepository())->metadata($plan->rootScope());
        $stockOperation = null;
        if ($plan->recipe !== Catalogue::RESERVE_FIRST) {
            $obligation = $this->authority->cart->stockObligation($plan->terms->subjectKey());
            $stock = Request::fromArray($obligation->input['forward']);
            $stockOperation = $stock->identity->key();
        }
        $id = SqlBusinessStore::decisionId($execution, 'transition', $execution->executionId);
        $hash = hash(
            'sha256',
            PortableDescriptor::encode([
                $execution->toArray(),
                $context->snapshot->snapshotId,
                $context->snapshot->target->toArray(),
            ])
        );
        return $this->authority->db->transaction(function () use (
            $execution,
            $row,
            $plan,
            $proof,
            $id,
            $hash,
            $stockOperation
        ): array {
            $current = $this->authority->db->table('example_machine_subjects')
                ->where('id', $row->id)
                ->lockForUpdate()
                ->sole();
            $this->authority->assertGrant($current, $plan->terms->subjectKey(), 'order');
            $previous = $this->authority->db->table('example_machine_decisions')
                ->where('id', $id)
                ->first();
            if ($previous !== null) {
                if ($previous->subject_id !== $row->id || $previous->request_sha256 !== $hash) {
                    throw new LogicException('The original cancellation decision changed proof.');
                }
                return [
                    'execution' => $execution->toArray(),
                    'status' => $previous->status,
                    'reason' => $previous->reason,
                ];
            }
            if ($proof['status'] !== 'verified' || $proof['original_run_id'] !== $plan->rootRun
                || ! (bool) $current->cancellation_requested || $current->state !== $execution->sourceState
                || (string) $current->revision !== $execution->sourceRevision || (int) $current->revision >= PHP_INT_MAX) {
                throw new LogicException('Original compensation or the protected cancellation revision is unresolved.');
            }
            $revision = (int) $current->revision + 1;
            $this->authority->db->table('example_machine_subjects')
                ->where('id', $row->id)
                ->update([
                    'state' => 'cancelled',
                    'revision' => $revision,
                ]);
            $this->authority->db->table('example_machine_decisions')
                ->insert([
                    'id' => $id,
                    'subject_id' => $row->id,
                    'request_sha256' => $hash,
                    'status' => TransitionStatus::Committed->value,
                    'reason' => 'verified_business_cancellation_committed',
                ]);
            $publication = $this->publication($execution, $revision, $plan, $stockOperation);
            $this->authority->db->table('example_machine_publications')
                ->insert([
                    'id' => $id,
                    'subject_id' => $row->id,
                    'payload' => PortableDescriptor::encode($publication),
                ]);
            return [
                'execution' => $execution->toArray(),
                'status' => TransitionStatus::Committed->value,
                'reason' => 'verified_business_cancellation_committed',
            ];
        });
    }

    /**
     * A closed original run keeps ownership of recovery; this new workflow can only publish its verified result.
     */
    public function finish(string $key, string $rootRun, string $requestKey): array
    {
        $this->intent($key, $rootRun, $requestKey);
        $proof = (new ScopeRepository())->metadata($this->authority->plan($key)->rootScope());
        if ($proof['original_run_id'] !== $rootRun || $proof['status'] !== 'verified') {
            return [
                'status' => 'pending',
                'reason' => 'original-compensation-unverified',
            ];
        }
        $instanceId = self::instance($key, $rootRun, $requestKey);
        $instance = WorkflowInstance::query()->where('namespace', Catalogue::NAMESPACE)->find($instanceId);
        $run = $instance === null ? null : CurrentRunResolver::forInstance($instance);
        if ($run === null) {
            $stub = WorkflowStub::make(Cancellation::class, $instanceId, Catalogue::NAMESPACE);
            $stub->start(StartOptions::returnExistingActive(), $key, $rootRun, $requestKey);
            return [
                'status' => 'started',
                'runId' => $stub->runId(),
            ];
        }
        $this->authority->actor($run);
        return [
            'status' => $run->status->value,
            'runId' => $run->id,
        ];
    }

    public function intent(string $key, string $rootRun, string $requestKey): object
    {
        $this->authority->authorize($key, $this->authority->principal);
        PortableDescriptor::identifier($requestKey, 'original cancellation request');
        [$assignment] = $this->authority->assignment($key);
        $root = $this->authority->subject($key, 'order');
        $id = hash('sha256', PortableDescriptor::encode([$key, $rootRun, $requestKey]));
        $hash = hash('sha256', PortableDescriptor::encode([$key, $rootRun, $requestKey, $this->authority->principal]));
        $intent = $this->authority->db->table('provider_machine_cancellations')
            ->where('root_key', $key)
            ->where('id', $id)
            ->sole();
        if ($assignment->root_run !== $rootRun || ! (bool) $root->cancellation_requested
            || $intent->root_run !== $rootRun || $intent->request_sha256 !== $hash) {
            throw new LogicException('The original business cancellation is not authorized.');
        }
        return $intent;
    }

    public static function instance(string $key, string $rootRun, string $requestKey): string
    {
        return 'provider-cancel-' . hash('sha256', PortableDescriptor::encode([$key, $rootRun, $requestKey]));
    }

    private function publication(
        \Workflow\V2\StateMachine\Execution\ExecutionReference $execution,
        int $revision,
        BindingPlan $plan,
        ?string $stockOperation
    ): array {
        $publication = [
            'execution' => $execution->toArray(),
            'transitionKey' => 'cancel',
            'state' => 'cancelled',
            'revision' => (string) $revision,
            'originalRunId' => $plan->rootRun,
            'scope' => $plan->rootScope()
                ->toArray(),
        ];
        if ($plan->recipe !== Catalogue::RESERVE_FIRST) {
            $assignment = $this->authority->db->table('provider_machine_assignments')
                ->where('id', $plan->terms->subjectKey())
                ->sole();

            $receipt = [
                'originalRunId' => $plan->rootRun,
                'operation' => $stockOperation,
                'status' => 'refused',
                'admissionClosed' => true,
            ];
            if ($assignment->root_run !== $plan->rootRun || ($assignment->stock_refusal !== null
                && $assignment->stock_refusal !== PortableDescriptor::encode($receipt))) {
                throw new LogicException('The protected cancellation has a substituted stock refusal.');
            }
            $publication['stockRefusal'] = $assignment->stock_refusal === null ? null : $receipt;
        }
        return $publication;
    }

    private function assertOriginalRoot(string $key, string $rootRun, object $assignment): void
    {
        $reservation = $this->authority->roots->selected($key)
            ? $this->authority->roots->reservationFor($key, $rootRun) : null;
        $class = $reservation?->identity->workflowClass ?? $this->authority->cart->rootClass($key);
        $instanceId = $reservation?->identity->instanceId() ?? 'provider-order-' . $key;
        $actual = WorkflowRun::query()->where('namespace', Catalogue::NAMESPACE)
            ->where('workflow_class', $class)
            ->where('workflow_instance_id', $instanceId)
            ->findOrFail($rootRun);
        $instance = WorkflowInstance::query()->findOrFail($actual->workflow_instance_id);
        if ($actual->workflow_class !== $class || $actual->namespace !== Catalogue::NAMESPACE
            || $actual->workflowArguments() !== [$key] || $actual->workflow_instance_id !== $instanceId
            || (int) $instance->run_count !== 1 || $instance->current_run_id !== $rootRun
            || ($assignment->root_run !== null && $assignment->root_run !== $rootRun)) {
            throw new LogicException(
                'Cancellation cannot move the original business generation to another native owner.'
            );
        }
    }
}
