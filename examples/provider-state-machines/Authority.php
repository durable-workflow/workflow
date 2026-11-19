<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use DurableWorkflow\Examples\BusinessStateMachines\SqlBusinessStore;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use LogicException;
use Workflow\V2\Compensation\Definition\PortableDescriptor;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\Execution\OperationContext;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\StateMachine\Binding\BindingIdentity;
use Workflow\V2\StateMachine\Binding\ChildScopeBindingAuthorization;
use Workflow\V2\StateMachine\Binding\ChildScopeParticipation;
use Workflow\V2\StateMachine\Definition\PolicyProfile;
use Workflow\V2\StateMachine\Execution\ExecutionReference;

/** Trusted bootstrap owns the principal and business grants; workflow arguments never grant access. */
final readonly class Authority implements ChildScopeBindingAuthorization
{
    public CartPolicy $cart;

    public OrderPeriodPolicy $periods;

    public RootPolicy $roots;

    public UnitMoneyPolicy $unitMoney;

    public StockPolicy $stock;

    /**
     * @param list<string> $subjects Canonical OrderTerms::subjectKey() grants.
     */
    public function __construct(
        public ConnectionInterface $db,
        public string $principal,
        private array $subjects,
        private array $recipes = [],
        array $cartPurchases = [],
        array $orderPeriods = [],
        array $reservedRoots = [],
        array $unitAmounts = [],
        array $stockPolicies = [],
    ) {
        PortableDescriptor::identifier($principal, 'authenticated principal');
        if (! array_is_list($subjects)) {
            throw new LogicException('The worker needs an explicit business subject grant list.');
        }
        foreach ($subjects as $subject) {
            if (! is_string($subject) || preg_match('/\A[0-9a-f]{64}\z/D', $subject) !== 1) {
                throw new LogicException('Business grants must use canonical client/subject/generation identities.');
            }
        }
        foreach ($recipes as $subject => $recipe) {
            if (! in_array($subject, $subjects, true) || ! is_string($recipe)) {
                throw new LogicException('Policy selection requires an existing trusted subject grant.');
            }
            Catalogue::assertRecipe($recipe);
        }
        if (! array_is_list($cartPurchases)) {
            throw new LogicException('Cart purchase authority must be an explicit trusted grant list.');
        }
        foreach ($cartPurchases as $key) {
            if (! is_string($key) || ! in_array($key, $subjects, true) || $this->recipe(
                $key
            ) !== FundingCatalogue::BANK) {
                throw new LogicException('Cart checkout requires an existing original bank purchase grant.');
            }
        }
        $this->cart = new CartPolicy($this, $cartPurchases);
        $this->periods = new OrderPeriodPolicy($this, $orderPeriods);
        $this->roots = new RootPolicy($this, $reservedRoots);
        $this->unitMoney = new UnitMoneyPolicy($this, $unitAmounts, $subjects);
        $this->stock = new StockPolicy($this, $stockPolicies);
    }

    public function recipe(string $key): string
    {
        return $this->recipes[$key] ?? Catalogue::RESERVE_FIRST;
    }

    public function profile(string $key, string $role): PolicyProfile
    {
        return Catalogue::profile($role, $this->recipe($key));
    }

    public function grantFor(string $key, string $role): string
    {
        return hash(
            'sha256',
            BindingPlan::versionFor($this->recipe($key)) . ($this->cart->selected($key) ? '.cart-v1' : '')
                . $this->periods->version($key) . $this->roots->version($key)
                . $this->unitMoney->version($key) . $this->stock->version($key)
                . ':' . $this->profile($key, $role)->fingerprint()
        );
    }

    public function authorize(string $key, string $principal): void
    {
        if ($this->db->transactionLevel() !== 0 || $principal !== $this->principal
            || ! in_array($key, $this->subjects, true)) {
            throw new LogicException('The original business generation is outside this authenticated host grant.');
        }
    }

    public function assignment(string $key): array
    {
        $this->authorize($key, $this->principal);
        // The immutable subject authority pins the bootstrap-selected recipe before protected terms decode.
        $this->subject($key, 'order');
        $row = $this->db->table('provider_machine_assignments')
            ->where('id', $key)
            ->sole();
        if (! hash_equals($row->terms_sha256, hash('sha256', $row->terms))) {
            throw new LogicException('The pinned original business terms changed.');
        }
        $terms = OrderTerms::fromArray(json_decode($row->terms, true, flags: JSON_THROW_ON_ERROR));
        if ($terms->subjectKey() !== $key) {
            throw new LogicException('The original client, subject or generation was substituted.');
        }
        if ($this->cart->selected($key)) {
            app(CartStore::class)->assertTerms($key, $terms, $row->terms_sha256);
        }
        return [$row, $terms];
    }

    public function plan(string $key): BindingPlan
    {
        [$row, $terms] = $this->assignment($key);
        if (! is_string($row->root_run) || $row->root_run === '') {
            throw new LogicException('The original root owner has not initialized.');
        }
        return new BindingPlan(
            $terms,
            $row->root_run,
            $this->principal,
            function (BindingIdentity $identity, string $operation) use ($key): void {
                [$current] = $this->assignment($key);
                $root = $this->subject($key, 'order');
                if ($current->root_run !== $identity->parentRunId || ($operation === 'reserve'
                    && ((bool) $root->cancellation_requested || $root->state !== $identity->parentExecution->sourceState
                        || (string) $root->revision !== $identity->parentExecution->sourceRevision))) {
                    throw new LogicException('The live original owner refused this child admission.');
                }
            },
            $this->recipe($key),
            $this->cart->selected($key),
            $this->roots->selected($key) ? $this->roots->reservationFor($key, $row->root_run) : null
        );
    }

    public function subject(string $key, string $role): object
    {
        $this->authorize($key, $this->principal);
        $row = $this->db->table('example_machine_subjects')
            ->where('id', self::subjectId($key, $role))->sole();
        $this->assertGrant($row, $key, $role);
        return $row;
    }

    public function assertGrant(object $row, string $key, string $role): void
    {
        if ($row->id !== self::subjectId($key, $role) || $row->scope !== Catalogue::APPLICATION
            || $row->subject !== self::subjectName($key, $role) || $row->principal !== $this->principal
            || $row->authority_version !== $this->grantFor($key, $role)) {
            throw new LogicException('The original subject authority or selected policy is unavailable.');
        }
    }

    public function actor(WorkflowRun $actual, ?ExecutionReference $execution = null): array
    {
        if ($actual->namespace !== Catalogue::NAMESPACE) {
            throw new LogicException('The native actor is outside the configured business namespace.');
        }
        $role = self::role($actual->workflow_class);
        $args = $actual->workflowArguments();
        $key = $args[0] ?? '';
        if (! is_string($key) || $actual->namespace !== Catalogue::NAMESPACE) {
            throw new LogicException('The native actor has no authorized business namespace.');
        }
        $this->authorize($key, $this->principal);
        $plan = $this->plan($key);
        $finalizer = $this->assertNativeOwner($actual, $args, $key, $role, $plan);
        $actor = $this->db->table('provider_machine_actors')
            ->where('id', self::subjectId($key, $role))->sole();
        $scope = $role === 'order' ? $plan->rootScope() : $plan->childScope($role);
        if ($actor->root_key !== $key || $actor->role !== $role || $actor->native_run !== ($finalizer ? $plan->rootRun : $actual->id)
            || $actor->scope !== PortableDescriptor::encode($scope->toArray())) {
            throw new LogicException('The recorded original native owner or scope changed.');
        }
        $row = $this->subject($key, $role);
        if ($execution !== null && ($execution->scope !== $row->scope || $execution->subject !== $row->subject
            || $execution->policyFingerprint !== $this->profile($key, $role)
                ->fingerprint()
            || ! str_starts_with($execution->executionId, $actual->id . '.'))) {
            throw new LogicException('The transition belongs to another original native or business owner.');
        }
        return [$actor, $row, $plan];
    }

    public function authorizeScope(ScopeIdentity $scope, string $principal, string $operation): string
    {
        if (str_starts_with($operation, 'root_')) {
            return $this->roots->authorizeScope($scope, $principal, $operation);
        }
        $key = explode('.', $scope->key)[0];
        $this->authorize($key, $principal);
        if ($scope->application !== Catalogue::APPLICATION || $scope->namespace !== Catalogue::NAMESPACE
            || ! in_array(
                $operation,
                ['register', 'participate', 'forward', 'compensate', 'request', 'inspect'],
                true
            )) {
            throw new LogicException('The retained scope is outside the selected host boundary.');
        }
        if ($this->roots->selected($key) && in_array($operation, ['request', 'inspect', 'compensate'], true)
            && $scope->toArray() === $this->roots->identity($key)
                ->scope->toArray()) {
            [$assignment] = $this->assignment($key);
            if ($operation !== 'compensate' || $assignment->root_run === null) {
                return $this->roots->authorizeRecoveryScope($scope, $principal, $operation);
            }
        }
        if (str_starts_with($scope->key, $key . ExchangeStore::SCOPE_INFIX)) {
            return app(ExchangeStore::class)->authorizeScope($scope, $principal, $operation);
        }
        if (str_starts_with($scope->key, $key . ReturnStore::SCOPE_INFIX)) {
            return app(ReturnStore::class)->authorizeScope($scope, $principal, $operation);
        }
        $plan = $this->plan($key);
        $scopes = [$plan->rootScope(), $plan->childScope('payment')];
        $scopes[] = $plan->childScope('shipment');
        foreach ($scopes as $expected) {
            if ($scope->toArray() === $expected->toArray()) {
                $this->subject($key, 'order');
                return $plan->version();
            }
        }
        throw new LogicException('The original retained scope generation was substituted.');
    }

    public function authorizeOperation(OperationContext $context, string $operation, bool $owner = false): array
    {
        $this->authorizeScope($context->scope, $this->principal, $operation);
        $key = explode('.', $context->scope->key)[0];
        [, $terms] = $this->assignment($key);
        $plan = $this->plan($key);
        $role = $context->scope->key === $key ? 'order' : substr($context->scope->key, strlen($key) + 1);
        $actor = $this->db->table('provider_machine_actors')
            ->where('id', self::subjectId($key, $role))->sole();
        $expected = $owner ? [
            'key' => $key,
            'rootRun' => $plan->rootRun,
            'nativeRun' => $actor->native_run,
            'role' => $role,
        ] : $this->stock->descriptor($terms, $role);
        $handler = $role === 'order' ? $this->stock->handler($key) : 'provider.effect';
        if ($actor->root_key !== $key || $actor->role !== $role || $actor->native_run !== $context->originalRunId
            || $actor->scope !== PortableDescriptor::encode($context->scope->toArray())
            || $context->obligation->handlerType !== ($owner ? 'provider.owner' : $handler)
            || $context->obligation->handlerVersion !== '1'
            || $context->obligation->key !== ($owner ? 'owner' : 'effect')
            || PortableDescriptor::encode($context->obligation->input) !== PortableDescriptor::encode($expected)) {
            throw new LogicException('The provider descriptor does not belong to this original native/business owner.');
        }
        $this->subject($key, $role);
        $root = $this->subject($key, 'order');
        if (! $owner && $operation === 'forward' && FinancialCatalogue::selected($this->recipe($key))
            && ((bool) $root->cancellation_requested || $terms->authorization === null
                || ! $terms->authorization->canAdmitCaptureAt(Carbon::now()->getTimestamp()))) {
            throw new LogicException('The original financial policy has closed stock forward admission.');
        }
        return [$root, $terms];
    }

    public function assertAllowed(BindingIdentity $identity, string $principal, string $operation): string
    {
        $this->authorize($identity->parentExecution->subject, $principal);
        return $this->plan($identity->parentExecution->subject)
            ->assertAllowed($identity, $principal, $operation);
    }

    public function assertParticipationAllowed(
        BindingIdentity $identity,
        ChildScopeParticipation $participation,
        string $principal,
        string $operation,
        ?string $pinnedPolicyVersion = null,
    ): string {
        $this->authorize($identity->parentExecution->subject, $principal);
        return $this->plan($identity->parentExecution->subject)
            ->assertParticipationAllowed($identity, $participation, $principal, $operation, $pinnedPolicyVersion);
    }

    public static function role(string $class): string
    {
        return match ($class) {
            Order::class, CheckoutOrder::class, AuthorizationCheckout::class, FundingCheckout::class, CartCheckout::class, Cancellation::class => 'order',
            Payment::class, AuthorizedPayment::class, FundingPayment::class, CodFundingPayment::class, AdvanceBalancePayment::class => 'payment',
            Booking::class, FullShipment::class => 'shipment',
            default => throw new LogicException('The native workflow is outside this example.'),
        };
    }

    public static function subjectName(string $key, string $role): string
    {
        Catalogue::profile($role);
        return $key . ($role === 'order' ? '' : '.' . $role);
    }

    public static function subjectId(string $key, string $role): string
    {
        return SqlBusinessStore::subjectId(new ExecutionReference(
            Catalogue::APPLICATION,
            self::subjectName($key, $role),
            'identity',
            Catalogue::profile($role)->fingerprint(),
            'received',
            '1'
        ));
    }

    public static function grant(string $role): string
    {
        return hash('sha256', BindingPlan::VERSION . ':' . Catalogue::profile($role)->fingerprint());
    }

    public static function reference(array $value): ExecutionReference
    {
        $keys = array_keys($value);
        sort($keys);
        if ($keys !== ['executionId', 'policyFingerprint', 'scope', 'sourceRevision', 'sourceState', 'subject']) {
            throw new LogicException('The recorded execution reference is malformed.');
        }
        foreach ($value as $part) {
            if (! is_string($part)) {
                throw new LogicException('Execution reference fields must be strings.');
            }
        }
        return new ExecutionReference(...$value);
    }

    private function assertNativeOwner(
        WorkflowRun $actual,
        array $args,
        string $key,
        string $role,
        BindingPlan $plan
    ): bool {
        if ($actual->workflow_class === Cancellation::class) {
            if (count($args) !== 3 || ! is_string($args[1]) || ! is_string($args[2])
                || $args[1] !== $plan->rootRun
                || $actual->workflow_instance_id !== CancellationStore::instance($key, $plan->rootRun, $args[2])) {
                throw new LogicException('The proof-only finalizer belongs to another original cancellation.');
            }
            app(CancellationStore::class)->intent($key, $plan->rootRun, $args[2]);
            return true;
        }
        $input = $role === 'order' ? [$key] : $plan->childInput($role);
        $instance = $role === 'order' ? $plan->rootInstanceId() : 'dw-bound-child-' . $plan->binding($role)->key();
        if (($role === 'order' && $actual->workflow_class !== ($plan->rootReservation?->identity->workflowClass ?? $this->cart->rootClass(
            $key
        )))
            || PortableDescriptor::encode($args) !== PortableDescriptor::encode($input)
            || $actual->workflow_instance_id !== $instance || ($role === 'order' && $actual->id !== $plan->rootRun)) {
            throw new LogicException('The native actor differs from the pinned original admission.');
        }
        return false;
    }
}
