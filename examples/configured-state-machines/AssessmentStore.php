<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ConfiguredStateMachines;

use DurableWorkflow\Examples\BusinessStateMachines\SqlBusinessStore;
use Illuminate\Database\ConnectionInterface;
use LogicException;
use Workflow\V2\Compensation\Definition\PortableDescriptor;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\StateMachine\Binding\ChildBinding;
use Workflow\V2\StateMachine\Definition\TransitionDefinition;
use Workflow\V2\StateMachine\Execution\ExecutionReference;
use Workflow\V2\StateMachine\Execution\TransitionStatus;
use Workflow\V2\StateMachine\Rules\Decision;
use Workflow\V2\StateMachine\Rules\MachineAssessment;
use Workflow\V2\StateMachine\Rules\RecordedFacts;
use Workflow\V2\StateMachine\Rules\Verdict;
use Workflow\V2\StateMachine\Transition\FactSnapshot;
use Workflow\V2\StateMachine\Transition\SnapshotPhase;
use Workflow\V2\StateMachine\Transition\TransitionContext;

/** Recorded fact collection, pure assessments, admission and protected forward commits. */
final class AssessmentStore
{
    private readonly ConnectionInterface $db;

    public function __construct(
        private readonly MachineAuthority $authority
    ) {
        $this->db = $authority->db;
    }

    public function snapshot(WorkflowRun $actual, string $activityId, array $request): array
    {
        [$execution, $transition, $row, $profile] = $this->request(
            $actual,
            $request,
            ['execution', 'transition', 'phase']
        );
        $phase = SnapshotPhase::from($request['phase']);
        $id = hash('sha256', PortableDescriptor::encode([$activityId, $request]));
        $requestHash = hash('sha256', PortableDescriptor::encode($request));
        $existing = $this->db->table('configured_machine_assessments')
            ->where('id', $id)
            ->first();
        if ($existing !== null) {
            if ($existing->subject_id !== $row->id || $existing->request_sha256 !== $requestHash) {
                throw new LogicException('An assessment boundary was reused for another request.');
            }
            return $this->assessment($existing);
        }
        $values = $this->facts($execution, $transition, $row);
        if ($profile->definition !== null) {
            $values['model_consistent'] = (int) $row->revision > 0
                && in_array($row->state, $profile->definition->stateIds(), true);
        }
        $facts = new RecordedFacts($id, $values, array_combine(array_keys($values), array_map(
            static fn (string $name): string => 'recorded-fact:' . $id . ':' . $name,
            array_keys($values)
        )));
        $registry = Catalogue::registry();
        $target = $registry->evaluateCondition($profile->condition($transition->key . '.target'), $facts);
        $permission = $registry->evaluateCondition($profile->condition($transition->key . '.permission'), $facts);
        $snapshot = [
            'execution' => $execution->toArray(),
            'transitionKey' => $transition->key,
            'phase' => $phase->value,
            'snapshotId' => $id,
            'state' => $row->state,
            'revision' => (string) $row->revision,
            'target' => $target->decision->toArray(),
            'permission' => $permission->decision->toArray(),
        ];
        $explanation = [
            'target' => $target->toArray(),
            'permission' => $permission->toArray(),
        ];
        if ($profile->definition !== null) {
            $machine = $profile->definition->assess($registry, $facts);
            $snapshot['machineAssessment'] = MachineAssessment::fromEvaluation(
                $profile->definition,
                $machine
            )->toArray();
            $explanation['machine'] = $machine->toArray();
        }
        $factsJson = PortableDescriptor::encode($facts->toArray());
        $explanationJson = PortableDescriptor::encode($explanation);
        $snapshotJson = PortableDescriptor::encode($snapshot);
        $this->db->table('configured_machine_assessments')
            ->insert([
                'id' => $id,
                'subject_id' => $row->id,
                'request_sha256' => $requestHash,
                'facts' => $factsJson,
                'explanation' => $explanationJson,
                'snapshot' => $snapshotJson,
                'data_sha256' => hash(
                    'sha256',
                    PortableDescriptor::encode([$factsJson, $explanationJson, $snapshotJson])
                ),
            ]);
        return $snapshot;
    }

