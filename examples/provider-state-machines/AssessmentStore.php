<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use DurableWorkflow\Examples\BusinessStateMachines\SqlBusinessStore;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Journal;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Request;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Status;
use Illuminate\Support\Carbon;
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
        $facts = $this->facts($actual, $activityId, $actor, $row, $plan, $transition->key, $id);
        $profile = $this->authority->profile($actor->root_key, $actor->role);
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
        if (FundingCatalogue::selected($this->authority->recipe($actor->root_key))) {
            // Authenticate before opening the application-owned transaction; its lock still rechecks cancellation.
            (new FundingAuthority($this->authority))->assertForward($actor->root_key, $actor->role);
        }
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
            if (FundingCatalogue::selected($this->authority->recipe($actor->root_key))) {
                (new FundingAuthority($this->authority))->assertForward($actor->root_key, $actor->role);
            }
            if ($operation !== 'commit' || $proposed !== $context || ! $context->snapshot->target->satisfied()) {
                throw new LogicException('The protected commit lacks its original satisfied provider assessment.');
            }
            return $this->authority->grantFor($actor->root_key, $actor->role);
        });
        $result = $store->commit($context, $this->authority->principal);
        return [
            'execution' => $result->execution->toArray(),
            'status' => $result->status->value,
            'reason' => $result->reason,
        ];
    }

    /**
     * Recorded boundary: unknown is never promoted to stock refusal or financial settlement.
     */
    public function stockRefusal(WorkflowRun $actual, bool $requestCancellation = false): array
    {
        [$actor, $row, $forward] = $this->reservationForRefusal($actual);
        $observed = $this->provider->lookup($forward);
        if ($observed->status === Status::Refused && $observed->effectId === null) {
            $observed = $this->provider->closeAdmission($forward);
        }
        $refused = $observed->status === Status::Refused && $observed->effectId === null && $observed->admissionClosed;
        $result = [
            'refused' => $refused,
            'confirmed' => $observed->status === Status::Confirmed
                && $observed->effectId === 'effect-' . $forward->identity->key(),
            'status' => $observed->status->value,
            'operation' => $forward->identity->key(),
        ];
        if ($requestCancellation) {
            if (! $refused) {
                throw new LogicException('An unresolved reservation cannot be treated as definitive stock refusal.');
            }
            $this->recordStockRefusal($actual, $actor, $row, $forward);
            $result['cancellation'] = app(CancellationStore::class)->request(
                $actor->root_key,
                $actual->id,
                'stock-refused'
            );
        }
        return $result;
    }

    private function reservationForRefusal(WorkflowRun $actual): array
    {
        [$actor, $row, $plan] = $this->authority->actor($actual);
        if ($actual->workflow_class !== CheckoutOrder::class || $actor->role !== 'order'
            || $plan->recipe === Catalogue::RESERVE_FIRST || $row->state !== 'received'
            || (string) $row->revision !== '1'
            || ($plan->recipe === Catalogue::PAYMENT_FIRST && ! $this->childConfirmed($row, $plan, 'payment'))) {
            throw new LogicException(
                'Stock refusal requires the original checkout and its policy payment prerequisite.'
            );
        }
        $this->authority->assignment($actor->root_key);
        $expected = $this->authority->cart->stockObligation($actor->root_key);
        $descriptor = $expected->input;
        $scope = (new ScopeRepository())->inspect($plan->rootScope());
        $effect = array_filter(
            $scope['obligations'],
            static fn (array $item): bool => ($item['descriptor']['key'] ?? null) === $expected->key
        );
        $obligation = count($effect) === 1 ? array_values($effect)[0]['descriptor'] : null;
        if ($scope['original_run_id'] !== $actual->id || ($obligation['handlerType'] ?? null) !== $expected->handlerType
            || ($obligation['handlerVersion'] ?? null) !== $expected->handlerVersion
            || PortableDescriptor::encode($obligation['input'] ?? []) !== PortableDescriptor::encode($descriptor)) {
            throw new LogicException('Stock refusal requires the original recorded reservation obligation.');
        }
        $forward = Request::fromArray($descriptor['forward']);
        return [$actor, $row, $forward];
    }

    private function recordStockRefusal(WorkflowRun $actual, object $actor, object $row, Request $forward): void
    {
        $receipt = PortableDescriptor::encode([
            'originalRunId' => $actual->id,
            'operation' => $forward->identity->key(),
            'status' => 'refused',
            'admissionClosed' => true,
        ]);
        $this->authority->db->transaction(function () use ($actor, $row, $actual, $receipt): void {
            $current = $this->authority->db->table('example_machine_subjects')
                ->where('id', $row->id)
                ->lockForUpdate()
                ->sole();
            $this->authority->assertGrant($current, $actor->root_key, 'order');
            $assignment = $this->authority->db->table('provider_machine_assignments')
                ->where('id', $actor->root_key)
                ->sole();
            if ($assignment->root_run !== $actual->id || $current->state !== 'received' || (string) $current->revision !== '1'
                || ($assignment->stock_refusal !== null && $assignment->stock_refusal !== $receipt)) {
                throw new LogicException('The original stock refusal or paid Order changed.');
            }
            $this->authority->db->table('provider_machine_assignments')
                ->where('id', $actor->root_key)
                ->update([
                    'stock_refusal' => $receipt,
                ]);
        });
    }

    private function facts(
        WorkflowRun $actual,
        string $activityId,
        object $actor,
        object $row,
        BindingPlan $plan,
        string $transitionKey,
        string $id
    ): RecordedFacts {
        $root = $this->authority->subject($actor->root_key, 'order');
        $values = [
            'forward_open' => ! (bool) $root->cancellation_requested && ! (bool) $row->cancellation_requested,
            'cancellation_requested' => (bool) $root->cancellation_requested,
            'model_consistent' => (int) $row->revision > 0 && in_array(
                $row->state,
                $this->authority->profile($actor->root_key, $actor->role)
->states,
                true
            ),
        ];
        $evidence = array_fill_keys(array_keys($values), 'business-snapshot:' . $id);
        $selected = null;
        $facts = new RecordedFacts($id, $values, $evidence);
        if (FundingCatalogue::selected($plan->recipe)) {
            $selected = $this->fundingFacts($actual, $activityId, $actor, $plan, $transitionKey, $facts);
        } elseif (FinancialCatalogue::selected($plan->recipe)) {
            $selected = $this->selectedFacts($actual, $activityId, $actor, $plan, $transitionKey, $facts);
        }
        if ($selected !== null) {
            return $selected;
        }
        if ($actor->role === 'order' && $transitionKey === 'prepare') {
            return $this->preparationFacts($actual, $actor, $row, $plan, $values, $evidence, $id);
        }
        if ($actor->role === 'order' && in_array($transitionKey, ['pay', 'book'], true)) {
            $role = $transitionKey === 'pay' ? 'payment' : 'shipment';
            $values[$transitionKey . '_confirmed'] = $this->childConfirmed($row, $plan, $role);
            $evidence[$transitionKey . '_confirmed'] = 'original-child-publication:' . $plan->binding($role)->key();
        } elseif ($transitionKey === 'cancel') {
            $scope = (new ScopeRepository())->metadata($plan->rootScope());
            $values['cancel_confirmed'] = $scope['original_run_id'] === $plan->rootRun && $scope['status'] === 'verified';
            $evidence['cancel_confirmed'] = 'retained-scope:' . $plan->rootScope()->id() . ':' . $scope['status'];
        } else {
            [, $terms] = $this->authority->assignment($actor->root_key);
            $forward = Request::fromArray($this->authority->stock->descriptor($terms, $actor->role)['forward']);
            $observation = $this->provider->lookup($forward);
            // False means "not confirmed", never "the operation did not execute".
            $values[$transitionKey . '_confirmed'] = $observation->status === Status::Confirmed
                && $observation->effectId === 'effect-' . $forward->identity->key();
            $evidence[$transitionKey . '_confirmed'] = $observation->effectId
                ?? 'provider-observation:' . $forward->identity->key() . ':' . $observation->status->value;
        }
        return new RecordedFacts($id, $values, $evidence);
    }

    private function fundingFacts(
        WorkflowRun $actual,
        string $activityId,
        object $actor,
        BindingPlan $plan,
        string $transitionKey,
        RecordedFacts $facts
    ): ?RecordedFacts {
        $values = $facts->values;
        $evidence = $facts->evidence;
        $id = $facts->snapshotId;
        foreach (array_unique(['order', $actor->role]) as $role) {
            $scope = $role === 'order' ? $plan->rootScope() : $plan->childScope($role);
            $values['forward_open'] = $values['forward_open']
                && (new ScopeRepository())->metadata($scope)['status'] === 'open';
        }
        $result = null;
        if ($actor->role === 'payment') {
            $snapshot = app(FundingFactsStore::class)->observe($actual, $activityId);
            foreach ($snapshot['values'] as $fact => $value) {
                $values[$fact] = $value;
                $evidence[$fact] = 'original-funding-assessment:' . $id;
            }
            $values['model_consistent'] = $values['model_consistent']
                && ($snapshot['values']['financial_observation_consistent'] ?? true);
            $result = new RecordedFacts($id, $values, $evidence);
        } elseif ($actor->role === 'shipment') {
            $result = $this->physicalFacts($actual, $values, $evidence, $id);
        } elseif ($transitionKey === 'prepare') {
            $values['prepare_confirmed'] = app(FundingHandoffStore::class)->prepared($actual);
            $evidence['prepare_confirmed'] = 'original-funding-preparation:' . $plan->binding('payment')->key();
            $result = new RecordedFacts($id, $values, $evidence);
        } elseif ($transitionKey === 'fulfill') {
            $result = $this->fulfillmentFacts($actual, $actor, $values, $evidence, $id);
        }
        return $result;
    }

    private function selectedFacts(
        WorkflowRun $actual,
        string $activityId,
        object $actor,
        BindingPlan $plan,
        string $transitionKey,
        RecordedFacts $facts
    ): ?RecordedFacts {
        $values = $facts->values;
        $evidence = $facts->evidence;
        $id = $facts->snapshotId;
        return match ($actor->role) {
            'payment' => $this->financialFacts($actual, $activityId, $actor, $plan, $values, $evidence, $id),
            'shipment' => $this->physicalFacts($actual, $values, $evidence, $id),
            'order' => match ($transitionKey) {
                'fulfill' => $this->fulfillmentFacts($actual, $actor, $values, $evidence, $id),
                'remedy' => $this->remedyFacts($actual, $values, $evidence, $id),
                default => null,
            },
            default => null,
        };
    }

    private function physicalFacts(WorkflowRun $actual, array $values, array $evidence, string $id): RecordedFacts
    {
        $physical = app(ShipmentBoundaryStore::class)->facts($actual);
        foreach ($physical['values'] as $key => $value) {
            $values[$key] = $value;
            $evidence[$key] = 'original-physical-assessment:' . $id;
        }
        $values['physical_observations'] = $physical['snapshot'];
        $evidence['physical_observations'] = 'original-physical-assessment:' . $id;
        return new RecordedFacts($id, $values, $evidence);
    }

    private function fulfillmentFacts(
        WorkflowRun $actual,
        object $actor,
        array $values,
        array $evidence,
        string $id
    ): RecordedFacts {
        [, $terms] = $this->authority->assignment($actor->root_key);
        $values['fulfillment_permitted'] = $terms->fulfillmentDeadline !== null
            && Carbon::now()->getTimestamp() < $terms->fulfillmentDeadline;
        $values['fulfillment_confirmed'] = app(ShipmentBoundaryStore::class)->fulfilled($actual);
        $evidence['fulfillment_permitted'] = $evidence['fulfillment_confirmed'] = 'original-fulfillment:' . $id;
        return new RecordedFacts($id, $values, $evidence);
    }

    private function remedyFacts(WorkflowRun $actual, array $values, array $evidence, string $id): RecordedFacts
    {
        $remedy = app(IncidentStore::class)->remedy($actual);
        $values['transit_incident_confirmed'] = $remedy['incident'];
        $values['transit_remedy_settled'] = $remedy['settled'];
        $evidence['transit_incident_confirmed'] = $evidence['transit_remedy_settled'] = 'original-transit-remedy:' . $id;
        return new RecordedFacts($id, $values, $evidence);
    }

    private function preparationFacts(
        WorkflowRun $actual,
        object $actor,
        object $row,
        BindingPlan $plan,
        array $values,
        array $evidence,
        string $id
    ): RecordedFacts {
        if (FinancialCatalogue::selected($plan->recipe)) {
            $values['prepare_confirmed'] = app(FinancialHandoffStore::class)->prepared($actual);
            $evidence['prepare_confirmed'] = 'original-financial-preparation:' . $plan->binding('payment')->key();
        } else {
            $this->authority->assignment($actor->root_key);
            $forward = Request::fromArray($this->authority->cart->stockObligation($actor->root_key)->input['forward']);
            $stock = $this->provider->lookup($forward);
            $values['prepare_confirmed'] = $this->childConfirmed($row, $plan, 'payment')
                && $stock->status === Status::Confirmed && $stock->effectId === 'effect-' . $forward->identity->key();
            $evidence['prepare_confirmed'] = 'original-checkout:' . $plan->binding(
                'payment'
            )->key() . ':' . $forward->identity->key();
        }
        return new RecordedFacts($id, $values, $evidence);
    }

    private function financialFacts(
        WorkflowRun $actual,
        string $activityId,
        object $actor,
        BindingPlan $plan,
        array $values,
        array $evidence,
        string $id
    ): RecordedFacts {
        [, $terms] = $this->authority->assignment($actor->root_key);
        $snapshot = app(FinancialInspectionStore::class)->inspect(
            $actual,
            $activityId,
            $plan->childScope('payment')
                ->toArray(),
            $terms->financialDescriptor()
        );
        foreach ($snapshot['financial']['facts'] as $key => $value) {
            $values[$key] = $value;
            $evidence[$key] = 'original-financial-snapshot:' . $snapshot['snapshotId'];
        }
        // A provider confirmation alone cannot skip establishing this original native effect owner.
        $values['authorization_confirmed'] = $values['authorization_confirmed']
            && $snapshot['business']['nativeForward']['authorize'] === 'observed';
        $values['capture_confirmed'] = $values['capture_confirmed']
            && $snapshot['business']['nativeForward']['capture'] === 'observed';
        $values['model_consistent'] = $values['model_consistent'] && $values['financial_observation_consistent'];
        $values['financial_observations'] = $snapshot['financial']['observations'];
        $values['financial_observed_at'] = $snapshot['financial']['observedAt'];
        $evidence['financial_observations'] = $evidence['financial_observed_at'] = 'original-financial-snapshot:' . $snapshot['snapshotId'];
        if ($snapshot['business']['captureInstruction'] !== null) {
            $values[FinancialCatalogue::captureTrigger($plan->recipe)] = true;
            $evidence[FinancialCatalogue::captureTrigger($plan->recipe)] = 'original-policy-instruction:'
                . $snapshot['business']['captureInstruction']['id'];
        }
        return new RecordedFacts($id, $values, $evidence);
    }

    private function childConfirmed(object $row, BindingPlan $plan, string $role): bool
    {
        $binding = $plan->binding($role);
        $milestone = $this->authority->db->table('provider_machine_milestones')
            ->where('id', $binding->key())
            ->first();
        if ($milestone === null) {
            return false;
        }
        if ($milestone->actor_id !== $row->id || ! hash_equals(
            $milestone->payload_sha256,
            hash('sha256', $milestone->payload)
        )) {
            throw new LogicException('The original child milestone belongs to another actor or is corrupt.');
        }
        $payload = json_decode($milestone->payload, true, flags: JSON_THROW_ON_ERROR);
        if (PortableDescriptor::encode($payload['binding']['identity'] ?? []) !== PortableDescriptor::encode(
            $binding->toArray()
        )
            || ($payload['binding']['admission']['childRunId'] ?? null) !== $milestone->child_run) {
            throw new LogicException('The recorded original child milestone is corrupt.');
        }
        return true;
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
            $this->authority->profile($actor->root_key, $actor->role)
->definition
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
        if ($actor->role === 'shipment' && (FinancialCatalogue::selected($plan->recipe) || FundingCatalogue::selected(
            $plan->recipe
        ))) {
            app(ShipmentBoundaryStore::class)->authorize($actual);
        }
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
        $transition = Catalogue::transition(
            $actor->role,
            $request['transition']['key'] ?? '',
            $this->authority->recipe($actor->root_key),
            $this->authority->transit->selected($actor->root_key)
        );
        if ($actual->workflow_class === Cancellation::class && $transition->key !== 'cancel') {
            throw new LogicException('The proof-only finalizer cannot admit or assess forward work.');
        }
        $profile = $this->authority->profile($actor->root_key, $actor->role);
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
