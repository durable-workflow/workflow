<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ConfiguredStateMachines;

use DurableWorkflow\Examples\BusinessStateMachines\SqlBusinessStore;
use DurableWorkflow\Examples\BusinessStateMachines\SqlEffectOperation;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\ConnectionInterface;
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
use Workflow\V2\StateMachine\Binding\ChildReservation;
use Workflow\V2\StateMachine\Binding\ChildReservationStore;
use Workflow\V2\StateMachine\Execution\ExecutionReference;

/** Application assembly, original native initialization and state-bound child milestones. */
final class MachineStore
{
    public readonly Ownership $ownership;

    private readonly ConnectionInterface $db;

    public function __construct(
        private readonly MachineAuthority $authority
    ) {
        $this->db = $authority->db;
        $this->ownership = $authority->ownership;
    }

    public static function configure(Container $app, ConnectionInterface $db, string $principal, array $subjects): self
    {
        $authority = new MachineAuthority($db, $principal, $subjects);
        $store = new self($authority);
        $app->instance(MachineAuthority::class, $authority);
        $app->instance(AssessmentStore::class, new AssessmentStore($authority));
        $app->instance(CancellationStore::class, new CancellationStore($authority));
        $app->instance(self::class, $store);
        $app->instance(ScopeAuthorization::class, $store->ownership->scopeAuthorization(
            static fn (ScopeIdentity $scope, string $who, string $operation) => $authority->authorizeScope(
                $scope,
                $who,
                $operation
            )
        ));
        $app->instance(ChildReservationStore::class, new ChildReservationStore($store->ownership));
        $children = [];
        foreach (Catalogue::profiles() as [$integration, $version]) {
            $children[Catalogue::shipment($integration, $version)->fingerprint()] = Shipment::class;
        }
        $app->instance(ChildDefinitionRegistry::class, new ChildDefinitionRegistry([
            Ownership::CHILD_TYPE => $children,
        ]));
        $handlers = [
            'configured.owner' => [
                '1' => new OwnerOperation($authority),
            ],
        ];
        foreach (['reserve', 'prepare', 'import', 'deliver'] as $action) {
            $handlers['configured.sql.' . $action]['1'] = new SqlEffectOperation(
                $db,
                $action,
                static fn (OperationContext $context, string $operation): array => $authority->authorizeEffect(
                    $context,
                    $operation
                )
            );
        }
        $app->instance(HandlerRegistry::class, new HandlerRegistry($handlers));
        return $store;
    }

    public function seed(
        string $subject,
        string $integration,
        bool $enabled = true,
        bool $prevalidated = false,
        string $version = '1'
    ): void {
        $this->authority->unlocked();
        $this->ownership->authorize($subject, $this->ownership->principal);
        $profile = Catalogue::order($integration, $version);
        $execution = new ExecutionReference(
            Ownership::APPLICATION,
            $subject,
            'seed',
            $profile->fingerprint(),
            'received',
            '1'
        );
        $this->db->table('example_machine_subjects')
            ->insert([
                'id' => SqlBusinessStore::subjectId($execution),
                'scope' => $execution->scope,
                'subject' => $subject,
                'principal' => $this->ownership->principal,
                'authority_version' => MachineAuthority::grant($profile),
                'state' => 'received',
                'revision' => 1,
                'cancellation_requested' => false,
                'parent_subject_id' => null,
                'profile' => $integration,
                'profile_version' => $version,
                'native_instance_id' => Ownership::instance($subject),
                'original_native_run_id' => null,
                'enabled' => $enabled,
                'prevalidated' => $prevalidated,
                'active_binding_id' => null,
            ]);
    }

    public function initialize(WorkflowRun $actual, string $subject, string $rootRun, ?array $begin = null): array
    {
        $this->authority->unlocked();
        $this->ownership->authorize($subject, $this->ownership->principal);
        $this->authority->namespace($actual);
        if (($begin === null && ($actual->workflow_class !== Order::class || $actual->id !== $rootRun
            || $actual->workflow_instance_id !== Ownership::instance($subject)))
            || ($begin !== null && $actual->workflow_class !== Shipment::class)) {
            throw new LogicException('Initialization belongs to another native owner.');
        }
        $root = $this->authority->root($subject);
        return $begin === null ? $this->initializeRoot($actual, $root, $subject, $rootRun)
            : $this->initializeChild($actual, $root, $rootRun, $begin);
    }

