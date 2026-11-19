<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use Closure;
use LogicException;
use Workflow\V2\Compensation\Definition\PortableDescriptor;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\StateMachine\Binding\BindingIdentity;
use Workflow\V2\StateMachine\Binding\ChildReservation;
use Workflow\V2\StateMachine\Binding\ChildScopeBindingAuthorization;
use Workflow\V2\StateMachine\Binding\ChildScopeParticipation;
use Workflow\V2\StateMachine\Execution\ExecutionReference;

/** Canonical relation for one trusted business generation; live host ownership must also be checked. */
final readonly class BindingPlan implements ChildScopeBindingAuthorization
{
    public const VERSION = 'provider-order-pair-v1';

    public const PAYMENT = 'example.provider.payment.v1';

    public const SHIPMENT = 'example.provider.booking.v1';

    public const AUTHORIZED_PAYMENT = 'example.provider.payment.authorized.v1';

    public const FUNDING_PAYMENT = 'example.provider.payment.funding.v1';

    public const COD_PAYMENT = 'example.provider.payment.cod.v1';

    public const ADVANCE_BALANCE_PAYMENT = 'example.provider.payment.advance-balance.v1';

    public const FULL_SHIPMENT = 'example.provider.shipment.full.v1';

    /**
     * @param Closure(BindingIdentity, string): void $authorizeOriginalOwner
     */
    public function __construct(
        public OrderTerms $terms,
        public string $rootRun,
        private string $principal,
        private Closure $authorizeOriginalOwner,
        public string $recipe = Catalogue::RESERVE_FIRST,
        public bool $cart = false,
        public ?\Workflow\V2\Compensation\Admission\RootReservation $rootReservation = null,
        public bool $transitRemedy = false,
    ) {
        PortableDescriptor::identifier($rootRun, 'original root run');
        PortableDescriptor::identifier($principal, 'authenticated principal');
        Catalogue::assertRecipe($recipe);
        if ($rootReservation !== null && ($rootReservation->originalRunId !== $rootRun
            || $rootReservation->identity->scope->toArray() !== (new ScopeIdentity(
                Catalogue::APPLICATION,
                Catalogue::NAMESPACE,
                $terms->subjectKey(),
                $terms->generation
            ))->toArray())) {
            throw new LogicException('The reserved root plan belongs to another original business owner.');
        }
        if ($cart && $recipe !== FundingCatalogue::BANK) {
            throw new LogicException('The cart contract requires its original bank funding profile.');
        }
    }

    public function version(): string
    {
        return self::versionFor($this->recipe) . ($this->cart ? '.cart-v1' : '')
            . ($this->rootReservation === null ? '' : '.root-v1');
    }

    public static function versionFor(string $recipe): string
    {
        Catalogue::assertRecipe($recipe);
        return match ($recipe) {
            Catalogue::RESERVE_FIRST => self::VERSION,
            Catalogue::PAYMENT_FIRST => 'provider-order-pair-checkout-payment-v1',
            Catalogue::COMMON_RESERVE_FIRST => 'provider-order-pair-checkout-reserve-v1',
            FinancialCatalogue::AFTER_STOCK => 'provider-order-pair-financial-stock-v1',
            FinancialCatalogue::AFTER_DELIVERY => 'provider-order-pair-financial-delivery-v1',
            FundingCatalogue::IMMEDIATE => 'provider-order-pair-funding-immediate-v1',
            FundingCatalogue::AFTER_STOCK => 'provider-order-pair-funding-stock-v1',
            FundingCatalogue::AFTER_DELIVERY => 'provider-order-pair-funding-delivery-v1',
            FundingCatalogue::BANK => 'provider-order-pair-funding-bank-v1',
            CodFundingPolicy::BEFORE => 'provider-order-pair-cod-before-v1',
            CodFundingPolicy::AFTER => 'provider-order-pair-cod-after-v1',
            AdvanceBalancePolicy::BEFORE => 'provider-order-pair-advance-balance-before-v1',
            AdvanceBalancePolicy::AFTER => 'provider-order-pair-advance-balance-after-v1',
        };
    }

    public function rootScope(): ScopeIdentity
    {
        return $this->rootReservation?->identity->scope ?? new ScopeIdentity(
            Catalogue::APPLICATION,
            Catalogue::NAMESPACE,
            $this->terms->subjectKey(),
            $this->rootRun
        );
    }

    public function rootInstanceId(): string
    {
        return $this->rootReservation?->identity->instanceId() ?? 'provider-order-' . $this->terms->subjectKey();
    }

    public function execution(string $role): ExecutionReference
    {
        $checkout = $this->recipe !== Catalogue::RESERVE_FIRST;
        $shipment = $checkout ? ['book', 'ready', '2'] : ['book', 'paid', '3'];
        if (FinancialCatalogue::selected($this->recipe) || FundingCatalogue::selected($this->recipe)) {
            $shipment = ['fulfill', 'ready', '2'];
        }
        [$key, $state, $revision] = match ($role) {
            'payment' => $checkout ? ['prepare', 'received', '1'] : ['pay', 'reserved', '2'],
            'shipment' => $shipment,
            default => throw new LogicException('Only the original Payment and booking children are selected.'),
        };
        return new ExecutionReference(
            Catalogue::APPLICATION,
            $this->terms->subjectKey(),
            $this->rootRun . '.' . $key . '.' . $revision,
            Catalogue::profile('order', $this->recipe, $this->transitRemedy)->fingerprint(),
            $state,
            $revision,
        );
    }

    public function childScope(string $role): ScopeIdentity
    {
        return new ScopeIdentity(
            Catalogue::APPLICATION,
            Catalogue::NAMESPACE,
            $this->terms->subjectKey() . '.' . $role,
            $this->execution($role)
->executionId,
        );
    }

    public function childInput(string $role): array
    {
        return [
            $this->terms->subjectKey(),
            $this->rootRun,
            $this->execution($role)
                ->toArray(),
            $this->childScope($role)
                ->toArray(),
        ];
    }

    public function binding(string $role): BindingIdentity
    {
        $workflowType = $this->workflowType($role);
        $parentStates = $this->recipe === Catalogue::RESERVE_FIRST
            ? [
                'payment' => 'paid',
                'shipment' => 'booked',
            ]
            : [
                'payment' => 'ready',
                'shipment' => 'booked',
            ];
        $outcome = $role === 'payment' ? 'captured' : 'booked';
        if ($role === 'payment' && FundingCatalogue::selected($this->recipe)) {
            $outcome = 'paid';
        }
        if ($role === 'shipment' && (FinancialCatalogue::selected($this->recipe) || FundingCatalogue::selected(
            $this->recipe
        ))) {
            $parentStates['shipment'] = 'fulfilled';
            $outcome = 'delivered';
        }
        $binding = new BindingIdentity(
            $this->execution($role),
            Catalogue::NAMESPACE,
            $this->rootInstanceId(),
            $this->rootRun,
            $role,
            $this->execution($role)
->executionId,
            $parentStates[$role],
            $workflowType,
            Catalogue::profile($role, $this->recipe)->fingerprint(),
            $outcome,
            ChildReservation::inputFingerprint($this->childInput($role)),
        );
        $parent = Catalogue::profile('order', $this->recipe, $this->transitRemedy);
        $parent->definition->assertBinding($parent, $binding, Catalogue::profile($role, $this->recipe));
        return $binding;
    }

    public function participation(string $role): ChildScopeParticipation
    {
        return new ChildScopeParticipation($this->rootScope(), $this->childScope($role), $role);
    }

    public function assertAllowed(BindingIdentity $identity, string $principal, string $operation): string
    {
        if ($principal !== $this->principal || ! in_array($operation, ['reserve', 'load', 'close'], true)
            || ! in_array($identity->slot, ['payment', 'shipment'], true)
            || PortableDescriptor::encode($identity->toArray()) !== PortableDescriptor::encode(
                $this->binding($identity->slot)
                    ->toArray()
            )) {
            throw new LogicException('The child binding does not belong to this original business generation.');
        }
        ($this->authorizeOriginalOwner)($identity, $operation);
        return $this->version();
    }

    public function assertParticipationAllowed(
        BindingIdentity $identity,
        ChildScopeParticipation $participation,
        string $principal,
        string $operation,
        ?string $pinnedPolicyVersion = null,
    ): string {
        // Refuse a substituted scope pair before even the live host ownership lookup.
        if (! in_array($identity->slot, ['payment', 'shipment'], true)
            || $participation->toArray() !== $this->participation($identity->slot)
                ->toArray()
            || ($pinnedPolicyVersion !== null && $pinnedPolicyVersion !== $this->version())) {
            throw new LogicException('The child scope, binding and original business owner must agree.');
        }
        return $this->assertAllowed($identity, $principal, $operation);
    }

    private function workflowType(string $role): string
    {
        return match ($role) {
            'payment' => match (true) {
                AdvanceBalancePolicy::selected($this->recipe) => self::ADVANCE_BALANCE_PAYMENT,
                CodFundingPolicy::selected($this->recipe) => self::COD_PAYMENT,
                FundingCatalogue::selected($this->recipe) => self::FUNDING_PAYMENT,
                FinancialCatalogue::selected($this->recipe) => self::AUTHORIZED_PAYMENT,
                default => self::PAYMENT,
            },
            'shipment' => FinancialCatalogue::selected($this->recipe) || FundingCatalogue::selected($this->recipe)
                ? self::FULL_SHIPMENT : self::SHIPMENT,
            default => throw new LogicException('The original child role is unavailable.'),
        };
    }
}
