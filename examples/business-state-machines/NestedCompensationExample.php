<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\BusinessStateMachines;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\ConnectionInterface;
use LogicException;
use Workflow\V2\Compensation\Definition\PortableDescriptor;
use Workflow\V2\Compensation\Execution\HandlerRegistry;
use Workflow\V2\Compensation\Execution\OperationContext;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\Compensation\Recovery\ScopeRecovery;
use Workflow\V2\Compensation\Security\ScopeAuthorization;
use Workflow\V2\StateMachine\Binding\ChildBinding;
use Workflow\V2\StateMachine\Binding\ChildDefinitionRegistry;
use Workflow\V2\StateMachine\Binding\ChildReservation;
use Workflow\V2\StateMachine\Binding\ChildReservationStore;
use Workflow\V2\StateMachine\Execution\ExecutionReference;

/** Application-owned, synchronous SQL effects; this example makes no remote-provider guarantee. */
final readonly class NestedCompensationExample
{
    public const PRINCIPAL = 'example-worker';

    public const AUTHORITY = 'example-grant-v1';

    public function __construct(
        private ConnectionInterface $db
    ) {
    }

    public static function configure(Container $app, ConnectionInterface $db): self
    {
        $policy = new ExampleOwnershipPolicy(self::PRINCIPAL);
        $example = new self($db);
        $app->instance(self::class, $example);
        $app->instance(ScopeAuthorization::class, $policy->scopeAuthorization());
        $app->instance(ChildReservationStore::class, new ChildReservationStore($policy));
        $definitions = [];
        foreach (['prepare', 'imported'] as $integration) {
            $definitions[ProfileCatalogue::child($integration, '1')->fingerprint()] = ShipmentWorkflow::class;
        }
        $app->instance(ChildDefinitionRegistry::class, new ChildDefinitionRegistry([
            ExampleOwnershipPolicy::CHILD_TYPE => $definitions,
        ]));
        $handlers = [];
        foreach (['reserve', 'prepare', 'import', 'deliver'] as $action) {
            $handlers['example.sql.' . $action]['1'] = new SqlEffectOperation(
                $db,
                $action,
                static fn (OperationContext $context, string $operation): array => $example->authorizeEffect(
                    $context,
                    $operation,
                ),
            );
        }
        $app->instance(HandlerRegistry::class, new HandlerRegistry($handlers));
        return $example;
    }

    public static function execution(
        string $subject,
        string $integration,
        string $version,
        string $role
    ): ExecutionReference {
        $profile = $role === 'parent' ? ProfileCatalogue::select($integration, $version)
            : ProfileCatalogue::child($integration, $version);
        return new ExecutionReference(
            ExampleOwnershipPolicy::APPLICATION . ($role === 'parent' ? '' : '.shipment'),
            $subject,
            $subject . ':' . ($role === 'parent' ? 'begin' : 'deliver'),
            $profile->fingerprint(),
            'received',
            '1',
        );
    }

    public function initialize(
        string $subject,
        string $integration,
        string $version,
        string $role,
        string $parentRun,
        string $run,
        string $instance,
    ): array {
        if ($role === 'parent' && ($parentRun !== $run || $instance !== ExampleOwnershipPolicy::instanceId($subject))) {
            throw new LogicException('The Order must retain its canonical instance and original native run.');
        }
        $scope = ExampleOwnershipPolicy::scope($subject, $parentRun, $role);
        (new ExampleOwnershipPolicy(self::PRINCIPAL))->assertScopeAllowed($scope, self::PRINCIPAL, 'register');
        $execution = self::execution($subject, $integration, $version, $role);
        if ($role === 'child' && (new ScopeRepository())->inspect($scope)['original_run_id'] !== $run) {
            throw new LogicException('The Shipment is not the admitted original child.');
        }
        $this->db->transaction(function () use ($execution, $integration, $version, $role, $run, $instance): void {
            $parent = $role === 'child' ? $this->db->table('example_machine_subjects')
                ->where('subject', $execution->subject)
                ->where('scope', ExampleOwnershipPolicy::APPLICATION)->lockForUpdate()->sole() : null;
            $id = SqlBusinessStore::subjectId($execution);
            $existing = $this->db->table('example_machine_subjects')
                ->where('id', $id)
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                if ($existing->original_native_run_id !== $run || $existing->native_instance_id !== $instance
                    || $existing->profile !== $integration || $existing->profile_version !== $version
                    || $existing->principal !== self::PRINCIPAL || $existing->authority_version !== self::grant(
                        $execution
                    )) {
                    throw new LogicException('The original example owner or policy was substituted.');
                }
                return;
            }
            $cancelled = false;
            $parentId = null;
            if ($parent !== null) {
                $cancelled = (bool) $parent->cancellation_requested;
                $parentId = $parent->id;
            }
            $this->db->table('example_machine_subjects')
                ->insert([
                    'id' => $id,
                    'scope' => $execution->scope,
                    'subject' => $execution->subject,
                    'principal' => self::PRINCIPAL,
                    'authority_version' => self::grant($execution),
                    'state' => 'received',
                    'revision' => 1,
                    'cancellation_requested' => $cancelled,
                    'parent_subject_id' => $parentId,
                    'profile' => $integration,
                    'profile_version' => $version,
                    'native_instance_id' => $instance,
                    'original_native_run_id' => $run,
                ]);
        });
        return $execution->toArray();
    }

    public function recordAdmission(array $value, string $parentRun): array
    {
        $reservation = ChildReservation::fromArray($value);
        if ($reservation->identity->parentRunId !== $parentRun) {
            throw new LogicException('The admission activity belongs to another parent run.');
        }
        $binding = app(ChildReservationStore::class)->close($reservation, self::PRINCIPAL);
        if (! $binding instanceof ChildBinding) {
            throw new LogicException('A queued or never-admitted Shipment is not a confirmed start.');
        }
        $content = json_encode($binding->toArray(), JSON_THROW_ON_ERROR);
        $id = $reservation->identity->key();
        $this->db->transaction(function () use ($binding, $content, $id, $value): void {
            $parent = $this->subject($binding->identity->parentExecution);
            $existing = $this->db->table('example_machine_bindings')
                ->where('id', $id)
                ->first();
            if ($existing !== null && $existing->admission !== $content) {
                throw new LogicException('The immutable Shipment binding changed.');
            }
            if ($existing !== null) {
                return;
            }
            if ($existing === null) {
                $this->db->table('example_machine_bindings')
                    ->insert([
                        'id' => $id,
                        'subject_id' => $parent->id,
                        'reservation' => json_encode($value, JSON_THROW_ON_ERROR),
                        'admission' => $content,
                        'business_outcome_verified' => false,
                    ]);
            }
            if (! (bool) $parent->cancellation_requested) {
                $this->db->table('example_machine_subjects')
                    ->where('id', $parent->id)
                    ->update([
                        'state' => $binding->identity->hostingState,
                    ]);
            }
        });
        return $binding->toArray();
    }

    public function completeShipment(string $subject, string $integration, string $version, string $run): array
    {
        $execution = self::execution($subject, $integration, $version, 'child');
        return $this->db->transaction(function () use ($execution, $run): array {
            $child = $this->subject($execution);
            if ($child->original_native_run_id !== $run || (bool) $child->cancellation_requested
                || ! $this->db->table('example_machine_effects')
                    ->where('subject_id', $child->id)
                    ->where('action', 'deliver')
                    ->where('status', 'active')
                    ->exists()) {
                throw new LogicException('Shipment delivery has no authoritative business evidence.');
            }
            $this->db->table('example_machine_subjects')
                ->where('id', $child->id)
                ->update([
                    'state' => 'completed',
                ]);
            return [
                'subject' => $execution->subject,
                'originalRunId' => $run,
                'businessOutcome' => 'completed',
            ];
        });
    }

    public function completeOrder(
        array $value,
        array $outcome,
        string $integration,
        string $version,
        string $run
    ): array {
        $binding = ChildBinding::fromArray($value);
        if ($binding->identity->parentRunId !== $run) {
            throw new LogicException('The completion activity belongs to another Order run.');
        }
        (new ExampleOwnershipPolicy(self::PRINCIPAL))->assertAllowed($binding->identity, self::PRINCIPAL, 'load');
        return $this->db->transaction(function () use ($binding, $outcome, $integration, $version): array {
            $parent = $this->subject($binding->identity->parentExecution);
            $child = $this->subject(self::execution($parent->subject, $integration, $version, 'child'));
            $stored = $this->db->table('example_machine_bindings')
                ->where('id', $binding->identity->key())
                ->sole();
            $expected = [
                'subject' => $parent->subject,
                'originalRunId' => $binding->admission->childRunId,
                'businessOutcome' => 'completed',
            ];
            if ((bool) $parent->cancellation_requested || (bool) $child->cancellation_requested
                || $child->state !== 'completed' || $child->original_native_run_id !== $binding->admission->childRunId
                || $outcome !== $expected || json_decode(
                    $stored->admission,
                    true,
                    flags: JSON_THROW_ON_ERROR
                ) !== $binding->toArray()) {
                throw new LogicException('Technical child closure is not verified Shipment delivery.');
            }
            $this->db->table('example_machine_bindings')
                ->where('id', $stored->id)
                ->update([
                    'business_outcome_verified' => true,
                ]);
            $this->db->table('example_machine_subjects')
                ->where('id', $parent->id)
                ->update([
                    'state' => 'completed',
                ]);
            return [
                'businessOutcome' => 'completed',
                'binding' => $binding->toArray(),
            ];
        });
    }

    public function requestCancellation(string $subject, string $parentRun, string $requestId): array
    {
        $scope = ExampleOwnershipPolicy::scope($subject, $parentRun, 'parent');
        (new ExampleOwnershipPolicy(self::PRINCIPAL))->assertScopeAllowed($scope, self::PRINCIPAL, 'request');
        $this->db->transaction(function () use ($subject, $parentRun): void {
            $parent = $this->db->table('example_machine_subjects')
                ->where('subject', $subject)
                ->where('scope', ExampleOwnershipPolicy::APPLICATION)->lockForUpdate()->sole();
            if ($parent->original_native_run_id !== $parentRun || $parent->principal !== self::PRINCIPAL
                || $parent->authority_version !== self::grant(
                    self::execution($subject, $parent->profile, $parent->profile_version, 'parent')
                )) {
                throw new LogicException('Cancellation cannot follow a newer owner.');
            }
            $this->db->table('example_machine_subjects')
                ->where('parent_subject_id', $parent->id)
                ->lockForUpdate()
                ->get();
            $this->db->table('example_machine_subjects')
                ->where('id', $parent->id)
                ->orWhere('parent_subject_id', $parent->id)
                ->update([
                    'cancellation_requested' => true,
                ]);
        });
        // Missing root initialization remains an explicit failure; this never manufactures a ledger.
        return app(ScopeRecovery::class)->request($scope, $requestId, self::PRINCIPAL, 'business_cancellation');
    }

    /**
     * Operator view of a cancellation. It only reads: automatic recovery after
     * the Order closes, any recorded authorization refusal, and every unresolved
     * Shipment and Order obligation with its reason.
     */
    public function cancellationOutcome(string $subject, string $parentRun): array
    {
        $recovery = app(ScopeRecovery::class);
        $parent = $recovery->describe(ExampleOwnershipPolicy::scope($subject, $parentRun, 'parent'), self::PRINCIPAL);
        $child = $recovery->describe(ExampleOwnershipPolicy::scope($subject, $parentRun, 'child'), self::PRINCIPAL);
        $unresolved = [];
        foreach ([
            'child' => $child,
            'parent' => $parent,
        ] as $role => $view) {
            foreach ($view['unresolved'] as $obligation) {
                $unresolved[] = [
                    'role' => $role,
                    ...$obligation,
                ];
            }
        }
        return [
            'recoveryStatus' => $parent['recoveryStatus'],
            'scopeStatus' => $parent['scopeStatus'],
            'automaticRecovery' => $parent['automaticRecovery'],
            'nextAction' => $parent['nextAction'],
            'unresolved' => $unresolved,
        ];
    }

    /**
     * After the operator resolves the blocker, submit fresh authorized keys. A failed Shipment
     * needs its own request; the Order request never creates it implicitly.
     */
    public function resubmitCancellation(string $subject, string $parentRun, string $requestId): array
    {
        foreach (['child', 'parent'] as $role) {
            $scope = ExampleOwnershipPolicy::scope($subject, $parentRun, $role);
            (new ExampleOwnershipPolicy(self::PRINCIPAL))->assertScopeAllowed($scope, self::PRINCIPAL, 'request');
            $receipt = app(ScopeRecovery::class)->request(
                $scope,
                $requestId,
                self::PRINCIPAL,
                'business_cancellation'
            );
        }
        return $receipt;
    }

    /**
     * The carrier took the parcel; voiding the delivery now needs the parcel back.
     */
    public function recordCarrierHandoff(string $subject): void
    {
        $this->moveDelivery($subject, 'active', 'dispatched');
    }

    /**
     * The carrier returned the parcel, so the delivery can be voided again.
     */
    public function recordCarrierReturn(string $subject): void
    {
        $this->moveDelivery($subject, 'dispatched', 'active');
    }

    private function moveDelivery(string $subject, string $from, string $to): void
    {
        $this->db->transaction(function () use ($subject, $from, $to): void {
            $shipment = $this->db->table('example_machine_subjects')
                ->where('subject', $subject)
                ->where('scope', ExampleOwnershipPolicy::APPLICATION . '.shipment')
                ->lockForUpdate()
                ->sole();
            $moved = $this->db->table('example_machine_effects')
                ->where('subject_id', $shipment->id)
                ->where('action', 'deliver')
                ->where('status', $from)
                ->update([
                    'status' => $to,
                ]);
            if ($moved !== 1) {
                throw new LogicException('The Shipment delivery is not ' . $from . '.');
            }
        });
    }

    private function subject(ExecutionReference $execution): object
    {
        $row = $this->db->table('example_machine_subjects')
            ->where('id', SqlBusinessStore::subjectId($execution))
            ->lockForUpdate()
            ->sole();
        if ($row->scope !== $execution->scope || $row->subject !== $execution->subject
            || $row->principal !== self::PRINCIPAL || $row->authority_version !== self::grant($execution)) {
            throw new LogicException('The example subject authority changed.');
        }
        return $row;
    }

    private function authorizeEffect(OperationContext $context, string $operation): array
    {
        $role = $context->obligation->input['action'] === 'deliver' ? 'child' : 'parent';
        (new ExampleOwnershipPolicy(self::PRINCIPAL))->assertScopeAllowed($context->scope, self::PRINCIPAL, $operation);
        $subject = substr($context->scope->key, 0, -strlen('.' . $role));
        if ($context->scope->toArray() !== ExampleOwnershipPolicy::scope(
            $subject,
            $context->scope->generation,
            $role
        )->toArray()) {
            throw new LogicException('The SQL operation scope was substituted.');
        }
        foreach (['prepare', 'imported'] as $integration) {
            $expected = self::execution($subject, $integration, '1', $role);
            $actions = $role === 'child' ? ['deliver'] : ProfileCatalogue::actions($integration, '1');
            if (PortableDescriptor::encode($context->obligation->input['execution']) === PortableDescriptor::encode(
                $expected->toArray()
            )
                && in_array($context->obligation->input['action'], $actions, true)
                && $context->obligation->key === $context->obligation->input['action']) {
                return [
                    'principal' => self::PRINCIPAL,
                    'version' => self::grant($expected),
                ];
            }
        }
        throw new LogicException('The original SQL operation/profile relationship is unavailable.');
    }

    private static function grant(ExecutionReference $execution): string
    {
        return self::AUTHORITY . ':' . $execution->policyFingerprint;
    }
}