    public function execution(WorkflowRun $actual, string $transition): array
    {
        [$row, $profile] = $this->authority->actor($actual);
        Catalogue::transition($profile, $transition);
        if (($actual->workflow_class === Cancellation::class) !== ($transition === 'cancel')) {
            throw new LogicException('Only the proof-only finalizer may request the cancellation transition.');
        }
        return (new ExecutionReference(
            $row->scope,
            $row->subject,
            $actual->id . '.' . $transition . '.' . $row->revision,
            $profile->fingerprint(),
            $row->state,
            (string) $row->revision
        ))->toArray();
    }

    public function binding(WorkflowRun $actual, array $value): ?array
    {
        [$row] = $this->authority->actor($actual);
        $reservation = ChildReservation::fromArray($value);
        $this->ownership->assertParticipationAllowed(
            $reservation->identity,
            $reservation->participation,
            $this->ownership->principal,
            'load',
            $reservation->participationAuthorizationVersion
        );
        if ($reservation->identity->parentRunId !== $actual->id) {
            throw new LogicException('The binding lookup belongs to another original native run.');
        }
        $stored = $this->db->table('example_machine_bindings')
            ->where('id', $reservation->identity->key())
            ->first();
        if ($stored === null) {
            return null; // Planning observation only; never evidence of non-admission.
        }
        if ($stored->subject_id !== $row->id || PortableDescriptor::encode(json_decode(
            $stored->reservation,
            true,
            flags: JSON_THROW_ON_ERROR
        )) !== PortableDescriptor::encode($value)) {
            throw new LogicException('The recorded binding belongs to another state entry.');
        }
        return ChildBinding::fromArray(json_decode($stored->admission, true, flags: JSON_THROW_ON_ERROR))->toArray();
    }

    public function activeBinding(WorkflowRun $actual): array
    {
        [$row] = $this->authority->actor($actual);
        if ($actual->workflow_class !== Order::class || $row->active_binding_id === null) {
            throw new LogicException('There is no original Shipment binding to observe.');
        }
        $record = $this->db->table('example_machine_bindings')
            ->where('id', $row->active_binding_id)
            ->sole();
        return $this->binding($actual, json_decode($record->reservation, true, flags: JSON_THROW_ON_ERROR))
            ?? throw new LogicException('The original state-entry binding is unavailable.');
    }

    public function recordStart(WorkflowRun $actual, array $value): array
    {
        [$row] = $this->authority->actor($actual);
        $reservation = ChildReservation::fromArray($value);
        if ($reservation->identity->parentRunId !== $actual->id) {
            throw new LogicException('The start milestone belongs to another original parent.');
        }
        $binding = app(ChildReservationStore::class)->close($reservation, $this->ownership->principal);
        if (! $binding instanceof ChildBinding) {
            throw new LogicException('A closed unused reservation is not a confirmed Shipment start.');
        }
        $content = PortableDescriptor::encode($binding->toArray());
        $this->db->transaction(function () use ($row, $reservation, $content): void {
            $parent = $this->db->table('example_machine_subjects')
                ->where('id', $row->id)
                ->lockForUpdate()
                ->sole();
            $this->authority->assertGrant($parent, $this->authority->profile($parent));
            $key = $reservation->identity->key();
            $existing = $this->db->table('example_machine_bindings')
                ->where('id', $key)
                ->first();
            if ($existing !== null) {
                if ($existing->subject_id !== $row->id || $existing->admission !== $content) {
                    throw new LogicException('The original immutable child admission changed.');
                }
                return;
            }
            if ($parent->active_binding_id !== null && $parent->active_binding_id !== $key) {
                throw new LogicException('The hosting slot already belongs to another state entry.');
            }
            $this->db->table('example_machine_bindings')
                ->insert([
                    'id' => $key,
                    'subject_id' => $row->id,
                    'reservation' => PortableDescriptor::encode($reservation->toArray()),
                    'admission' => $content,
                    'business_outcome_verified' => false,
                ]);
            $this->db->table('example_machine_subjects')
                ->where('id', $row->id)
                ->update([
                    'active_binding_id' => $key,
                ]);
            // Business state/revision change only in the separate protected transition commit.
        });
        return $binding->toArray();
    }

