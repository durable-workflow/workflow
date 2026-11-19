<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use LogicException;
use Workflow\V2\Compensation\Admission\RootAuthorization;
use Workflow\V2\Compensation\Admission\RootBinding;
use Workflow\V2\Compensation\Admission\RootIdentity;
use Workflow\V2\Compensation\Admission\RootReservation;
use Workflow\V2\Compensation\Admission\RootReservationStore;
use Workflow\V2\Compensation\Definition\PortableDescriptor;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\StartOptions;

/** An opt-in original business intent, retained before any native reservation. */
final readonly class RootPolicy implements RootAuthorization
{
    public const VERSION = 'provider-original-root-v1';

    public function __construct(
        private Authority $authority,
        private array $subjects
    ) {
        if (! array_is_list($subjects)) {
            throw new LogicException('Reserved root authority requires an explicit unique subject grant list.');
        }
        foreach ($subjects as $key) {
            if (! is_string($key)) {
                throw new LogicException('Reserved root authority requires canonical business subject keys.');
            }
            $authority->authorize($key, $authority->principal);
            if ($authority->recipe($key) !== Catalogue::RESERVE_FIRST) {
                throw new LogicException('The reserved root example currently requires its reserve-first policy.');
            }
        }
        if (count($subjects) !== count(array_unique($subjects))) {
            throw new LogicException('Reserved root authority requires unique original subject grants.');
        }
    }

    public function grants(): array
    {
        return $this->subjects;
    }

    public function selected(string $key): bool
    {
        return in_array($key, $this->subjects, true);
    }

    public function version(string $key): string
    {
        return $this->selected($key) ? '.root-v1' : '';
    }

    public function createIntent(OrderTerms $terms): ?RootIdentity
    {
        $this->assertHost();
        $key = $terms->subjectKey();
        $this->authority->authorize($key, $this->authority->principal);
        return $this->selected($key) ? RootIdentity::forWorkflow(
            new ScopeIdentity(Catalogue::APPLICATION, Catalogue::NAMESPACE, $key, $terms->generation),
            $this->authority->cart->rootClass($key),
            [$key],
            new StartOptions(businessKey: $key),
        ) : null;
    }

    /**
     * Called with the original subject and assignment in their owned business transaction.
     */
    public function seedRow(OrderTerms $terms, ?RootIdentity $identity): void
    {
        $key = $terms->subjectKey();
        if (\Workflow\V2\Support\WorkflowFiberContext::active() || $this->authority->db->transactionLevel() !== 1 || $this->selected(
            $key
        ) !== ($identity !== null)) {
            throw new LogicException('Root intent seed requires its original owned business transaction.');
        }
        if ($identity === null) {
            return;
        }
        $this->assertRelation($identity, $terms);
        $payload = PortableDescriptor::encode($identity->toArray());
        $this->authority->db->table('provider_machine_root_intents')
            ->insert([
                'id' => $key,
                'identity' => $payload,
                'identity_sha256' => hash('sha256', $payload),
                'policy_version' => self::VERSION,
            ]);
    }

    public function identity(string $key): RootIdentity
    {
        $this->assertHost();
        $this->authority->authorize($key, $this->authority->principal);
        if (! $this->selected($key)) {
            throw new LogicException('This business generation has no reserved root grant.');
        }
        [, $terms] = $this->authority->assignment($key);
        $row = $this->authority->db->table('provider_machine_root_intents')
            ->where('id', $key)
            ->sole();
        if ($row->policy_version !== self::VERSION || ! is_string($row->identity) || strlen($row->identity) > 65_536
            || ! hash_equals($row->identity_sha256, hash('sha256', $row->identity))) {
            throw new LogicException('The original root intent or policy changed.');
        }
        $identity = RootIdentity::fromArray(json_decode($row->identity, true, 32, JSON_THROW_ON_ERROR));
        $this->assertRelation($identity, $terms);
        return $identity;
    }

    public function assertAllowed(RootIdentity $identity, string $principal, string $operation): string
    {
        $key = $identity->scope->key;
        $this->authority->authorize($key, $principal);
        if (! in_array($operation, ['reserve', 'load', 'start', 'close'], true)
            || $this->identity($key)
                ->toArray() !== $identity->toArray()) {
            throw new LogicException('The original business root relationship was substituted.');
        }
        return self::VERSION . ':' . $this->authority->grantFor($key, 'order');
    }

    public function authorizeScope(ScopeIdentity $scope, string $principal, string $operation): string
    {
        $this->authority->authorize($scope->key, $principal);
        if (! in_array($operation, ['root_reserve', 'root_load', 'root_start', 'root_close'], true)
            || $this->identity($scope->key)
                ->scope->toArray() !== $scope->toArray()) {
            throw new LogicException('The original root scope relationship was substituted.');
        }
        return self::VERSION . ':' . $this->authority->grantFor($scope->key, 'order');
    }

    public function authorizeRecoveryScope(ScopeIdentity $scope, string $principal, string $operation): string
    {
        $this->authority->authorize($scope->key, $principal);
        $identity = $this->identity($scope->key);
        if (! in_array(
            $operation,
            ['request', 'inspect', 'compensate'],
            true
        ) || $identity->scope->toArray() !== $scope->toArray()) {
            throw new LogicException(
                'Only the exact admitted original root can be requested or inspected before initialization.'
            );
        }
        if ($operation === 'compensate') {
            [$assignment] = $this->authority->assignment($scope->key);
            $root = $this->authority->subject($scope->key, 'order');
            if ($assignment->root_run !== null || ! (bool) $root->cancellation_requested
                || ! in_array($root->state, ['received', 'cancelled'], true)) {
                throw new LogicException(
                    'Startup recovery requires its original committed business cancellation fence.'
                );
            }
        }
        $store = app(RootReservationStore::class);
        $outcome = $store->close($store->load($identity, $principal), $principal);
        if (! $outcome instanceof RootBinding) {
            throw new LogicException('Never-admitted roots cannot become compensation ledgers.');
        }
        return BindingPlan::versionFor($this->authority->recipe($scope->key)) . '.root-v1';
    }

    public function reservationFor(string $key, string $originalRun): RootReservation
    {
        $store = app(RootReservationStore::class);
        $reservation = $store->load($this->identity($key), $this->authority->principal);
        if ($reservation->originalRunId !== $originalRun) {
            throw new LogicException('The original business root belongs to another native run.');
        }
        $outcome = $store->close($reservation, $this->authority->principal);
        if (! $outcome instanceof RootBinding) {
            throw new LogicException('The original business root has no matching native admission.');
        }
        return $reservation;
    }

    private function assertHost(): void
    {
        if (\Workflow\V2\Support\WorkflowFiberContext::active() || $this->authority->db->transactionLevel() !== 0) {
            throw new LogicException(
                'Original root business operations require an unlocked host boundary outside replay.'
            );
        }
    }

    private function assertRelation(RootIdentity $identity, OrderTerms $terms): void
    {
        if ($identity->scope->application !== Catalogue::APPLICATION || $identity->scope->namespace !== Catalogue::NAMESPACE
            || $identity->scope->key !== $terms->subjectKey() || $identity->scope->generation !== $terms->generation
            || $identity->inputFingerprint !== \Workflow\V2\StateMachine\Binding\ChildReservation::inputFingerprint([
                $terms->subjectKey(),
            ])) {
            throw new LogicException('The root intent differs from its original business generation.');
        }
    }
}