    public function admit(WorkflowRun $actual, array $request): array
    {
        [$context, $row] = $this->context($actual, $request);
        $this->assertInvariant($context);
        $execution = $context->execution();
        $id = hash('sha256', PortableDescriptor::encode($execution->toArray()));
        $hash = hash('sha256', PortableDescriptor::encode($request));
        return $this->db->transaction(function () use ($context, $row, $execution, $id, $hash): array {
            $current = $this->db->table('example_machine_subjects')
                ->where('id', $row->id)
                ->lockForUpdate()
                ->sole();
            $this->authority->assertGrant($current, $this->authority->profile($current));
            $existing = $this->db->table('configured_machine_admissions')
                ->where('id', $id)
                ->first();
            if ($existing !== null) {
                if ($existing->subject_id !== $row->id || $existing->request_sha256 !== $hash) {
                    throw new LogicException('The original transition admission changed content.');
                }
                // An admission receipt is not reusable permission after a live revocation.
                if ((bool) $current->cancellation_requested || ! (bool) $current->enabled) {
                    return [
                        'execution' => $execution->toArray(),
                        'decision' => (new Decision(
                            Verdict::Unsatisfied,
                            'live_admission_refused',
                            ['business-admission:' . $id]
                        ))->toArray(),
                    ];
                }
                return json_decode($existing->decision, true, flags: JSON_THROW_ON_ERROR);
            }
            $allowed = ! (bool) $current->cancellation_requested && (bool) $current->enabled
                && $current->state === $execution->sourceState && (string) $current->revision === $execution->sourceRevision
                && $context->snapshot->permission->satisfied();
            $decision = [
                'execution' => $execution->toArray(),
                'decision' => (new Decision(
                    $allowed ? Verdict::Satisfied : Verdict::Unsatisfied,
                    $allowed ? 'original_execution_admitted' : 'live_admission_refused',
                    ['business-admission:' . $id],
                ))->toArray(),
            ];
            $this->db->table('configured_machine_admissions')
                ->insert([
                    'id' => $id,
                    'subject_id' => $row->id,
                    'request_sha256' => $hash,
                    'decision' => PortableDescriptor::encode($decision),
                ]);
            return $decision;
        });
    }

    public function commit(WorkflowRun $actual, array $request): array
    {
        [$context, $row] = $this->context($actual, $request);
        $this->assertInvariant($context);
        if ($context->transition->key === 'cancel') {
            return app(CancellationStore::class)->commit($context, $row);
        }
        $store = new SqlBusinessStore($this->db, function (
            ExecutionReference $execution,
            string $principal,
            string $operation,
            ?TransitionContext $proposed
        ) use ($context, $row): string {
            $this->authority->ownership->authorize(explode('.', $row->subject)[0], $principal);
            if ($operation !== 'commit' || $proposed !== $context || ! $execution->equals($context->execution())
                || ! $context->snapshot->target->satisfied()) {
                throw new LogicException('The protected commit lacks its original satisfied assessment.');
            }
            return MachineAuthority::grant($this->authority->profile($row));
        });
        $result = $store->commit($context, $this->authority->ownership->principal);
        return [
            'execution' => $result->execution->toArray(),
            'status' => $result->status->value,
            'reason' => $result->reason,
        ];
    }

    private function facts(ExecutionReference $execution, TransitionDefinition $transition, object $row): array
    {
        $values = [
            'enabled' => (bool) $row->enabled,
            'forward_open' => ! (bool) $row->cancellation_requested,
            'cancellation_requested' => (bool) $row->cancellation_requested,
            'prevalidated' => (bool) $row->prevalidated,
            'slot_available' => $row->active_binding_id === null,
            'shipment_started' => false,
            'shipment_verified' => false,
            'hosting_committed' => false,
            'scope_verified' => false,
        ];
        foreach (['reserve', 'prepare', 'import', 'deliver'] as $action) {
            $values[$action . '_active'] = $this->db->table('example_machine_effects')->where('subject_id', $row->id)
                ->where('action', $action)
                ->where('status', 'active')
                ->exists();
        }
        if ($row->active_binding_id !== null) {
            $binding = $this->db->table('example_machine_bindings')
                ->where('id', $row->active_binding_id)
                ->sole();
            $receipt = ChildBinding::fromArray(json_decode($binding->admission, true, flags: JSON_THROW_ON_ERROR));
            $sameEntry = $transition->key !== 'begin' || $receipt->identity->parentExecution->equals($execution);
            $values['shipment_started'] = $binding->subject_id === $row->id && $sameEntry;
            $values['shipment_verified'] = $values['shipment_started'] && (bool) $binding->business_outcome_verified;
            $values['hosting_committed'] = $this->db->table('example_machine_decisions')->where(
                'id',
                SqlBusinessStore::decisionId(
                    $receipt->identity->parentExecution,
                    'transition',
                    $receipt->identity->parentExecution->executionId
                )
            )->where('status', TransitionStatus::Committed->value)->exists();
        }
        if ($transition->key === 'cancel') {
            $metadata = (new ScopeRepository())->inspect(Ownership::scope($row->subject, $row->original_native_run_id));
            $values['scope_verified'] = $metadata['original_run_id'] === $row->original_native_run_id && $metadata['status'] === 'verified';
        }
        return $values;
    }