    public function observeOutcome(WorkflowRun $actual, array $value, array $outcome): array
    {
        [$row] = $this->authority->actor($actual);
        $binding = ChildBinding::fromArray($value);
        $this->ownership->assertAllowed($binding->identity, $this->ownership->principal, 'load');
        if ($binding->identity->parentRunId !== $actual->id) {
            throw new LogicException('Result observation belongs to another original parent.');
        }
        $expected = [
            'subject' => $row->subject,
            'originalRunId' => $binding->admission->childRunId,
            'businessOutcome' => 'completed',
        ];
        if (PortableDescriptor::encode($outcome) !== PortableDescriptor::encode($expected)) {
            throw new LogicException('Technical child closure is not the required business result.');
        }
        $this->db->transaction(function () use ($row, $binding): void {
            $parent = $this->db->table('example_machine_subjects')
                ->where('id', $row->id)
                ->lockForUpdate()
                ->sole();
            $this->authority->assertGrant($parent, $this->authority->profile($parent));
            $child = $this->db->table('example_machine_subjects')
                ->where('native_instance_id', $binding->admission->childInstanceId)
                ->lockForUpdate()
                ->sole();
            if ((bool) $parent->cancellation_requested || (bool) $child->cancellation_requested || $child->state !== 'completed'
                || $child->original_native_run_id !== $binding->admission->childRunId
                || ! $this->db->table('example_machine_effects')
                    ->where('subject_id', $child->id)
                    ->where('action', 'deliver')
                    ->where('status', 'active')
                    ->exists()) {
                throw new LogicException('Shipment business evidence is unavailable or fenced.');
            }
            $stored = $this->db->table('example_machine_bindings')
                ->where('id', $binding->identity->key())
                ->sole();
            if ($stored->subject_id !== $parent->id || PortableDescriptor::encode(json_decode(
                $stored->admission,
                true,
                flags: JSON_THROW_ON_ERROR
            )) !== PortableDescriptor::encode($binding->toArray())) {
                throw new LogicException('The admitted child/state-entry relationship changed.');
            }
            $this->db->table('example_machine_bindings')
                ->where('id', $binding->identity->key())
                ->update([
                    'business_outcome_verified' => true,
                ]);
        });
        return $expected;
    }

    public function verifyStep(WorkflowRun $actual, array $value, string $transitionKey): array
    {
        $execution = MachineAuthority::reference($value);
        [$row, $profile] = $this->authority->actor($actual, $execution);
        Catalogue::transition($profile, $transitionKey);
        if ($execution->executionId !== $actual->id . '.' . $transitionKey . '.' . $execution->sourceRevision) {
            throw new LogicException('Step verification requires its original transition execution.');
        }
        $root = $this->authority->root(explode('.', $row->subject)[0]);
        $scope = $row->parent_subject_id === null ? Ownership::scope($row->subject, $root->original_native_run_id)
            : new ScopeIdentity(
                Ownership::APPLICATION,
                Ownership::NAMESPACE,
                $row->subject,
                $this->authority->childEntry($actual)
            );
        $metadata = (new ScopeRepository())->inspect($scope);
        if ($metadata['original_run_id'] !== $actual->id || ! in_array(
            $metadata['status'],
            ['requested', 'verified'],
            true
        )) {
            throw new LogicException('Step compensation requires its original closed forward gate.');
        }
        $keys = match ($transitionKey) {
            'begin' => [...array_map(
                static fn (string $action): string => $execution->executionId . '.' . $action,
                Catalogue::actions($profile->profile)
            ), $execution->executionId . '.shipment'],
            'deliver' => [$execution->executionId . '.deliver'],
            default => [],
        };
        foreach ($metadata['obligations'] as $obligation) {
            if (in_array(
                $obligation['descriptor']['key'],
                $keys,
                true
            ) && $obligation['compensation_status'] !== 'verified') {
                throw new LogicException('An original transition obligation is still unresolved.');
            }
        }
        // Missing keys cannot be registered after this permanent gate closes; no provider call precedes registration.
        return [
            'execution' => $execution->toArray(),
            'verified' => true,
        ];
    }

