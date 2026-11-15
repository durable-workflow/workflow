<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use DurableWorkflow\Examples\BusinessStateMachines\SqlBusinessStore;
use LogicException;
use Workflow\V2\Compensation\Definition\PortableDescriptor;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\Compensation\Recovery\ScopeRecovery;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\StateMachine\Execution\TransitionStatus;
use Workflow\V2\StateMachine\Transition\SnapshotPhase;
use Workflow\V2\StateMachine\Transition\TransitionContext;

/** A business intent fences every protected writer before native recovery is requested. */
final readonly class CancellationStore
{
    public function __construct(
        private Authority $authority
    ) {
    }

    public function request(string $key, string $rootRun, string $requestKey): array
    {
        $this->authority->authorize($key, $this->authority->principal);
        PortableDescriptor::identifier($requestKey, 'original cancellation request');
        [$assignment] = $this->authority->assignment($key);
        $root = $this->authority->subject($key, 'order');
        $actual = WorkflowRun::query()->where('namespace', Catalogue::NAMESPACE)
            ->where('workflow_class', Order::class)->where(
                'workflow_instance_id',
                'provider-order-' . $key
            )->findOrFail($rootRun);
        $instance = WorkflowInstance::query()->findOrFail($actual->workflow_instance_id);
        if ($actual->workflow_class !== Order::class || $actual->namespace !== Catalogue::NAMESPACE
            || $actual->workflowArguments() !== [$key] || $actual->workflow_instance_id !== 'provider-order-' . $key
            || (int) $instance->run_count !== 1 || $instance->current_run_id !== $rootRun
            || ($assignment->root_run !== null && $assignment->root_run !== $rootRun)) {
            throw new LogicException(
                'Cancellation cannot move the original business generation to another native owner.'
            );
        }
        $id = hash('sha256', PortableDescriptor::encode([$key, $rootRun, $requestKey]));
        $hash = hash('sha256', PortableDescriptor::encode([$key, $rootRun, $requestKey, $this->authority->principal]));
        $this->authority->db->transaction(function () use ($key, $rootRun, $root, $id, $hash): void {
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
        });
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
                $requestKey,
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
        $id = SqlBusinessStore::decisionId($execution, 'transition', $execution->executionId);
        $hash = hash(
            'sha256',
            PortableDescriptor::encode([
                $execution->toArray(),
                $context->snapshot->snapshotId,
                $context->snapshot->target->toArray(),
            ])
        );
        return $this->authority->db->transaction(function () use ($execution, $row, $plan, $proof, $id, $hash): array {
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
            $this->authority->db->table('example_machine_publications')
                ->insert([
                    'id' => $id,
                    'subject_id' => $row->id,
                    'payload' => PortableDescriptor::encode([
                        'execution' => $execution->toArray(),
                        'transitionKey' => 'cancel',
                        'state' => 'cancelled',
                        'revision' => (string) $revision,
                        'originalRunId' => $plan->rootRun,
                        'scope' => $plan->rootScope()
                            ->toArray(),
                    ]),
                ]);
            return [
                'execution' => $execution->toArray(),
                'status' => TransitionStatus::Committed->value,
                'reason' => 'verified_business_cancellation_committed',
            ];
        });
    }
}
