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
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\StateMachine\Binding\ChildBinding;
use Workflow\V2\StateMachine\Binding\ChildReservationStore;
use Workflow\V2\StateMachine\Execution\ExecutionReference;
use Workflow\V2\WorkflowStub;

final readonly class ShipmentBoundaryStore
{
    public function __construct(
        private Authority $authority,
        private Journal $provider,
        private ShipmentAuthority $physical
    ) {
    }

    public function register(WorkflowRun $actual): array
    {
        [$actor, , $terms, $graph] = $this->physical->rows($actual);
        if ($terms->fulfillmentDeadline === null) {
            throw new LogicException('Full Shipment requires its original physical fulfillment deadline.');
        }
        $this->physical->stock($actor->root_key);
        $this->provider->shipments()
            ->register($graph);
        return [
            'descriptorFingerprint' => $graph->fingerprint(),
        ];
    }

    public function authorize(WorkflowRun $actual): void
    {
        [$actor] = $this->physical->rows($actual);
        $this->physical->stock($actor->root_key);
    }

    public function deduct(WorkflowRun $actual): array
    {
        [$actor, , , $graph, $row] = $this->physical->rows($actual);
        $this->physical->forward($actor->root_key);
        $this->physical->stock($actor->root_key);
        $subject = $this->authority->subject($actor->root_key, 'shipment');
        if ($row['forward_status'] !== 'observed' || $subject->state !== 'booked' || (string) $subject->revision !== '2'
            || $graph->deduction === null) {
            throw new LogicException(
                'Deduction requires the original booked Shipment and predeclared physical responsibility.'
            );
        }
        $reservation = Request::fromArray($this->authority->cart->stockObligation($actor->root_key)->input['forward']);
        $receipt = $this->provider->lookup($reservation);
        if ($receipt->status !== Status::Confirmed || $receipt->effectId !== 'effect-' . $reservation->identity->key()) {
            throw new LogicException('Physical deduction requires the current original reservation receipt.');
        }
        $value = $this->provider->attempt($graph->deduction);
        return [
            'operation' => $graph->deduction->identity->key(),
            'status' => $value->status->value,
            'effectId' => $value->effectId,
        ];
    }

    public function facts(WorkflowRun $actual): array
    {
        [$actor, $plan, $terms, $graph, $row] = $this->physical->rows($actual);
        $this->physical->stock($actor->root_key);
        $snapshot = $this->provider->shipments()
            ->inspect($graph)
            ->toArray();
        $stock = Request::fromArray($this->authority->cart->stockObligation($actor->root_key)->input['forward']);
        $reservation = $this->provider->lookup($stock);
        $payment = $this->payment($plan);
        $stage = $plan->recipe === FinancialCatalogue::AFTER_STOCK ? 'capture' : 'authorize';
        $handoff = FinancialHandoffStore::class;
        if (FundingCatalogue::selected($plan->recipe)) {
            $handoff = FundingHandoffStore::class;
            $stage = $plan->recipe === FundingCatalogue::AFTER_DELIVERY || CodFundingPolicy::selected(
                $plan->recipe
            ) || AdvanceBalancePolicy::selected($plan->recipe) ? 'prepare' : 'confirm-receipt';
        }
        $milestone = app($handoff)
            ->milestone(WorkflowRun::query()->findOrFail($plan->rootRun), $payment->toArray(), $stage);
        $values = $snapshot['facts'];
        $values['booking_confirmed'] = $values['booking_confirmed'] && $row['forward_status'] === 'observed';
        $values['shipment_permitted'] = $reservation->status === Status::Confirmed
            && $reservation->effectId === 'effect-' . $stock->identity->key() && $milestone['confirmed']
            && (in_array($stage, ['capture', 'confirm-receipt'], true) || $milestone['waitSeconds'] > 0);
        $values['stock_deduction_confirmed'] = $values['inventory_accounted'] && $values['deducted_quantity'] === $terms->quantity;
        return [
            'values' => $values,
            'snapshot' => $snapshot,
        ];
    }

    public function milestone(WorkflowRun $parent, array $value): array
    {
        [$actor, , $plan] = $this->authority->actor($parent);
        $binding = ChildBinding::fromArray($value);
        $this->authority->assertAllowed($binding->identity, $this->authority->principal, 'close');
        if ($actor->role !== 'order' || $binding->identity->slot !== 'shipment'
            || $binding->identity->parentRunId !== $parent->id || $this->admitted($plan)
                ->toArray() !== $binding->toArray()) {
            throw new LogicException('Recipient delivery must come from the original admitted Shipment.');
        }
        $publication = $this->publication($plan, $binding);
        $value = [
            'confirmed' => $publication !== null,
            'publication' => $publication,
            'originOpen' => ! WorkflowRun::query()->findOrFail($binding->admission->childRunId)->status->isTerminal(),
        ];
        if ($this->authority->transit->selected($actor->root_key)) {
            // Legacy milestone results lack this field; only an admitted original incident sets it.
            $value['incident'] = $publication === null && app(IncidentStore::class)->remedy($parent)['incident'];
        }
        return $value;
    }

    public function notify(WorkflowRun $actual): array
    {
        [, $plan] = $this->physical->rows($actual);
        $binding = $this->admitted($plan);
        $publication = $this->publication($plan, $binding);
        if ($binding->admission->childRunId !== $actual->id || $publication === null) {
            throw new LogicException('Only the original committed recipient delivery can notify Order.');
        }
        $key = hash('sha256', PortableDescriptor::encode(['physical-delivery-v1', $publication['id'], $plan->rootRun]));
        $signal = WorkflowStub::loadRun($plan->rootRun, Catalogue::NAMESPACE)->withRequestId($key)
            ->attemptSignal('physical-milestone', $publication['id']);
        return [
            'publicationId' => $publication['id'],
            'notificationAccepted' => $signal->accepted(),
        ];
    }

    public function expire(WorkflowRun $actual): array
    {
        [$actor, , $plan] = $this->authority->actor($actual);
        [, $terms] = $this->authority->assignment($actor->root_key);
        if ($terms->fulfillmentDeadline === null || Carbon::now()->getTimestamp() < $terms->fulfillmentDeadline) {
            throw new LogicException('The original physical fulfillment deadline has not expired.');
        }
        return app(CancellationStore::class)->request($actor->root_key, $plan->rootRun, 'physical-fulfillment-expired');
    }

    public function fulfilled(WorkflowRun $parent): bool
    {
        [$actor, , $plan] = $this->authority->actor($parent);
        if ($actor->role !== 'order') {
            throw new LogicException('Fulfillment belongs to its original Order.');
        }
        if (! $this->authority->db->table('provider_machine_actors')
            ->where('id', Authority::subjectId($actor->root_key, 'shipment'))->exists()) {
            return false;
        }
        $binding = $this->admitted($plan);
        return $this->publication($plan, $binding) !== null
            && app(
                FundingCatalogue::selected($plan->recipe) ? FundingHandoffStore::class : FinancialHandoffStore::class
            )->milestone(
                $parent,
                $this->payment($plan)
                    ->toArray(),
                FundingCatalogue::selected($plan->recipe) ? 'confirm-receipt' : 'capture'
            )['confirmed'];
    }

    public function delivery(WorkflowRun $parent): ?array
    {
        [$actor, , $plan] = $this->authority->actor($parent);
        if ($actor->role !== 'order') {
            throw new LogicException('The delivery capture trigger belongs to its original Order.');
        }
        return $this->publication($plan, $this->admitted($plan));
    }

    private function publication(BindingPlan $plan, ChildBinding $binding): ?array
    {
        $run = $binding->admission->childRunId;
        $execution = new ExecutionReference(
            Catalogue::APPLICATION,
            Authority::subjectName($plan->terms->subjectKey(), 'shipment'),
            $run . '.deliver.3',
            Catalogue::profile('shipment', $plan->recipe)->fingerprint(),
            'in-transit',
            '3'
        );
        $id = SqlBusinessStore::decisionId($execution, 'transition', $execution->executionId);
        $row = $this->authority->db->table('example_machine_publications')
            ->where('id', $id)
            ->first();
        if ($row === null) {
            return null;
        }
        $payload = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
        if ($row->subject_id !== Authority::subjectId($plan->terms->subjectKey(), 'shipment')
            || ($payload['state'] ?? null) !== 'delivered' || ($payload['revision'] ?? null) !== '4'
            || PortableDescriptor::encode($payload['execution'] ?? []) !== PortableDescriptor::encode(
                $execution->toArray()
            )) {
            throw new LogicException('The original recipient-delivery publication changed owner, execution or stage.');
        }
        return [
            'id' => $id,
            'binding' => $binding->toArray(),
            'publication' => $payload,
        ];
    }

    private function admitted(BindingPlan $plan): ChildBinding
    {
        return $this->binding($plan, 'shipment');
    }

    private function payment(BindingPlan $plan): ChildBinding
    {
        return $this->binding($plan, 'payment');
    }

    private function binding(BindingPlan $plan, string $role): ChildBinding
    {
        $reservations = app(ChildReservationStore::class);
        $reservation = $reservations->load(
            $plan->binding($role),
            $this->authority->principal,
            $plan->participation($role)
        );
        $binding = $reservations->close($reservation, $this->authority->principal);
        if (! $binding instanceof ChildBinding) {
            throw new LogicException('The original child has no authoritative native admission.');
        }
        return $binding;
    }
}