    private function context(WorkflowRun $actual, array $request): array
    {
        [$execution, $transition, $row, $profile] = $this->request(
            $actual,
            $request,
            ['execution', 'transition', 'snapshotId', 'phase', 'expectedState', 'expectedRevision']
        );
        $record = $this->db->table('configured_machine_assessments')
            ->where('id', $request['snapshotId'])->sole();
        if ($record->subject_id !== $row->id) {
            throw new LogicException('The assessment belongs to another subject.');
        }
        $snapshot = $this->assessment($record);
        if (PortableDescriptor::encode($snapshot['execution']) !== PortableDescriptor::encode($execution->toArray())
            || $snapshot['transitionKey'] !== $transition->key || $snapshot['phase'] !== $request['phase']
            || $snapshot['state'] !== $request['expectedState'] || $snapshot['revision'] !== $request['expectedRevision']) {
            throw new LogicException('The protected boundary does not match its recorded assessment.');
        }
        $machine = null;
        if ($profile->definition !== null) {
            if (PortableDescriptor::encode($request['machineAssessment']) !== PortableDescriptor::encode(
                $snapshot['machineAssessment']
            )) {
                throw new LogicException('The protected boundary substituted its shared machine assessment.');
            }
            $machine = MachineAssessment::fromArray($snapshot['machineAssessment'], $profile->definition);
        }
        $decision = static fn (array $v): Decision => new Decision(Verdict::from(
            $v['verdict']
        ), $v['reason'], $v['evidence']);
        return [new TransitionContext($transition, new FactSnapshot(
            $execution,
            $transition->key,
            SnapshotPhase::from($snapshot['phase']),
            $snapshot['snapshotId'],
            $snapshot['state'],
            $snapshot['revision'],
            $decision($snapshot['target']),
            $decision($snapshot['permission']),
            $machine
        )), $row];
    }

    private function request(WorkflowRun $actual, array $request, array $keys): array
    {
        $execution = MachineAuthority::reference($request['execution'] ?? []);
        [$row, $profile] = $this->authority->actor($actual, $execution);
        if ($profile->definition !== null) {
            if (in_array('snapshotId', $keys, true)) {
                $keys[] = 'machineAssessment';
                if (! is_array($request['machineAssessment'] ?? null)) {
                    throw new LogicException('The shared machine assessment is missing or malformed.');
                }
            } else {
                $keys[] = 'machineDefinitionFingerprint';
                if (($request['machineDefinitionFingerprint'] ?? null) !== $profile->definition->fingerprint()) {
                    throw new LogicException('The snapshot selected another shared machine definition.');
                }
            }
        }
        $actualKeys = array_keys($request);
        sort($actualKeys);
        sort($keys);
        if ($actualKeys !== $keys || ! is_array($request['transition'])) {
            throw new LogicException('The recorded transition request is malformed.');
        }
        $transition = Catalogue::transition($profile, $request['transition']['key'] ?? '');
        if (($actual->workflow_class === Cancellation::class) !== ($transition->key === 'cancel')) {
            throw new LogicException('This native owner cannot execute the proposed transition.');
        }
        if (PortableDescriptor::encode($request['transition']) !== PortableDescriptor::encode(
            get_object_vars($transition)
        )) {
            throw new LogicException('The original transition definition was substituted.');
        }
        return [$execution, $transition, $row, $profile];
    }

    private function assessment(object $row): array
    {
        if (! hash_equals(
            $row->data_sha256,
            hash('sha256', PortableDescriptor::encode([$row->facts, $row->explanation, $row->snapshot]))
        )) {
            throw new LogicException('The immutable recorded assessment was corrupted.');
        }
        return json_decode($row->snapshot, true, flags: JSON_THROW_ON_ERROR);
    }

    private function assertInvariant(TransitionContext $context): void
    {
        if ($context->snapshot->machineAssessment !== null && ! $context->snapshot->machineAssessment->decision->satisfied()) {
            throw new LogicException('The protected transition requires its satisfied shared machine invariant.');
        }
    }
}
