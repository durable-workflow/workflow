<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use DurableWorkflow\Examples\BusinessStateMachines\SqlBusinessStore;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Journal;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Request;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Status;
use LogicException;
use Workflow\V2\Compensation\Definition\PortableDescriptor;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\StateMachine\Execution\ExecutionReference;
use Workflow\V2\StateMachine\Rules\Decision;
use Workflow\V2\StateMachine\Rules\MachineAssessment;
use Workflow\V2\StateMachine\Rules\RecordedFacts;
use Workflow\V2\StateMachine\Rules\Verdict;
use Workflow\V2\StateMachine\Transition\FactSnapshot;
use Workflow\V2\StateMachine\Transition\SnapshotPhase;
use Workflow\V2\StateMachine\Transition\TransitionContext;

/** Mutable provider facts become immutable activity results before any pure rule evaluates them. */
final readonly class AssessmentStore
{
    public function __construct(
        private Authority $authority,
        private Journal $provider
    ) {
    }

    public function snapshot(WorkflowRun $actual, string $activityId, array $request): array
    {
        [$execution, $transition, $actor, $row, $plan] = $this->request($actual, $request, false);
        $id = hash('sha256', PortableDescriptor::encode([$actual->id, $activityId, $request]));
        $hash = hash('sha256', PortableDescriptor::encode($request));
        $existing = $this->authority->db->table('provider_machine_assessments')
            ->where('id', $id)
            ->first();
        if ($existing !== null) {
            if ($existing->actor_id !== $actor->id || $existing->request_sha256 !== $hash) {
                throw new LogicException('The original assessment identity changed its request.');
            }
            return $this->read($existing);
        }
        $root = $this->authority->subject($actor->root_key, 'order');
        $values = [
            'forward_open' => ! (bool) $root->cancellation_requested && ! (bool) $row->cancellation_requested,
            'cancellation_requested' => (bool) $root->cancellation_requested,
            'model_consistent' => (int) $row->revision > 0 && in_array(
                $row->state,
                Catalogue::profile($actor->role)->states,
                true
            ),
        ];
        $evidence = array_fill_keys(array_keys($values), 'business-snapshot:' . $id);
        if ($actor->role === 'order' && in_array($transition->key, ['pay', 'book'], true)) {
            $role = $transition->key === 'pay' ? 'payment' : 'shipment';
            $binding = $plan->binding($role);
            $milestone = $this->authority->db->table('provider_machine_milestones')
                ->where('id', $binding->key())
                ->first();
            if ($milestone !== null) {
                $payload = json_decode($milestone->payload, true, flags: JSON_THROW_ON_ERROR);
                if ($milestone->actor_id !== $row->id || ! hash_equals(
                    $milestone->payload_sha256,
                    hash('sha256', $milestone->payload)
                )
                    || PortableDescriptor::encode($payload['binding']['identity'] ?? []) !== PortableDescriptor::encode(
                        $binding->toArray()
                    )
                    || ($payload['binding']['admission']['childRunId'] ?? null) !== $milestone->child_run) {
                    throw new LogicException('The recorded original child milestone is corrupt.');
                }
            }
            $values[$transition->key . '_confirmed'] = $milestone !== null;
            $evidence[$transition->key . '_confirmed'] = 'original-child-publication:' . $binding->key();
        } elseif ($transition->key === 'cancel') {
            $scope = (new ScopeRepository())->metadata($plan->rootScope());
            $values['cancel_confirmed'] = $scope['original_run_id'] === $plan->rootRun && $scope['status'] === 'verified';
            $evidence['cancel_confirmed'] = 'retained-scope:' . $plan->rootScope()->id() . ':' . $scope['status'];
        } else {
            [, $terms] = $this->authority->assignment($actor->root_key);
            $forward = Request::fromArray($terms->descriptor($actor->role)['forward']);
            $observation = $this->provider->lookup($forward);
            // False means "not confirmed", never "the operation did not execute".
            $values[$transition->key . '_confirmed'] = $observation->status === Status::Confirmed
                && $observation->effectId === 'effect-' . $forward->identity->key();
            $evidence[$transition->key . '_confirmed'] = $observation->effectId
                ?? 'provider-observation:' . $forward->identity->key() . ':' . $observation->status->value;
        }
        $profile = Catalogue::profile($actor->role);
        $facts = new RecordedFacts($id, $values, $evidence);
        $registry = Catalogue::registry();
        $target = $registry->evaluateCondition($profile->condition($transition->key . '.target'), $facts);
        $permission = $registry->evaluateCondition($profile->condition($transition->key . '.permission'), $facts);
        $machine = $profile->definition->assess($registry, $facts);
        $snapshot = [
            'execution' => $execution->toArray(),
            'transitionKey' => $transition->key,
            'phase' => $request['phase'],
            'snapshotId' => $id,
            'state' => $row->state,
            'revision' => (string) $row->revision,
            'target' => $target->decision->toArray(),
            'permission' => $permission->decision->toArray(),
            'machineAssessment' => MachineAssessment::fromEvaluation($profile->definition, $machine)->toArray(),
        ];
        $payload = PortableDescriptor::encode([
            'snapshot' => $snapshot,
            'facts' => $facts->toArray(),
            'explanation' => [
                'target' => $target->toArray(),
                'permission' => $permission->toArray(),
                'machine' => $machine->toArray(),
            ],
        ]);
        $this->authority->db->table('provider_machine_assessments')
            ->insert([
                'id' => $id,
                'actor_id' => $actor->id,
                'request_sha256' => $hash,
                'payload' => $payload,
                'payload_sha256' => hash('sha256', $payload),
            ]);
        return $snapshot;
    }

    public function admit(WorkflowRun $actual, array $request): array
    {
        [$context, $actor, $row] = $this->context($actual, $request);
        $execution = $context->execution();
        $id = hash('sha256', PortableDescriptor::encode($execution->toArray()));
        $hash = hash('sha256', PortableDescriptor::encode($request));
        return $this->authority->db->transaction(function () use (
            $context,
            $actor,
            $row,
            $execution,
            $id,
            $hash
        ): array {
            $root = $this->authority->db->table('example_machine_subjects')
                ->where('id', Authority::subjectId($actor->root_key, 'order'))->lockForUpdate()->sole();
            $this->authority->assertGrant($root, $actor->root_key, 'order');
            $current = $this->authority->db->table('example_machine_subjects')
                ->where('id', $row->id)
                ->lockForUpdate()
                ->sole();
            $this->authority->assertGrant($current, $actor->root_key, $actor->role);
            $previous = $this->authority->db->table('provider_machine_admissions')
                ->where('id', $id)
                ->first();
            if ($previous !== null && ($previous->actor_id !== $actor->id || $previous->request_sha256 !== $hash)) {
                throw new LogicException('The original transition admission changed content.');
            }
            $allowed = ! (bool) $root->cancellation_requested && ! (bool) $current->cancellation_requested
                && $current->state === $execution->sourceState && (string) $current->revision === $execution->sourceRevision
                && $context->snapshot->permission->satisfied();
            if ($allowed && $previous !== null) {
                return json_decode($previous->decision, true, flags: JSON_THROW_ON_ERROR);
            }
            $decision = [
                'execution' => $execution->toArray(),
                'decision' => (new Decision(
                    $allowed ? Verdict::Satisfied : Verdict::Unsatisfied,
                    $allowed ? 'original-execution-admitted' : 'live-admission-refused',
                    ['business-admission:' . $id],
                ))->toArray(),
            ];
            if ($previous === null) {
                $this->authority->db->table('provider_machine_admissions')
                    ->insert([
                        'id' => $id,
                        'actor_id' => $actor->id,
                        'request_sha256' => $hash,
                        'decision' => PortableDescriptor::encode($decision),
                    ]);
            }
            return $decision;
        });
    }

    public function commit(WorkflowRun $actual, array $request): array
    {
        [$context, $actor, $row, $plan] = $this->context($actual, $request);
        if ($context->transition->key === 'cancel') {
            return app(CancellationStore::class)->commit($context, $row, $plan);
        }
        $store = new SqlBusinessStore($this->authority->db, function (
            ExecutionReference $execution,
            string $principal,
            string $operation,
            ?TransitionContext $proposed,
        ) use ($actual, $actor, $context): string {
            $this->authority->authorize($actor->root_key, $principal);
            $this->authority->actor($actual, $execution);
            if ($operation !== 'commit' || $proposed !== $context || ! $context->snapshot->target->satisfied()) {
                throw new LogicException('The protected commit lacks its original satisfied provider assessment.');
            }
            return Authority::grant($actor->role);
        });
        $result = $store->commit($context, $this->authority->principal);
        return [
            'execution' => $result->execution->toArray(),
            'status' => $result->status->value,
            'reason' => $result->reason,
        ];
    }

    private function context(WorkflowRun $actual, array $request): array
    {
        [$execution, $transition, $actor, $row, $plan] = $this->request($actual, $request, true);
        $record = $this->authority->db->table('provider_machine_assessments')
            ->where('actor_id', $actor->id)
            ->where('id', $request['snapshotId'])->sole();
        $snapshot = $this->read($record);
        if ($record->actor_id !== $actor->id || PortableDescriptor::encode(
            $snapshot['execution']
        ) !== PortableDescriptor::encode($execution->toArray())
            || $snapshot['transitionKey'] !== $transition->key || $snapshot['phase'] !== $request['phase']
            || $snapshot['state'] !== $request['expectedState'] || $snapshot['revision'] !== $request['expectedRevision']
            || PortableDescriptor::encode($snapshot['machineAssessment']) !== PortableDescriptor::encode(
                $request['machineAssessment']
            )) {
            throw new LogicException('The protected request differs from its immutable original assessment.');
        }
        $decision = static fn (array $v): Decision => new Decision(Verdict::from(
            $v['verdict']
        ), $v['reason'], $v['evidence']);
        $machine = MachineAssessment::fromArray(
            $snapshot['machineAssessment'],
            Catalogue::profile($actor->role)->definition
        );
        if (! $machine->decision->satisfied()) {
            throw new LogicException('The protected boundary requires a satisfied shared machine invariant.');
        }
        return [new TransitionContext($transition, new FactSnapshot(
            $execution,
            $transition->key,
            SnapshotPhase::from($snapshot['phase']),
            $snapshot['snapshotId'],
            $snapshot['state'],
            $snapshot['revision'],
            $decision($snapshot['target']),
            $decision($snapshot['permission']),
            $machine,
        )), $actor, $row, $plan];
    }

    private function request(WorkflowRun $actual, array $request, bool $protected): array
    {
        $execution = Authority::reference($request['execution'] ?? []);
        [$actor, $row, $plan] = $this->authority->actor($actual, $execution);
        $keys = $protected ? [
            'execution',
            'transition',
            'snapshotId',
            'phase',
            'expectedState',
            'expectedRevision',
            'machineAssessment',
        ]
            : ['execution', 'transition', 'phase', 'machineDefinitionFingerprint'];
        $actualKeys = array_keys($request);
        sort($actualKeys);
        sort($keys);
        if ($keys !== $actualKeys || ! is_array($request['transition']) || ! is_string($request['phase'])) {
            throw new LogicException('The recorded provider transition request is malformed.');
        }
        SnapshotPhase::from($request['phase']);
        $transition = Catalogue::transition($actor->role, $request['transition']['key'] ?? '');
        $profile = Catalogue::profile($actor->role);
        if (PortableDescriptor::encode($request['transition']) !== PortableDescriptor::encode(
            get_object_vars($transition)
        )
            || $execution->executionId !== $actual->id . '.' . $transition->key . '.' . $execution->sourceRevision
            || ! in_array($execution->sourceState, $transition->from, true)
            || (! $protected && $request['machineDefinitionFingerprint'] !== $profile->definition->fingerprint())) {
            throw new LogicException('The original transition or shared machine definition was substituted.');
        }
        return [$execution, $transition, $actor, $row, $plan];
    }

    private function read(object $record): array
    {
        if (! hash_equals($record->payload_sha256, hash('sha256', $record->payload))) {
            throw new LogicException('The immutable recorded provider assessment was corrupted.');
        }
        return json_decode($record->payload, true, flags: JSON_THROW_ON_ERROR)['snapshot'];
    }
}
