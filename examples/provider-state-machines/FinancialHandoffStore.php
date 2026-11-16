<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use DurableWorkflow\Examples\BusinessStateMachines\SqlBusinessStore;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\AuthorizationTerms;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\FinancialDescriptor;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Journal;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Request;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Status;
use Illuminate\Support\Carbon;
use LogicException;
use Workflow\V2\Compensation\Definition\PortableDescriptor;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\StateMachine\Binding\ChildBinding;
use Workflow\V2\StateMachine\Binding\ChildReservationStore;
use Workflow\V2\StateMachine\Execution\ExecutionReference;
use Workflow\V2\WorkflowStub;

/** Original business publications and protected policy instructions, never facts supplied by a signal payload. */
readonly class FinancialHandoffStore
{
    private bool $commonFunding;

    private OriginalStock $originalStock;

    public function __construct(
        private Authority $authority,
        private Journal $provider,
        private FinancialAuthority|FundingAuthority $financial
    ) {
        $this->commonFunding = $financial instanceof FundingAuthority;
        $this->originalStock = new OriginalStock($authority, $provider);
    }

    public function milestone(WorkflowRun $parent, array $value, string $stage): array
    {
        [$actor, , , $binding] = $this->parentBinding($parent, $value);
        $publication = $this->publication($actor->root_key, $binding, $stage);
        [, $terms] = $this->authority->assignment($actor->root_key);
        $cod = $terms->codPayment ?? $terms->advanceBalance?->balance;
        if (! $this->commonFunding && $terms->authorization === null) {
            throw new LogicException('The original authorization terms are unavailable.');
        }
        return [
            'confirmed' => $publication !== null,
            'publication' => $publication,
            'originalRunId' => $binding->admission->childRunId,
            'originOpen' => ! WorkflowRun::query()->findOrFail($binding->admission->childRunId)->status->isTerminal(),
            'waitSeconds' => max(
                0,
                ($terms->authorization?->expiresAt ?? $terms->fulfillmentDeadline) - Carbon::now()->getTimestamp()
            ),
            'remittanceWaitSeconds' => $cod === null ? null : max(
                0,
                $cod->remittanceDeadline - Carbon::now()->getTimestamp()
            ),
        ];
    }

    public function notify(WorkflowRun $actual, string $stage): array
    {
        [$actor, , $plan] = $this->payment($actual);
        $binding = $this->admitted($plan);
        if ($binding->admission->childRunId !== $actual->id) {
            throw new LogicException('Only the original Payment can publish its business milestone.');
        }
        $publication = $this->publication($actor->root_key, $binding, $stage);
        if ($publication === null) {
            throw new LogicException('Notification requires the original protected business publication.');
        }
        $key = hash(
            'sha256',
            PortableDescriptor::encode([
                $this->commonFunding ? 'funding-publication-v1' : 'financial-publication-v1',
                $publication['id'],
                $plan->rootRun,
            ])
        );
        $signal = WorkflowStub::loadRun($plan->rootRun, Catalogue::NAMESPACE)->withRequestId($key)
            ->attemptSignal($this->commonFunding ? 'funding-milestone' : 'financial-milestone', $publication['id']);
        return [
            'publicationId' => $publication['id'],
            'notificationAccepted' => $signal->accepted(),
        ];
    }

    public function requestCapture(WorkflowRun $parent, array $value): array
    {
        [$actor, , $plan, $binding] = $this->parentBinding($parent, $value);
        if ($this->financial instanceof FundingAuthority) {
            if (! FundingCatalogue::requiresAuthorization($plan->recipe)) {
                throw new LogicException('A bank transfer or immediate payment cannot manufacture a card capture.');
            }
            $this->financial->assertForward($actor->root_key, 'order');
        }
        $id = $this->instructionId($binding);
        $row = $this->authority->db->table('provider_machine_milestones')
            ->where('id', $id)
            ->first();
        if ($row === null) {
            [$payload, $authorizationTerms] = $this->capturePayload($parent, $actor->root_key, $plan, $binding);
            $this->recordInstruction($parent, $actor->root_key, $binding, $id, $payload, $authorizationTerms);
            $row = $this->authority->db->table('provider_machine_milestones')
                ->where('id', $id)
                ->sole();
        }
        $this->readInstruction($row, $actor->root_key, $binding);
        $open = ! (bool) $this->authority->subject($actor->root_key, 'order')
->cancellation_requested;
        $signal = $open ? WorkflowStub::loadRun($binding->admission->childRunId, Catalogue::NAMESPACE)
            ->withRequestId($id)
            ->attemptSignal('capture-request', $id) : null;
        return [
            'instructionId' => $id,
            'notificationAccepted' => $signal?->accepted() ?? false,
        ];
    }

    public function businessFacts(WorkflowRun $actual, ScopeIdentity $scope, FinancialDescriptor $descriptor): array
    {
        [$actor, , $plan] = $this->payment($actual);
        if ($this->financial instanceof FundingAuthority) {
            $this->financial->inspect($actual, $scope, $descriptor);
            [, , , $rows] = $this->financial->rows($actual);
            $forward = [
                'authorize' => $rows['payment.authorize']['forward_status'],
                'capture' => $rows['payment.capture']['forward_status'],
            ];
        } else {
            $forward = $this->financial->forwardStates($actual, $scope, $descriptor);
        }
        $binding = $this->admitted($plan);
        $row = $this->authority->db->table('provider_machine_milestones')
            ->where('id', $this->instructionId($binding))
            ->first();
        return [
            'nativeForward' => $forward,
            'captureInstruction' => $row === null ? null : $this->readInstruction($row, $actor->root_key, $binding),
        ];
    }

    public function readCapture(WorkflowRun $actual, string $activityId): array
    {
        if ($this->commonFunding) {
            return app(FundingFactsStore::class)->observe($actual, $activityId);
        }
        [$actor, , $plan] = $this->payment($actual);
        [, $terms] = $this->authority->assignment($actor->root_key);
        return app(FinancialInspectionStore::class)->inspect(
            $actual,
            $activityId,
            $plan->childScope('payment')
                ->toArray(),
            $terms->financialDescriptor()
        );
    }

    public function expire(WorkflowRun $actual): array
    {
        [$actor, , $plan] = $this->authority->actor($actual);
        if (! ($this->commonFunding ? FundingCatalogue::selected($plan->recipe) : FinancialCatalogue::selected(
            $plan->recipe
        ))) {
            throw new LogicException('The original authorization policy is unavailable.');
        }
        return app(CancellationStore::class)->request(
            $actor->root_key,
            $plan->rootRun,
            $this->commonFunding ? 'funding-deadline-expired' : 'authorization-expired'
        );
    }

    public function stock(WorkflowRun $actual): array
    {
        [$actor, , $plan] = $this->authority->actor($actual);
        if ($actor->role !== 'order' || ! ($this->commonFunding ? FundingCatalogue::selected(
            $plan->recipe
        ) : FinancialCatalogue::selected($plan->recipe))) {
            throw new LogicException('Stock reconciliation requires the original financial Order.');
        }
        $request = $this->originalStock->request($actor->root_key, $plan);
        if ($request === null) {
            throw new LogicException('Stock reconciliation requires its original recorded forward execution.');
        }
        $value = $this->provider->lookup($request);
        if ($value->status === Status::Refused && $value->effectId === null) {
            $value = $this->provider->closeAdmission($request);
        }
        [, $terms] = $this->authority->assignment($actor->root_key);
        return [
            'status' => $value->status->value,
            'confirmed' => $value->status === Status::Confirmed && $value->effectId === 'effect-' . $request->identity->key(),
            'refused' => $value->status === Status::Refused && $value->effectId === null && $value->admissionClosed,
            'waitSeconds' => max(
                0,
                ($terms->authorization?->expiresAt ?? $terms->fulfillmentDeadline) - Carbon::now()->getTimestamp()
            ),
        ];
    }

    public function refuseStock(WorkflowRun $actual): array
    {
        if (! $this->stock($actual)['refused']) {
            throw new LogicException('An unknown or pending reservation cannot establish stock refusal.');
        }
        [$actor, , $plan] = $this->authority->actor($actual);
        return app(CancellationStore::class)->request($actor->root_key, $plan->rootRun, 'stock-refused');
    }

    public function prepared(WorkflowRun $parent): bool
    {
        [$actor, , $plan] = $this->authority->actor($parent);
        if ($actor->role !== 'order' || ! ($this->commonFunding ? FundingCatalogue::selected(
            $plan->recipe
        ) : FinancialCatalogue::selected($plan->recipe))) {
            throw new LogicException('Financial readiness belongs to the original Order.');
        }
        $child = $this->authority->db->table('provider_machine_actors')
            ->where('id', Authority::subjectId($actor->root_key, 'payment'))->first();
        if ($child === null || $this->originalStock->confirmed($actor->root_key, $plan) === null) {
            return false;
        }
        $stage = $plan->recipe === FinancialCatalogue::AFTER_STOCK ? 'capture' : 'authorize';
        if ($this->commonFunding) {
            $stage = $plan->recipe === FundingCatalogue::AFTER_DELIVERY || CodFundingPolicy::selected(
                $plan->recipe
            ) || AdvanceBalancePolicy::selected($plan->recipe) ? 'prepare' : 'confirm-receipt';
        }
        [, $terms] = $this->authority->assignment($actor->root_key);
        return $this->publication($actor->root_key, $this->admitted($plan), $stage) !== null
            && $this->fundingWindowOpen($terms, $plan, $stage);
    }

    public function binding(WorkflowRun $parent): array
    {
        [$actor, , $plan] = $this->authority->actor($parent);
        if ($actor->role !== 'order' || ! ($this->commonFunding ? FundingCatalogue::selected(
            $plan->recipe
        ) : FinancialCatalogue::selected($plan->recipe))) {
            throw new LogicException('The financial admission belongs to its original Order.');
        }
        return $this->admitted($plan)
            ->toArray();
    }

    private function fundingWindowOpen(OrderTerms $terms, BindingPlan $plan, string $stage): bool
    {
        return in_array($stage, ['capture', 'confirm-receipt'], true)
                || (CodFundingPolicy::selected(
                    $plan->recipe
                ) && Carbon::now()->getTimestamp() < $terms->codPayment->collectionDeadline)
                || (AdvanceBalancePolicy::selected($plan->recipe)
                    && Carbon::now()->getTimestamp() < $terms->advanceBalance->balance->collectionDeadline)
                || ($terms->authorization !== null && $terms->authorization->canAdmitCaptureAt(
                    Carbon::now()->getTimestamp()
                ));
    }

    /**
     * @return array{string, AuthorizationTerms}
     */
    private function capturePayload(WorkflowRun $parent, string $key, BindingPlan $plan, ChildBinding $binding): array
    {
        [, $terms] = $this->authority->assignment($key);
        $authorizationTerms = $terms->authorization ?? throw new LogicException(
            'The original authorization terms are unavailable.'
        );
        $authorization = $this->publication($key, $binding, $this->commonFunding ? 'prepare' : 'authorize');
        $stock = $this->originalStock->confirmed($key, $plan);
        if ($authorization === null || $stock === null) {
            throw new LogicException(
                'Capture requires original confirmed authorization and warehouse reservation.'
            );
        }
        $instruction = [
            'kind' => $this->commonFunding ? 'funding-capture-instruction-v1' : 'financial-capture-instruction-v1',
            'binding' => $binding->toArray(),
            'recipe' => $plan->recipe,
            'authorizationPublication' => $authorization['id'],
            'stockEffect' => $stock,
        ];
        if (in_array($plan->recipe, [FinancialCatalogue::AFTER_DELIVERY, FundingCatalogue::AFTER_DELIVERY], true)) {
            $delivery = app(ShipmentBoundaryStore::class)->delivery($parent);
            if ($delivery === null) {
                throw new LogicException('Capture requires the original committed recipient-delivery publication.');
            }
            $instruction['kind'] = $this->commonFunding ? 'funding-capture-instruction-v2' : 'financial-capture-instruction-v2';
            $instruction['deliveryPublication'] = $delivery['id'];
        }
        $payload = PortableDescriptor::encode($instruction);
        return [$payload, $authorizationTerms];
    }

    private function recordInstruction(
        WorkflowRun $parent,
        string $key,
        ChildBinding $binding,
        string $id,
        string $payload,
        AuthorizationTerms $terms
    ): void {
        $rootId = Authority::subjectId($key, 'order');
        $this->authority->db->transaction(function () use (
            $parent,
            $key,
            $binding,
            $id,
            $payload,
            $terms,
            $rootId
        ): void {
            $current = $this->authority->db->table('example_machine_subjects')
                ->where('id', $rootId)
                ->lockForUpdate()
                ->sole();
            $this->authority->assertGrant($current, $key, 'order');
            $entry = $binding->identity->parentExecution;
            $delivery = in_array(
                $this->authority->recipe($key),
                [FinancialCatalogue::AFTER_DELIVERY, FundingCatalogue::AFTER_DELIVERY],
                true
            );
            $state = $delivery ? 'ready' : $entry->sourceState;
            $revision = $delivery ? '2' : $entry->sourceRevision;
            if ((bool) $current->cancellation_requested || ! $terms->canAdmitCaptureAt(Carbon::now()->getTimestamp())
                || $current->state !== $state || (string) $current->revision !== $revision
                || $entry->executionId !== $parent->id . '.prepare.1') {
                throw new LogicException('The original Order refused this financial policy instruction.');
            }
            $old = $this->authority->db->table('provider_machine_milestones')
                ->where('id', $id)
                ->first();
            if ($old !== null) {
                if ($old->payload !== $payload || $old->actor_id !== $rootId || $old->child_run !== $binding->admission->childRunId) {
                    throw new LogicException('The original capture instruction changed.');
                }
                return;
            }
            $this->authority->db->table('provider_machine_milestones')
                ->insert([
                    'id' => $id,
                    'actor_id' => $rootId,
                    'child_run' => $binding->admission->childRunId,
                    'payload' => $payload,
                    'payload_sha256' => hash('sha256', $payload),
                ]);
        });
    }

    private function payment(WorkflowRun $actual): array
    {
        $value = $this->authority->actor($actual);
        if ($value[0]->role !== 'payment' || ! ($this->commonFunding
            ? FundingCatalogue::selected($value[2]->recipe) : FinancialCatalogue::selected($value[2]->recipe))) {
            throw new LogicException('The financial handoff requires the original authorized Payment actor.');
        }
        return $value;
    }

    private function parentBinding(WorkflowRun $parent, array $value): array
    {
        [$actor, $row, $plan] = $this->authority->actor($parent);
        $binding = ChildBinding::fromArray($value);
        if ($actor->role !== 'order' || ! ($this->commonFunding ? FundingCatalogue::selected(
            $plan->recipe
        ) : FinancialCatalogue::selected($plan->recipe))
            || $binding->identity->parentRunId !== $parent->id
            || $binding->toArray() !== $this->admitted($plan)
                ->toArray()) {
            throw new LogicException('The financial milestone belongs to another original Order or binding.');
        }
        return [$actor, $row, $plan, $binding];
    }

    private function admitted(BindingPlan $plan): ChildBinding
    {
        $store = app(ChildReservationStore::class);
        $reservation = $store->load(
            $plan->binding('payment'),
            $this->authority->principal,
            $plan->participation('payment')
        );
        $binding = $store->close($reservation, $this->authority->principal);
        if (! $binding instanceof ChildBinding) {
            throw new LogicException('A real original Payment admission is required for this business handoff.');
        }
        return $binding;
    }

    private function publication(string $key, ChildBinding $binding, string $stage): ?array
    {
        $route = $this->commonFunding ? [
            'prepare' => ['received', '1'],
            'collect' => ['ready', '2'],
            'confirm-receipt' => ['collected', '3'],
        ]
            : [
                'authorize' => ['received', '1'],
                'capture' => ['authorized', '2'],
            ];
        [$source, $revision] = $route[$stage] ?? throw new LogicException(
            'The financial business milestone is unavailable.'
        );
        $recipe = $this->authority->recipe($key);
        $transition = Catalogue::transition('payment', $stage, $recipe);
        $execution = new ExecutionReference(
            Catalogue::APPLICATION,
            Authority::subjectName($key, 'payment'),
            $binding->admission->childRunId . '.' . $stage . '.' . $revision,
            Catalogue::profile('payment', $recipe)->fingerprint(),
            $source,
            $revision
        );
        $id = SqlBusinessStore::decisionId($execution, 'transition', $execution->executionId);
        $row = $this->authority->db->table('example_machine_publications')
            ->where('id', $id)
            ->first();
        if ($row === null) {
            return null;
        }
        $this->authority->subject($key, 'payment');
        $payload = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
        $expected = [
            'execution' => $execution->toArray(),
            'transitionKey' => $stage,
            'state' => $transition->to,
            'revision' => (string) ((int) $revision + 1),
        ];
        if ($row->subject_id !== Authority::subjectId($key, 'payment')
            || PortableDescriptor::encode($payload) !== PortableDescriptor::encode($expected)) {
            throw new LogicException('The original financial publication or owning execution was substituted.');
        }
        return [
            'id' => $id,
            ...$expected,
        ];
    }

    private function instructionId(ChildBinding $binding): string
    {
        return hash(
            'sha256',
            ($this->commonFunding ? 'funding-capture-instruction-v1:' : 'financial-capture-instruction-v1:') . $binding->identity->key()
        );
    }

    private function readInstruction(object $row, string $key, ChildBinding $binding): array
    {
        if ($row->actor_id !== Authority::subjectId(
            $key,
            'order'
        ) || $row->child_run !== $binding->admission->childRunId
            || ! hash_equals($row->payload_sha256, hash('sha256', $row->payload))) {
            throw new LogicException('The protected original capture instruction is corrupt.');
        }
        $value = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
        $keys = array_keys($value);
        sort($keys);
        $this->authority->assignment($key);
        $stock = Request::fromArray($this->authority->cart->stockObligation($key)->input['forward']);
        $authorization = $this->publication($key, $binding, $this->commonFunding ? 'prepare' : 'authorize');
        $expected = ['authorizationPublication', 'binding', 'kind', 'recipe', 'stockEffect'];
        $kind = $this->commonFunding ? 'funding-capture-instruction-v1' : 'financial-capture-instruction-v1';
        if (in_array(
            $this->authority->recipe($key),
            [FinancialCatalogue::AFTER_DELIVERY, FundingCatalogue::AFTER_DELIVERY],
            true
        )) {
            $expected[] = 'deliveryPublication';
            sort($expected);
            $kind = $this->commonFunding ? 'funding-capture-instruction-v2' : 'financial-capture-instruction-v2';
            $delivery = app(ShipmentBoundaryStore::class)->delivery(
                WorkflowRun::query()->findOrFail($this->authority->plan($key)->rootRun)
            );
            if ($delivery === null || ($value['deliveryPublication'] ?? null) !== $delivery['id']) {
                throw new LogicException(
                    'The capture instruction substituted its original recipient-delivery publication.'
                );
            }
        }
        if ($keys !== $expected
            || ($value['kind'] ?? null) !== $kind
            || ($value['recipe'] ?? null) !== $this->authority->recipe($key)
            || PortableDescriptor::encode($value['binding'] ?? []) !== PortableDescriptor::encode($binding->toArray())
            || ($value['stockEffect'] ?? null) !== 'effect-' . $stock->identity->key()
            || $authorization === null || ($value['authorizationPublication'] ?? null) !== $authorization['id']) {
            throw new LogicException('The capture instruction substituted its original financial policy evidence.');
        }
        return [
            'id' => $row->id,
            ...$value,
        ];
    }
}