    public function requestCancellation(string $subject, string $rootRun, string $requestKey): array
    {
        return app(CancellationStore::class)->requestCancellation($subject, $rootRun, $requestKey);
    }

    public function finishCancellation(string $subject, string $rootRun, string $requestKey): array
    {
        return app(CancellationStore::class)->finishCancellation($subject, $rootRun, $requestKey);
    }

    private function initializeRoot(WorkflowRun $actual, object $root, string $subject, string $rootRun): array
    {
        $instance = WorkflowInstance::query()->findOrFail($actual->workflow_instance_id);
        if ((int) $instance->run_count !== 1 || $instance->current_run_id !== $actual->id) {
            throw new LogicException('This example does not transfer root ownership to a successor run.');
        }
        $this->db->transaction(function () use ($root, $actual): void {
            $row = $this->db->table('example_machine_subjects')
                ->where('id', $root->id)
                ->lockForUpdate()
                ->sole();
            $this->authority->assertGrant($row, Catalogue::order($row->profile, $row->profile_version));
            if ($row->original_native_run_id !== null && $row->original_native_run_id !== $actual->id) {
                throw new LogicException('The original business generation already has another owner.');
            }
            $this->db->table('example_machine_subjects')
                ->where('id', $row->id)
                ->update([
                    'original_native_run_id' => $actual->id,
                ]);
        });
        $root = $this->authority->root($subject);
        return $this->authority->initialization($root, $rootRun, 'order');
    }

    private function initializeChild(WorkflowRun $actual, object $root, string $rootRun, array $begin): array
    {
        $execution = MachineAuthority::reference($begin);
        $binding = Ownership::binding($execution, $rootRun, $root->profile, $root->profile_version);
        $scope = Ownership::childScope($execution);
        if ($actual->workflow_class !== Shipment::class || $actual->workflow_instance_id !== 'dw-bound-child-' . $binding->key()
            || $root->original_native_run_id !== $rootRun
            || (new ScopeRepository())->metadata($scope)['original_run_id'] !== $actual->id) {
            throw new LogicException('Child initialization requires its exact genuine native admission.');
        }
        $profile = Catalogue::shipment($root->profile, $root->profile_version);
        $childSubject = $scope->key;
        $child = new ExecutionReference(
            Ownership::SHIPMENT_APPLICATION,
            $childSubject,
            'seed',
            $profile->fingerprint(),
            'received',
            '1'
        );
        $id = SqlBusinessStore::subjectId($child);
        $this->db->transaction(function () use ($root, $actual, $profile, $childSubject, $id): void {
            $parent = $this->db->table('example_machine_subjects')
                ->where('id', $root->id)
                ->lockForUpdate()
                ->sole();
            $this->authority->assertGrant($parent, Catalogue::order($parent->profile, $parent->profile_version));
            $existing = $this->db->table('example_machine_subjects')
                ->where('id', $id)
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                $this->authority->assertGrant($existing, $profile);
                if ($existing->original_native_run_id !== $actual->id || $existing->native_instance_id !== $actual->workflow_instance_id) {
                    throw new LogicException('The original Shipment owner changed.');
                }
                return;
            }
            $this->db->table('example_machine_subjects')
                ->insert([
                    'id' => $id,
                    'scope' => Ownership::SHIPMENT_APPLICATION,
                    'subject' => $childSubject,
                    'principal' => $this->ownership->principal,
                    'authority_version' => MachineAuthority::grant($profile),
                    'state' => 'received',
                    'revision' => 1,
                    'cancellation_requested' => (bool) $parent->cancellation_requested,
                    'parent_subject_id' => $parent->id,
                    'profile' => $parent->profile,
                    'profile_version' => $parent->profile_version,
                    'native_instance_id' => $actual->workflow_instance_id,
                    'original_native_run_id' => $actual->id,
                    'enabled' => (bool) $parent->enabled,
                    'prevalidated' => false,
                    'active_binding_id' => null,
                ]);
        });
        return $this->authority->initialization(
            $this->db->table('example_machine_subjects')
                ->where('id', $id)
                ->sole(),
            $rootRun,
            'shipment'
        );
    }
}
