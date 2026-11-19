<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use DurableWorkflow\Examples\BusinessStateMachines\SqlBusinessStore;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\CodPaymentOperation;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\CodPaymentProvider;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\FinancialOperation;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\IncomingPaymentOperation;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\IncomingPaymentProvider;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Journal;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\RetainedOperation;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\ShipmentOperation;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\UnitCaptureOperation;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\UnitPaymentOperation;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\UnitRefundGateway;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\UnitRefundOperation;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\UnitReturnOperation;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\UnitReturnTerms;
use Illuminate\Contracts\Container\Container;
use LogicException;
use Workflow\V2\Compensation\Definition\PortableDescriptor;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\Execution\HandlerRegistry;
use Workflow\V2\Compensation\Execution\OperationContext;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\Compensation\Security\ScopeAuthorization;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\StateMachine\Binding\ChildBinding;
use Workflow\V2\StateMachine\Binding\ChildDefinitionRegistry;
use Workflow\V2\StateMachine\Binding\ChildReservationStore;
use Workflow\V2\StateMachine\Execution\ExecutionReference;

/** Application assembly and native ownership. All methods are host/activity boundaries, never guards. */
final readonly class MachineStore
{
    public function __construct(
        public Authority $authority,
        private ?IncomingPaymentProvider $incoming = null,
        private ?CodPaymentProvider $cod = null,
        private ?OrderBillingPeriodStore $periods = null,
        private ?UnitRefundGateway $unitRefunds = null,
        private ?StockWarehouse $warehouse = null,
    ) {
        if (($authority->unitMoney->grants() !== []) !== ($unitRefunds !== null)) {
            throw new LogicException('Unit-money workers require their exact original refund-provider assembly.');
        }
        if (($authority->stock->grants() !== []) !== ($warehouse !== null)) {
            throw new LogicException('Partial-stock workers require their exact original warehouse assembly.');
        }
        if (($authority->periods->grants() !== [] && $periods === null)
            || ($periods !== null && $periods->authority !== $authority)) {
            throw new LogicException('Order-period workers must retain their original policy and admission assembly.');
        }
    }

    public static function configure(
        Container $app,
        Authority $authority,
        Journal $provider,
        ?IncomingPaymentProvider $incoming = null,
        ?CodPaymentProvider $cod = null,
        ?UnitRefundGateway $unitRefunds = null,
        ?StockWarehouse $warehouse = null
    ): self {
        $unitRefunds?->assertProvider($provider);
        if ($incoming !== null && $incoming->commands !== $provider) {
            throw new LogicException('Incoming receipts and refunds must use the same original provider assembly.');
        }
        if ($cod !== null && ($cod->commands !== $provider || ! $cod->physicalContract)) {
            throw new LogicException(
                'Native COD requires the same original provider and atomic physical carrier contract.'
            );
        }
        $periods = $authority->periods->grants() === [] ? null : new OrderBillingPeriodStore($authority, $provider);
        $store = new self($authority, $incoming, $cod, $periods, $unitRefunds, $warehouse);
        $app->instance(self::class, $store);
        if ($unitRefunds !== null) {
            $app->instance(ReturnStore::class, new ReturnStore($authority, $provider, $unitRefunds));
            $app->instance(ExchangeStore::class, new ExchangeStore($authority, $provider, $unitRefunds));
        }
        if ($warehouse !== null && $unitRefunds !== null) {
            $app->instance(
                StockSelectionStore::class,
                new StockSelectionStore($authority, $provider, $warehouse, $unitRefunds)
            );
        }
        if ($periods !== null) {
            $app->instance(OrderBillingPeriodStore::class, $periods);
        }
        $app->instance(CartStore::class, new CartStore($authority, $provider, $incoming));
        $app->instance(AssessmentStore::class, new AssessmentStore($authority, $provider));
        $financial = new FinancialAuthority($authority);
        $handoff = new FinancialHandoffStore($authority, $provider, $financial);
        $app->instance(FinancialHandoffStore::class, $handoff);
        $funding = new FundingAuthority($authority);
        $fundingHandoff = new FundingHandoffStore($authority, $provider, $funding);
        $app->instance(FundingHandoffStore::class, $fundingHandoff);
        $app->instance(FundingFactsStore::class, new FundingFactsStore($funding, $provider));
        $app->instance(FinancialInspectionStore::class, new FinancialInspectionStore(
            $authority->db,
            $provider,
            static function (WorkflowRun $actual, ScopeIdentity $scope, $descriptor) use ($financial, $funding): void {
                $selected = $actual->workflow_class === FundingPayment::class ? $funding : $financial;
                $selected->inspect($actual, $scope, $descriptor);
            },
            static fn (WorkflowRun $actual, ScopeIdentity $scope, $descriptor): array =>
                $actual->workflow_class === FundingPayment::class
                    ? $fundingHandoff->businessFacts($actual, $scope, $descriptor)
                    : $handoff->businessFacts($actual, $scope, $descriptor)
        ));
        if ($incoming !== null) {
            $app->instance(IncomingPaymentInspectionStore::class, new IncomingPaymentInspectionStore(
                $authority->db,
                $incoming->receipts,
                $funding->inspectIncoming(...)
            ));
        }
        if ($cod !== null) {
            $app->instance(
                CodInspectionStore::class,
                new CodInspectionStore($authority->db, $cod, $funding->inspectCod(...))
            );
        }
        $app->instance(CancellationStore::class, new CancellationStore($authority));
        $physical = new ShipmentAuthority($authority);
        $app->instance(ShipmentBoundaryStore::class, new ShipmentBoundaryStore($authority, $provider, $physical));
        $app->instance(ChildReservationStore::class, new ChildReservationStore($authority));
        $app->instance(ChildDefinitionRegistry::class, new ChildDefinitionRegistry([
            BindingPlan::FULL_SHIPMENT => [
                PhysicalCatalogue::profile(FinancialCatalogue::AFTER_STOCK)->fingerprint() => FullShipment::class,
                PhysicalCatalogue::profile(FinancialCatalogue::AFTER_DELIVERY)->fingerprint() => FullShipment::class,
                ...array_fill_keys(array_map(static fn (string $recipe): string =>
                    FundingCatalogue::shipment(
                        $recipe
                    )->fingerprint(), FundingCatalogue::recipes()), FullShipment::class),
            ],
            BindingPlan::FUNDING_PAYMENT => array_fill_keys(array_map(static fn (string $recipe): string =>
                FundingCatalogue::payment(
                    $recipe
                )->fingerprint(), FundingCatalogue::baseRecipes()), FundingPayment::class),
            BindingPlan::COD_PAYMENT => array_fill_keys(array_map(static fn (string $recipe): string =>
                FundingCatalogue::payment(
                    $recipe
                )->fingerprint(), CodFundingPolicy::recipes()), CodFundingPayment::class),
            BindingPlan::ADVANCE_BALANCE_PAYMENT => array_fill_keys(array_map(static fn (string $recipe): string =>
                FundingCatalogue::payment(
                    $recipe
                )->fingerprint(), AdvanceBalancePolicy::recipes()), AdvanceBalancePayment::class),
            BindingPlan::AUTHORIZED_PAYMENT => [
                FinancialCatalogue::profile(FinancialCatalogue::AFTER_STOCK)->fingerprint() => AuthorizedPayment::class,
                FinancialCatalogue::profile(
                    FinancialCatalogue::AFTER_DELIVERY
                )->fingerprint() => AuthorizedPayment::class,
            ],
            BindingPlan::PAYMENT => [
                Catalogue::profile('payment')->fingerprint() => Payment::class,
            ],
            BindingPlan::SHIPMENT => [
                Catalogue::profile('shipment')->fingerprint() => Booking::class,
            ],
        ]));
        $app->instance(ScopeAuthorization::class, new class($authority) implements ScopeAuthorization {
            public function __construct(
                private readonly Authority $authority
            ) {
            }

            public function assertAllowed(ScopeIdentity $scope, string $principal, string $operation): string
            {
                try {
                    return $this->authority->authorizeScope($scope, $principal, $operation);
                } catch (\LogicException $refused) {
                    // Policy refusals are permanent; other failures stay retryable.
                    throw new \Workflow\V2\Compensation\Security\ScopeAuthorizationRefused(
                        $refused->getMessage(),
                        $refused
                    );
                }
            }
        });
        if ($authority->roots->grants() !== []) {
            $app->instance(RootCancellationStore::class, new RootCancellationStore($authority));
            $app->instance(
                \Workflow\V2\Compensation\Admission\RootReservationStore::class,
                new \Workflow\V2\Compensation\Admission\RootReservationStore($authority->roots, $app->make(
                    ScopeAuthorization::class
                ))
            );
        }
        $app->instance(
            HandlerRegistry::class,
            new HandlerRegistry($store->handlers($provider, $financial, $funding, $physical))
        );
        return $store;
    }

    public function seed(OrderTerms $terms, ?CartPlan $cart = null): void
    {
        $key = $terms->subjectKey();
        $this->authority->authorize($key, $this->authority->principal);
        $recipe = $this->authority->recipe($key);
        if ($this->authority->cart->selected($key) !== ($cart !== null)) {
            throw new LogicException('The native cart entry requires its exact trusted original cart assignment.');
        }
        $cart?->assertOrder($terms);
        $this->assertAdvanceBalance($terms, $recipe);
        $this->assertFundingProviders($terms, $recipe);
        if ($this->authority->unitMoney->selected($key)) {
            $this->authority->unitMoney->original($terms);
        }
        if ($this->authority->stock->selected($key)
            && $this->authority->stock->policy(
                $key
            ) === StockAllocation::WAIT && $terms->fulfillmentDeadline === null) {
            throw new LogicException('Waiting for the complete order requires its original fixed deadline.');
        }
        $rootIntent = $this->authority->roots->createIntent($terms);
        $payload = PortableDescriptor::encode($terms->toArray());
        $this->authority->db->transaction(function () use ($key, $payload, $terms, $cart, $rootIntent): void {
            $this->authority->db->table('provider_machine_assignments')
                ->insert([
                    'id' => $key,
                    'terms' => $payload,
                    'terms_sha256' => hash('sha256', $payload),
                    'root_run' => null,
                ]);
            $this->createSubject($key, 'order', false);
            $this->authority->roots->seedRow($terms, $rootIntent);
            $this->periods?->seedRow($terms);
            if ($cart !== null) {
                app(CartStore::class)->seedRow($terms, $cart, hash('sha256', $payload));
            }
        });
    }

    public function initialize(WorkflowRun $actual, array $arguments): array
    {
        if ($actual->namespace !== Catalogue::NAMESPACE) {
            throw new LogicException('The native initialization is outside this business namespace.');
        }
        $role = Authority::role($actual->workflow_class);
        $args = $actual->workflowArguments();
        if (PortableDescriptor::encode($args) !== PortableDescriptor::encode($arguments)) {
            throw new LogicException('The recorded workflow arguments differ from original native admission.');
        }
        $key = $args[0] ?? '';
        if (! is_string($key) || $actual->namespace !== Catalogue::NAMESPACE) {
            throw new LogicException('The native initialization has no authorized business subject.');
        }
        $this->authority->authorize($key, $this->authority->principal);
        $this->periods?->assertActor($actual, $key);
        [$assignment, $terms] = $this->authority->assignment($key);
        $root = $this->authority->subject($key, 'order');
        $plan = $role === 'order' ? $this->rootPlan($actual, $args, $key, $assignment, $terms)
            : $this->childPlan($actual, $args, $key, $role);
        $scope = $role === 'order' ? $plan->rootScope() : $plan->childScope($role);
        $this->persistActor($root, $key, $role, $actual, $scope);
        $this->authority->actor($actual);
        $children = [];
        if ($role === 'order') {
            $roles = ['payment', 'shipment'];
            $children = array_map(static fn (string $child): array => [
                'binding' => $plan->binding($child)
                    ->toArray(),
                'scope' => $plan->childScope($child)
                    ->toArray(),
                'input' => $plan->childInput($child),
            ], $roles);
        }
        return $this->withRecipe([
            'key' => $key,
            'role' => $role,
            'rootRun' => $plan->rootRun,
            'nativeRun' => $actual->id,
            'principal' => $this->authority->principal,
            'scope' => $scope->toArray(),
            'descriptor' => $this->descriptor($terms, $role, $this->authority->recipe($key)),
            'fulfillmentDeadline' => $terms->fulfillmentDeadline,
            'children' => $children,
        ], $key);
    }

    public function execution(WorkflowRun $actual, string $key): array
    {
        [$actor, $row] = $this->authority->actor($actual);
        if ($actual->workflow_class === Cancellation::class && $key !== 'cancel') {
            throw new LogicException('A proof-only finalizer cannot take over original forward work.');
        }
        Catalogue::transition($actor->role, $key, $this->authority->recipe($actor->root_key));
        return (new ExecutionReference(
            $row->scope,
            $row->subject,
            $actual->id . '.' . $key . '.' . $row->revision,
            $this->authority->profile($actor->root_key, $actor->role)
                ->fingerprint(),
            $row->state,
            (string) $row->revision,
        ))->toArray();
    }

    public function initializeFinalizer(WorkflowRun $actual, array $arguments): array
    {
        [$actor, , $plan] = $this->authority->actor($actual);
        if ($actual->workflow_class !== Cancellation::class || PortableDescriptor::encode(
            $arguments
        ) !== PortableDescriptor::encode($actual->workflowArguments())) {
            throw new LogicException('Only the exact original cancellation finalizer may initialize here.');
        }
        return $this->withRecipe([
            'key' => $actor->root_key,
            'role' => 'order',
            'rootRun' => $plan->rootRun,
            'nativeRun' => $actual->id,
            'principal' => $this->authority->principal,
            'scope' => $plan->rootScope()
                ->toArray(),
            'descriptor' => [],
            'children' => [],
        ], $actor->root_key);
    }

    /**
     * A completed child must supply its original protected business publication, not just a terminal status.
     */
    public function observe(WorkflowRun $actual, array $value, array $outcome): array
    {
        [$actor, $row, $plan] = $this->authority->actor($actual);
        $binding = ChildBinding::fromArray($value);
        $role = $binding->identity->slot;
        $this->authority->assertAllowed($binding->identity, $this->authority->principal, 'close');
        if ($actor->role !== 'order' || $binding->identity->parentRunId !== $actual->id
            || ! in_array($role, ['payment', 'shipment'], true)) {
            throw new LogicException('The child milestone belongs to another original parent.');
        }
        $reservation = app(ChildReservationStore::class)->load(
            $plan->binding($role),
            $this->authority->principal,
            $plan->participation($role)
        );
        $admitted = app(ChildReservationStore::class)->close($reservation, $this->authority->principal);
        if (! $admitted instanceof ChildBinding || $admitted->toArray() !== $binding->toArray()) {
            throw new LogicException('The child milestone differs from its authoritative native admission.');
        }
        $execution = new ExecutionReference(
            Catalogue::APPLICATION,
            Authority::subjectName($actor->root_key, $role),
            $binding->admission->childRunId . '.' . ($role === 'payment' ? 'capture' : 'book') . '.1',
            Catalogue::profile($role)->fingerprint(),
            'received',
            '1',
        );
        $publication = $this->authority->db->table('example_machine_publications')
            ->where('id', SqlBusinessStore::decisionId($execution, 'transition', $execution->executionId))->sole();
        $published = json_decode($publication->payload, true, flags: JSON_THROW_ON_ERROR);
        if ($publication->subject_id !== Authority::subjectId($actor->root_key, $role)
            || PortableDescriptor::encode($published['execution'] ?? []) !== PortableDescriptor::encode(
                $execution->toArray()
            )
            || ($published['state'] ?? null) !== $binding->identity->requiredOutcome
            || ($published['revision'] ?? null) !== '2'
            || ($outcome['businessOutcome'] ?? null) !== $binding->identity->requiredOutcome
            || ($outcome['originalRunId'] ?? null) !== $binding->admission->childRunId
            || ($outcome['publicationId'] ?? null) !== $publication->id) {
            throw new LogicException('Technical completion is not the original confirmed business milestone.');
        }
        $payload = PortableDescriptor::encode([
            'binding' => $binding->toArray(),
            'publication' => $published,
        ]);
        $this->authority->db->transaction(function () use ($binding, $row, $payload): void {
            $current = $this->authority->db->table('example_machine_subjects')
                ->where('id', $row->id)
                ->lockForUpdate()
                ->sole();
            $this->authority->assertGrant($current, $binding->identity->parentExecution->subject, 'order');
            $record = $this->authority->db->table('provider_machine_milestones')
                ->where('id', $binding->identity->key())
                ->first();
            if ($record !== null) {
                if ($record->actor_id !== $row->id || $record->child_run !== $binding->admission->childRunId
                    || $record->payload !== $payload || $record->payload_sha256 !== hash('sha256', $payload)) {
                    throw new LogicException('The original child publication changed.');
                }
                return;
            }
            $this->authority->db->table('provider_machine_milestones')
                ->insert([
                    'id' => $binding->identity->key(),
                    'actor_id' => $row->id,
                    'child_run' => $binding->admission->childRunId,
                    'payload' => $payload,
                    'payload_sha256' => hash('sha256', $payload),
                ]);
        });
        return $published;
    }

    public function verifyStep(WorkflowRun $actual, array $value, string $key): array
    {
        $execution = Authority::reference($value);
        [$actor, , $plan] = $this->authority->actor($actual, $execution);
        Catalogue::transition($actor->role, $key, $this->authority->recipe($actor->root_key));
        if ($execution->executionId !== $actual->id . '.' . $key . '.' . $execution->sourceRevision) {
            throw new LogicException('Step compensation requires its original transition identity.');
        }
        $scope = $actor->role === 'order' ? $plan->rootScope() : $plan->childScope($actor->role);
        $metadata = (new ScopeRepository())->inspect($scope);
        if ($metadata['original_run_id'] !== $actual->id || ! in_array(
            $metadata['status'],
            ['requested', 'verified'],
            true
        )) {
            throw new LogicException('The original forward gate must be permanently closed.');
        }
        $obligations = $actor->role === 'order' ? match ($key) {
            'reserve' => ['effect'], 'pay' => ['payment'], 'book' => ['shipment'],
            'prepare' => ['payment', 'effect', StockPolicy::SHORTAGE_KEY],
            'fulfill' => ['shipment'],
            default => throw new LogicException('The proof-only transition cannot own provider work.'),
        } : ['effect'];
        foreach ($metadata['obligations'] as $item) {
            if (in_array(
                $item['descriptor']['key'],
                $obligations,
                true
            ) && $item['compensation_status'] !== 'verified') {
                throw new LogicException('An original transition obligation remains unresolved.');
            }
        }
        return [
            'execution' => $execution->toArray(),
            'verified' => true,
        ];
    }

    /**
     * @return array<string, array<string, \Workflow\V2\Compensation\Execution\ScopedOperation>>
     */
    private function handlers(
        Journal $provider,
        FinancialAuthority $financial,
        FundingAuthority $funding,
        ShipmentAuthority $physical
    ): array {
        $authority = $this->authority;
        $incoming = $this->incoming;
        $cod = $this->cod;
        $periods = $this->periods;
        $handlers = [
            'provider.owner' => [
                '1' => new OwnerOperation($authority),
            ],
            'provider.effect' => [
                '1' => new RetainedOperation($provider, static function (
                    OperationContext $context,
                    string $operation,
                ) use ($authority, $funding, $periods): void {
                    $key = explode('.', $context->scope->key)[0];
                    if (FundingCatalogue::selected($authority->recipe($key))) {
                        $funding->effect($context, $operation);
                    } else {
                        $authority->authorizeOperation($context, $operation);
                    }
                    $periods?->authorizeEffect($context, $operation);
                }),
            ],
            'provider.cart' => [
                '1' => new CartLeaseOperation($provider, app(CartStore::class)->operation(...)),
            ],
            'provider.shipment.full' => [
                '1' => new ShipmentOperation($provider, static function (
                    OperationContext $context,
                    string $mode,
                ) use ($physical, $periods): void {
                    $physical->operation($context, $mode);
                    $periods?->authorizeEffect($context, $mode);
                }),
            ],
        ];
        if ($this->unitRefunds !== null) {
            $handlers['provider.unit-payment'] = [
                '1' => new UnitPaymentOperation($provider, $this->unitRefunds, $authority->unitMoney->operation(...)),
            ];
        }
        if ($this->unitRefunds !== null) {
            $handlers['provider.financial.unit-capture'] = [
                '1' => new UnitCaptureOperation($provider, $this->unitRefunds, static function (
                    OperationContext $context,
                    string $mode
                ) use ($financial): void {
                    $financial->operation($context, $mode, 'capture');
                }),
            ];
            $returns = app(ReturnStore::class);
            $handlers[ReturnStore::REQUEST_HANDLER] = [
                '1' => new UnitReturnOperation($provider, $returns->operation(...), UnitReturnTerms::REQUEST),
            ];
            $handlers[ReturnStore::RESTOCK_HANDLER] = [
                '1' => new UnitReturnOperation($provider, $returns->operation(...), UnitReturnTerms::RESTOCK),
            ];
            $handlers[ReturnStore::REFUND_HANDLER] = [
                '1' => new UnitRefundOperation($provider, $this->unitRefunds, $returns->operation(...)),
            ];
            $exchanges = app(ExchangeStore::class);
            $handlers[ExchangeStore::REQUEST_HANDLER] = [
                '1' => new UnitReturnOperation($provider, $exchanges->operation(...), UnitReturnTerms::REQUEST),
            ];
            $handlers[ExchangeStore::RESTOCK_HANDLER] = [
                '1' => new UnitReturnOperation($provider, $exchanges->operation(...), UnitReturnTerms::RESTOCK),
            ];
            $handlers[ExchangeStore::RESERVE_HANDLER] = [
                '1' => new RetainedOperation($provider, $exchanges->operation(...)),
            ];
            $handlers[ExchangeStore::BOOK_HANDLER] = [
                '1' => new ShipmentOperation($provider, $exchanges->operation(...)),
            ];
            $handlers[ExchangeStore::REFUND_HANDLER] = [
                '1' => new UnitRefundOperation($provider, $this->unitRefunds, $exchanges->operation(...)),
            ];
        }
        if ($this->warehouse !== null && $this->unitRefunds !== null) {
            $selection = app(StockSelectionStore::class);
            $handlers[StockPolicy::HANDLER] = [
                '1' => new StockOperation($provider, $this->warehouse, $selection->operation(...)),
            ];
            $handlers[StockPolicy::SHORTAGE_HANDLER] = [
                '1' => new UnitRefundOperation($provider, $this->unitRefunds, $selection->refund(...)),
            ];
        }
        if ($incoming !== null) {
            $handlers['provider.incoming'] = [
                '1' => new IncomingPaymentOperation($incoming, $funding->incoming(...)),
            ];
        }
        if ($cod !== null) {
            foreach (['merchant', 'payer'] as $stage) {
                $handlers['provider.cod.' . $stage] = [
                    '1' => new CodPaymentOperation($cod, $funding->cod(...), $stage),
                ];
            }
        }
        foreach (['authorize', 'capture'] as $stage) {
            $handlers['provider.financial.' . $stage] = [
                '1' => new FinancialOperation($provider, static function (
                    OperationContext $context,
                    string $mode
                ) use ($financial, $funding, $authority, $stage): void {
                    $key = explode('.', $context->scope->key)[0];
                    $selected = FundingCatalogue::selected($authority->recipe($key)) ? $funding : $financial;
                    $selected->operation($context, $mode, $stage);
                }, $stage),
            ];
        }
        return $handlers;
    }

    private function descriptor(OrderTerms $terms, string $role, string $recipe): array
    {
        if ($role === 'payment' && $this->authority->unitMoney->selected($terms->subjectKey())
            && ! FinancialCatalogue::selected($recipe)) {
            return $this->authority->unitMoney->obligation($terms->subjectKey())
->input;
        }
        if ($role === 'shipment' && $this->authority->stock->selected($terms->subjectKey())) {
            return $this->authority->stock->descriptor($terms, $role);
        }
        if ($role === 'order' && $this->authority->cart->selected($terms->subjectKey())) {
            return $this->authority->cart->stockObligation($terms->subjectKey())
->input;
        }
        if ((FinancialCatalogue::selected($recipe) || FundingCatalogue::selected($recipe)) && $role !== 'order') {
            return match ($role) {
                'shipment' => $terms->physicalDescriptor(),
                'payment' => match (true) {
                    AdvanceBalancePolicy::selected($recipe) => $terms->advanceBalanceDescriptor(),
                    CodFundingPolicy::selected($recipe) => $terms->codDescriptor(),
                    $recipe === FundingCatalogue::BANK => $terms->incomingDescriptor(),
                    FinancialCatalogue::selected($recipe) || FundingCatalogue::requiresAuthorization(
                        $recipe
                    ) => $terms->financialDescriptor(),
                    default => $terms->descriptor('payment'),
                },
                default => $terms->descriptor($role),
            };
        }
        return $terms->descriptor($role);
    }

    private function rootPlan(
        WorkflowRun $actual,
        array $args,
        string $key,
        object $assignment,
        OrderTerms $terms
    ): BindingPlan {
        $reservation = $this->authority->roots->selected($key)
            ? $this->authority->roots->reservationFor($key, $actual->id) : null;
        $expectedInstance = $reservation?->identity->instanceId() ?? 'provider-order-' . $key;
        $instance = WorkflowInstance::query()->findOrFail($actual->workflow_instance_id);
        if ($actual->workflow_class !== ($reservation?->identity->workflowClass ?? $this->authority->cart->rootClass(
            $key
        ))
            || $args !== [$key] || $actual->workflow_instance_id !== $expectedInstance
            || (int) $instance->run_count !== 1 || $instance->current_run_id !== $actual->id
            || ($assignment->root_run !== null && $assignment->root_run !== $actual->id)) {
            throw new LogicException('This example does not transfer its original Order to a successor.');
        }
        return new BindingPlan($terms, $actual->id, $this->authority->principal, static function (): void {
            throw new LogicException('Initialization does not authorize child admission.');
        }, $this->authority->recipe($key), $this->authority->cart->selected($key), $reservation);
    }

    private function childPlan(WorkflowRun $actual, array $args, string $key, string $role): BindingPlan
    {
        $plan = $this->authority->plan($key);
        $scope = $plan->childScope($role);
        if (PortableDescriptor::encode($args) !== PortableDescriptor::encode($plan->childInput($role))
            || $actual->workflow_instance_id !== 'dw-bound-child-' . $plan->binding($role)->key()) {
            throw new LogicException('Child initialization differs from its exact original input.');
        }
        $reservations = app(ChildReservationStore::class);
        $reservation = $reservations->load(
            $plan->binding($role),
            $this->authority->principal,
            $plan->participation($role)
        );
        $binding = $reservations->close($reservation, $this->authority->principal);
        if (! $binding instanceof ChildBinding || $binding->admission->childRunId !== $actual->id
            || (new ScopeRepository())->metadata($scope)['original_run_id'] !== $actual->id) {
            throw new LogicException('A genuine original native admission and child scope are required.');
        }
        return $plan;
    }

    private function persistActor(
        object $root,
        string $key,
        string $role,
        WorkflowRun $actual,
        ScopeIdentity $scope
    ): void {
        $this->authority->db->transaction(function () use ($root, $key, $role, $actual, $scope): void {
            $current = $this->authority->db->table('example_machine_subjects')
                ->where('id', $root->id)
                ->lockForUpdate()
                ->sole();
            $this->authority->assertGrant($current, $key, 'order');
            $assignment = $this->authority->db->table('provider_machine_assignments')
                ->where('id', $key)
                ->sole();
            if ($role === 'order') {
                $this->assignOriginalRoot($key, $actual, $current, $assignment);
            }
            $id = Authority::subjectId($key, $role);
            $existing = $this->authority->db->table('provider_machine_actors')
                ->where('id', $id)
                ->first();
            if ($existing !== null) {
                if ($existing->root_key !== $key || $existing->role !== $role || $existing->native_run !== $actual->id
                    || $existing->scope !== PortableDescriptor::encode($scope->toArray())) {
                    throw new LogicException('The immutable original machine actor changed.');
                }
                return;
            }
            if ($role !== 'order') {
                $this->createSubject($key, $role, (bool) $current->cancellation_requested);
            }
            $this->authority->db->table('provider_machine_actors')
                ->insert([
                    'id' => $id,
                    'root_key' => $key,
                    'role' => $role,
                    'native_run' => $actual->id,
                    'scope' => PortableDescriptor::encode($scope->toArray()),
                ]);
        });
    }

    private function assignOriginalRoot(string $key, WorkflowRun $actual, object $current, object $assignment): void
    {
        if ($this->authority->roots->selected($key) && $assignment->root_run === null
            && $current->state === 'cancelled' && (bool) $current->cancellation_requested) {
            throw new LogicException('The original startup cancellation is already committed.');
        }
        if ($assignment->root_run !== null && $assignment->root_run !== $actual->id) {
            throw new LogicException('The original business generation has another native owner.');
        }
        $this->authority->db->table('provider_machine_assignments')
            ->where('id', $key)
            ->update([
                'root_run' => $actual->id,
            ]);
    }

    private function createSubject(string $key, string $role, bool $cancelled): void
    {
        $this->authority->db->table('example_machine_subjects')
            ->insert([
                'id' => Authority::subjectId($key, $role),
                'scope' => Catalogue::APPLICATION,
                'subject' => Authority::subjectName($key, $role),
                'principal' => $this->authority->principal,
                'authority_version' => $this->authority->grantFor($key, $role),
                'state' => 'received',
                'revision' => 1,
                'cancellation_requested' => $cancelled,
            ]);
    }

    private function withRecipe(array $initialization, string $key): array
    {
        if ($initialization['role'] === 'payment' && $this->authority->unitMoney->authorized($key)) {
            // Legacy authorization initializations lack this field and register the original capture.
            $obligation = $this->authority->unitMoney->obligation($key);
            $initialization['capture'] = [
                'handler' => $obligation->handlerType,
                'input' => $obligation->input,
            ];
        } elseif ($initialization['role'] === 'payment' && $this->authority->unitMoney->selected($key)) {
            $initialization['effectHandler'] = 'provider.unit-payment';
        }
        if ($initialization['role'] === 'order' && $this->authority->stock->selected($key)) {
            // Legacy initializations lack both fields and keep their original recorded command sequence.
            $initialization['stockHandler'] = StockPolicy::HANDLER;
            $initialization['stockPolicy'] = $this->authority->stock->policy($key);
        }
        $recipe = $this->authority->recipe($key);
        if ($recipe !== Catalogue::RESERVE_FIRST) {
            $initialization['recipe'] = $recipe;
        }
        return $initialization;
    }

    private function assertAdvanceBalance(OrderTerms $terms, string $recipe): void
    {
        if (($terms->advanceBalance !== null) !== AdvanceBalancePolicy::selected($recipe)
            || ($terms->advanceBalance !== null && ($this->incoming === null || $this->cod === null
                || ! $this->cod->physicalContract
                || $terms->advanceBalance->balance->handoverPolicy !== AdvanceBalancePolicy::handover($recipe)))) {
            throw new LogicException('The selected native recipe does not implement the original advance and balance.');
        }
    }

    private function assertFundingProviders(OrderTerms $terms, string $recipe): void
    {
        if (($terms->codPayment !== null) !== CodFundingPolicy::selected($recipe)
            || ($terms->codPayment !== null && ($this->cod === null
                || $terms->codPayment->handoverPolicy !== CodFundingPolicy::handover($recipe)))) {
            throw new LogicException(
                'Native COD requires its original provider, recipe and recipient-handover policy.'
            );
        }
        if (FundingCatalogue::selected($recipe) && $terms->fulfillmentDeadline === null) {
            throw new LogicException('Common funding checkout requires its original fulfillment deadline.');
        }
        if (($terms->incomingPayment !== null) !== ($recipe === FundingCatalogue::BANK)
            || ($recipe === FundingCatalogue::BANK && $this->incoming === null)) {
            throw new LogicException('Only the original bank policy may use its configured incoming-payment provider.');
        }
        if ($recipe === FinancialCatalogue::AFTER_DELIVERY && $terms->fulfillmentDeadline === null) {
            throw new LogicException(
                'The selected delivery policy requires its original physical fulfillment deadline.'
            );
        }
        if (($terms->authorization !== null) !== (FinancialCatalogue::selected($recipe)
            || (FundingCatalogue::selected($recipe) && FundingCatalogue::requiresAuthorization($recipe)))) {
            throw new LogicException('The selected native recipe does not implement authorization and capture.');
        }
    }
}
