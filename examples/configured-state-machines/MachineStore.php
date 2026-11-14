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
use Workflow\V2\Compensation\Recovery\ScopeRecovery;
use Workflow\V2\Compensation\Security\ScopeAuthorization;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\StartOptions;
use Workflow\V2\StateMachine\Binding\BindingIdentity;
use Workflow\V2\StateMachine\Binding\ChildBinding;
use Workflow\V2\StateMachine\Binding\ChildDefinitionRegistry;
use Workflow\V2\StateMachine\Binding\ChildReservation;
use Workflow\V2\StateMachine\Binding\ChildReservationStore;
use Workflow\V2\StateMachine\Definition\PolicyProfile;
use Workflow\V2\StateMachine\Definition\TransitionDefinition;
use Workflow\V2\StateMachine\Execution\ExecutionReference;
use Workflow\V2\StateMachine\Execution\TransitionStatus;
use Workflow\V2\StateMachine\Rules\Decision;
use Workflow\V2\StateMachine\Rules\RecordedFacts;
use Workflow\V2\StateMachine\Rules\Verdict;
use Workflow\V2\StateMachine\Transition\FactSnapshot;
use Workflow\V2\StateMachine\Transition\SnapshotPhase;
use Workflow\V2\StateMachine\Transition\TransitionContext;
use Workflow\V2\Support\CurrentRunResolver;
use Workflow\V2\WorkflowStub;

/** Application-owned recorded boundaries. No method is called directly from workflow replay. */
final class MachineStore
{
    public readonly Ownership $ownership;

    /**
     * @param list<string> $subjects
     */
    public function __construct(
        private readonly ConnectionInterface $db,
        string $principal,
        array $subjects
    ) {
        $this->ownership = new Ownership(
            $principal,
            $subjects,
            fn (BindingIdentity $binding, string $who, string $operation) => $this->authorizeBinding(
                $binding,
                $who,
                $operation
            )
        );
    }

    /**
     * @param list<string> $subjects
     */
    public static function configure(Container $app, ConnectionInterface $db, string $principal, array $subjects): self
    {
        $store = new self($db, $principal, $subjects);
        $app->instance(self::class, $store);
        $app->instance(ScopeAuthorization::class, $store->ownership->scopeAuthorization(
            static fn (ScopeIdentity $scope, string $who, string $operation) => $store->authorizeScope(
                $scope,
                $who,
                $operation
            )
        ));
        $app->instance(ChildReservationStore::class, new ChildReservationStore($store->ownership));
        $children = [];
        foreach (['prepare', 'imported'] as $integration) {
            $children[Catalogue::shipment($integration)->fingerprint()] = Shipment::class;
        }
        $app->instance(ChildDefinitionRegistry::class, new ChildDefinitionRegistry([
            Ownership::CHILD_TYPE => $children,
        ]));
        $handlers = [
            'configured.owner' => [
                '1' => new OwnerOperation($store),
            ],
        ];
        foreach (['reserve', 'prepare', 'import', 'deliver'] as $action) {
            $handlers['configured.sql.' . $action]['1'] = new SqlEffectOperation(
                $db,
                $action,
                static fn (OperationContext $context, string $operation): array => $store->authorizeEffect(
                    $context,
                    $operation
                )
            );
        }
        $app->instance(HandlerRegistry::class, new HandlerRegistry($handlers));
        return $store;
    }

    /**
     * Trusted application setup, before native start; never accepts an HTTP-selected principal/profile.
     */
    public function seed(string $subject, string $integration, bool $enabled = true, bool $prevalidated = false): void
    {
        $this->unlocked();
        $this->ownership->authorize($subject, $this->ownership->principal);
        $profile = Catalogue::order($integration);
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
                'authority_version' => self::grant($profile),
                'state' => 'received',
                'revision' => 1,
                'cancellation_requested' => false,
                'parent_subject_id' => null,
                'profile' => $integration,
                'profile_version' => '1',
                'native_instance_id' => Ownership::instance($subject),
                'original_native_run_id' => null,
                'enabled' => $enabled,
                'prevalidated' => $prevalidated,
                'active_binding_id' => null,
            ]);
    }

    public function initialize(WorkflowRun $actual, string $subject, string $rootRun, ?array $begin = null): array
    {
        $this->unlocked();
        $this->ownership->authorize($subject, $this->ownership->principal);
        $this->namespace($actual);
        if (($begin === null && ($actual->workflow_class !== Order::class || $actual->id !== $rootRun
            || $actual->workflow_instance_id !== Ownership::instance($subject)))
            || ($begin !== null && $actual->workflow_class !== Shipment::class)) {
            throw new LogicException('Initialization belongs to another native owner.');
        }
        $root = $this->root($subject);
        if ($begin === null) {
            $instance = WorkflowInstance::query()->findOrFail($actual->workflow_instance_id);
            if ((int) $instance->run_count !== 1 || $instance->current_run_id !== $actual->id) {
                throw new LogicException('This example does not transfer root ownership to a successor run.');
            }
            $this->db->transaction(function () use ($root, $actual): void {
                $row = $this->db->table('example_machine_subjects')
                    ->where('id', $root->id)
                    ->lockForUpdate()
                    ->sole();
                $this->assertGrant($row, Catalogue::order($row->profile, $row->profile_version));
                if ($row->original_native_run_id !== null && $row->original_native_run_id !== $actual->id) {
                    throw new LogicException('The original business generation already has another owner.');
                }
                $this->db->table('example_machine_subjects')
                    ->where('id', $row->id)
                    ->update([
                        'original_native_run_id' => $actual->id,
                    ]);
            });
            $root = $this->root($subject);
            return $this->initialization($root, $rootRun, 'order');
        }
        $execution = self::reference($begin);
        $binding = Ownership::binding($execution, $rootRun, $root->profile);
        $scope = Ownership::childScope($execution);
        if ($actual->workflow_class !== Shipment::class || $actual->workflow_instance_id !== 'dw-bound-child-' . $binding->key()
            || $root->original_native_run_id !== $rootRun
            || (new ScopeRepository())->metadata($scope)['original_run_id'] !== $actual->id) {
            throw new LogicException('Child initialization requires its exact genuine native admission.');
        }
        $profile = Catalogue::shipment($root->profile, $root->profile_version);
        $childSubject = $scope->key;
        $child = new ExecutionReference(
            Ownership::APPLICATION . '.shipment',
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
            $this->assertGrant($parent, Catalogue::order($parent->profile, $parent->profile_version));
            $existing = $this->db->table('example_machine_subjects')
                ->where('id', $id)
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                $this->assertGrant($existing, $profile);
                if ($existing->original_native_run_id !== $actual->id || $existing->native_instance_id !== $actual->workflow_instance_id) {
                    throw new LogicException('The original Shipment owner changed.');
                }
                return;
            }
            $this->db->table('example_machine_subjects')
                ->insert([
                    'id' => $id,
                    'scope' => Ownership::APPLICATION . '.shipment',
                    'subject' => $childSubject,
                    'principal' => $this->ownership->principal,
                    'authority_version' => self::grant($profile),
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
        return $this->initialization(
            $this->db->table('example_machine_subjects')
                ->where('id', $id)
                ->sole(),
            $rootRun,
            'shipment'
        );
    }

    public function execution(WorkflowRun $actual, string $transition): array
    {
        [$row, $profile] = $this->actor($actual);
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
        $factsJson = PortableDescriptor::encode($facts->toArray());
        $explanationJson = PortableDescriptor::encode([
            'target' => $target->toArray(),
            'permission' => $permission->toArray(),
        ]);
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
        $execution = $context->execution();
        $id = hash('sha256', PortableDescriptor::encode($execution->toArray()));
        $hash = hash('sha256', PortableDescriptor::encode($request));
        return $this->db->transaction(function () use ($context, $row, $execution, $id, $hash): array {
            $current = $this->db->table('example_machine_subjects')
                ->where('id', $row->id)
                ->lockForUpdate()
                ->sole();
            $this->assertGrant($current, $this->profile($current));
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
        if ($context->transition->key === 'cancel') {
            return $this->finalizeCancellation($context, $row);
        }
        $store = new SqlBusinessStore($this->db, function (
            ExecutionReference $execution,
            string $principal,
            string $operation,
            ?TransitionContext $proposed
        ) use ($context, $row): string {
            $this->ownership->authorize(explode('.', $row->subject)[0], $principal);
            if ($operation !== 'commit' || $proposed !== $context || ! $execution->equals($context->execution())
                || ! $context->snapshot->target->satisfied()) {
                throw new LogicException('The protected commit lacks its original satisfied assessment.');
            }
            return self::grant($this->profile($row));
        });
        $result = $store->commit($context, $this->ownership->principal);
        return [
            'execution' => $result->execution->toArray(),
            'status' => $result->status->value,
            'reason' => $result->reason,
        ];
    }

    public function binding(WorkflowRun $actual, array $value): ?array
    {
        [$row] = $this->actor($actual);
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
        [$row] = $this->actor($actual);
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
        [$row] = $this->actor($actual);
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
            $this->assertGrant($parent, $this->profile($parent));
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
        [$row] = $this->actor($actual);
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
            $this->assertGrant($parent, $this->profile($parent));
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
        $execution = self::reference($value);
        [$row, $profile] = $this->actor($actual, $execution);
        Catalogue::transition($profile, $transitionKey);
        if ($execution->executionId !== $actual->id . '.' . $transitionKey . '.' . $execution->sourceRevision) {
            throw new LogicException('Step verification requires its original transition execution.');
        }
        $root = $this->root(explode('.', $row->subject)[0]);
        $scope = $row->parent_subject_id === null ? Ownership::scope($row->subject, $root->original_native_run_id)
            : new ScopeIdentity(Ownership::APPLICATION, Ownership::NAMESPACE, $row->subject, $this->childEntry(
                $actual
            ));
        $metadata = (new ScopeRepository())->inspect($scope);
        if ($metadata['original_run_id'] !== $actual->id || ! in_array(
            $metadata['status'],
            ['requested', 'verified'],
            true
        )) {
            throw new LogicException('Step compensation requires its original closed forward gate.');
        }
        $keys = $transitionKey === 'begin' ? [...array_map(
            static fn (string $action): string => $execution->executionId . '.' . $action,
            Catalogue::actions($profile->profile)
        ), $execution->executionId . '.shipment']
            : ($transitionKey === 'deliver' ? [$execution->executionId . '.deliver'] : []);
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

    public function authorizeEffect(OperationContext $context, string $operation): array
    {
        $this->authorizeScope($context->scope, $this->ownership->principal, $operation);
        $execution = self::reference($context->obligation->input['execution']);
        $root = $this->root(explode('.', $context->scope->key)[0]);
        $child = $context->scope->key !== $root->subject . '.order';
        $row = $child ? $this->db->table('example_machine_subjects')
            ->where('scope', Ownership::APPLICATION . '.shipment')
            ->where('subject', $context->scope->key)
            ->sole() : $root;
        $profile = $this->profile($row);
        $this->assertGrant($row, $profile);
        $action = $context->obligation->input['action'];
        if ($row->original_native_run_id !== $context->originalRunId || $execution->scope !== $row->scope
            || $execution->subject !== $row->subject || $execution->policyFingerprint !== $profile->fingerprint()
            || $context->obligation->key !== $execution->executionId . '.' . $action
            || $execution->executionId !== $context->originalRunId . '.' . ($child ? 'deliver' : 'begin') . '.' . $execution->sourceRevision
            || ! in_array($action, $child ? ['deliver'] : Catalogue::actions($root->profile), true)) {
            throw new LogicException('The original effect/transition relationship was substituted.');
        }
        return [
            'principal' => $this->ownership->principal,
            'version' => self::grant($profile),
        ];
    }

    public static function reference(array $value): ExecutionReference
    {
        $keys = array_keys($value);
        sort($keys);
        if ($keys !== ['executionId', 'policyFingerprint', 'scope', 'sourceRevision', 'sourceState', 'subject']) {
            throw new LogicException('The execution reference is malformed.');
        }
        foreach ($value as $part) {
            if (! is_string($part)) {
                throw new LogicException('Execution identity requires string fields.');
            }
        }
        return new ExecutionReference(
            $value['scope'],
            $value['subject'],
            $value['executionId'],
            $value['policyFingerprint'],
            $value['sourceState'],
            $value['sourceRevision']
        );
    }

    /**
     * No provider call exists for this marker. It is an original-owner obligation, not a fabricated verified ledger.
     */
    public function owner(OperationContext $context): bool
    {
        $input = $context->obligation->input;
        $this->authorizeScope($context->scope, $this->ownership->principal, 'inspect');
        $root = $this->root($input['subject']);
        $row = $input['role'] === 'order' ? $root : $this->db->table('example_machine_subjects')
            ->where('scope', Ownership::APPLICATION . '.shipment')->where('subject', $context->scope->key)->sole();
        $expectedScope = $input['role'] === 'order' ? Ownership::scope($input['subject'], $input['rootRun'])
            : new ScopeIdentity(Ownership::APPLICATION, Ownership::NAMESPACE, $row->subject, $input['entry']);
        if (! in_array($input['role'], ['order', 'shipment'], true)
            || $context->obligation->key !== 'owner' || $context->obligation->handlerType !== 'configured.owner'
            || $context->obligation->handlerVersion !== '1'
            || ($input['role'] === 'order' && $input['entry'] !== '')
            || $row->original_native_run_id !== $context->originalRunId || $input['nativeRun'] !== $context->originalRunId
            || $root->original_native_run_id !== $input['rootRun']
            || $expectedScope->toArray() !== $context->scope->toArray()) {
            throw new LogicException('The no-effect obligation does not belong to this original owner.');
        }
        $this->assertGrant($row, $this->profile($row));
        return (bool) $row->cancellation_requested;
    }

    /**
     * Host request: fence SQL providers and completion before routing native compensation.
     */
    public function requestCancellation(string $subject, string $rootRun, string $requestKey): array
    {
        $this->unlocked();
        $this->ownership->authorize($subject, $this->ownership->principal);
        PortableDescriptor::identifier($requestKey, 'business cancellation request');
        $actual = WorkflowRun::query()->findOrFail($rootRun);
        $this->initialize($actual, $subject, $rootRun);
        $root = $this->root($subject);
        $id = self::cancellationId($subject, $rootRun, $requestKey);
        $hash = $this->cancellationHash($subject, $rootRun, $requestKey);
        $this->db->transaction(function () use ($root, $rootRun, $id, $hash): void {
            $row = $this->db->table('example_machine_subjects')
                ->where('id', $root->id)
                ->lockForUpdate()
                ->sole();
            $this->assertGrant($row, $this->profile($row));
            if ($row->original_native_run_id !== $rootRun) {
                throw new LogicException('Business cancellation belongs to a different original run.');
            }
            $existing = $this->db->table('configured_machine_cancellations')
                ->where('id', $id)
                ->first();
            if ($existing !== null && ($existing->subject_id !== $row->id || $existing->original_run_id !== $rootRun
                || $existing->request_sha256 !== $hash)) {
                throw new LogicException('The cancellation request was reused with different content.');
            }
            if ($existing === null) {
                $this->db->table('configured_machine_cancellations')
                    ->insert([
                        'id' => $id,
                        'subject_id' => $row->id,
                        'original_run_id' => $rootRun,
                        'request_sha256' => $hash,
                    ]);
            }
            // Child initialization also locks this parent first, so later children inherit the same fence.
            $children = $this->db->table('example_machine_subjects')
                ->where('parent_subject_id', $row->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $this->db->table('example_machine_subjects')
                ->whereIn('id', [$row->id, ...$children->pluck('id')->all()])
                ->update([
                    'cancellation_requested' => true,
                ]);
        });
        try {
            $route = app(ScopeRecovery::class)->request(
                Ownership::scope($subject, $rootRun),
                $requestKey,
                $this->ownership->principal,
                'business-cancellation'
            );
        } catch (LogicException $error) {
            if ($error->getMessage() !== 'The exact compensation scope generation is unavailable.') {
                throw $error;
            }
            return [
                'status' => 'unresolved',
                'reason' => 'original_scope_not_initialized',
                'requestId' => $id,
            ];
        }
        return [
            'status' => 'requested',
            'requestId' => $id,
            'route' => $route,
        ];
    }

    public function initializeFinalizer(
        WorkflowRun $actual,
        string $subject,
        string $rootRun,
        string $requestKey
    ): array {
        [$row] = $this->actor($actual);
        if ($actual->workflow_class !== Cancellation::class || $actual->workflowArguments() !== [
            $subject,
            $rootRun,
            $requestKey,
        ]) {
            throw new LogicException('The finalizer initialization differs from its native admission.');
        }
        return [
            ...$this->initialization($row, $rootRun, 'finalizer'),
            'nativeRun' => $actual->id,
        ];
    }

    /**
     * Separate host milestone, after recovery. No new effect or scope is admitted here.
     */
    public function finishCancellation(string $subject, string $rootRun, string $requestKey): array
    {
        $this->unlocked();
        $root = $this->root($subject);
        $this->cancellationIntent($root, $rootRun, $requestKey);
        $metadata = (new ScopeRepository())->metadata(Ownership::scope($subject, $rootRun));
        if ($metadata['original_run_id'] !== $rootRun || $metadata['status'] !== 'verified') {
            return [
                'status' => 'pending',
                'reason' => 'original_compensation_unverified',
            ];
        }
        $instanceId = self::cancellationInstance($subject, $rootRun, $requestKey);
        $instance = WorkflowInstance::query()->find($instanceId);
        $run = $instance === null ? null : CurrentRunResolver::forInstance($instance);
        if ($run === null) {
            $stub = WorkflowStub::make(Cancellation::class, $instanceId, Ownership::NAMESPACE);
            $stub->start(StartOptions::returnExistingActive(), $subject, $rootRun, $requestKey);
            return [
                'status' => 'started',
                'runId' => $stub->runId(),
            ];
        }
        $this->actor($run);
        return [
            'status' => $run->status->value,
            'runId' => $run->id,
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
        [$execution, $transition, $row] = $this->request(
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
            $decision($snapshot['permission'])
        )), $row];
    }

    private function request(WorkflowRun $actual, array $request, array $keys): array
    {
        $execution = self::reference($request['execution'] ?? []);
        [$row, $profile] = $this->actor($actual, $execution);
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

    private function actor(WorkflowRun $actual, ?ExecutionReference $execution = null): array
    {
        $this->unlocked();
        $this->namespace($actual);
        $args = $actual->workflowArguments();
        $subject = $args[0] ?? '';
        if (! is_string($subject)) {
            throw new LogicException('The native workflow has no original business subject.');
        }
        $this->ownership->authorize($subject, $this->ownership->principal);
        if (! in_array($actual->workflow_class, [Order::class, Shipment::class, Cancellation::class], true)
            || ($actual->workflow_class === Order::class && $actual->workflow_instance_id !== Ownership::instance(
                $subject
            ))) {
            throw new LogicException('This native owner is outside the configured machine.');
        }
        $root = $this->root($subject);
        if ($actual->workflow_class === Order::class) {
            if ($actual->workflow_instance_id !== Ownership::instance(
                $subject
            ) || $root->original_native_run_id !== $actual->id) {
                throw new LogicException('This is not the original Order owner.');
            }
            $row = $root;
        } elseif ($actual->workflow_class === Shipment::class) {
            $begin = self::reference($args[2] ?? []);
            $binding = Ownership::binding($begin, $args[1] ?? '', $root->profile);
            if ($actual->workflow_instance_id !== 'dw-bound-child-' . $binding->key() || $root->original_native_run_id !== $binding->parentRunId) {
                throw new LogicException('This is not the original Shipment state entry.');
            }
            $row = $this->db->table('example_machine_subjects')
                ->where('native_instance_id', $actual->workflow_instance_id)
                ->sole();
            if ($row->original_native_run_id !== $actual->id || $row->parent_subject_id !== $root->id) {
                throw new LogicException('The admitted Shipment owner changed.');
            }
        } elseif ($actual->workflow_class === Cancellation::class) {
            $rootRun = $args[1] ?? '';
            $requestKey = $args[2] ?? '';
            if (! is_string($rootRun) || ! is_string($requestKey)
                || $root->original_native_run_id !== $rootRun
                || $actual->workflow_instance_id !== self::cancellationInstance($subject, $rootRun, $requestKey)) {
                throw new LogicException('The finalizer belongs to another original business cancellation.');
            }
            $this->cancellationIntent($root, $rootRun, $requestKey);
            $instance = WorkflowInstance::query()->findOrFail($actual->workflow_instance_id);
            if ((int) $instance->run_count !== 1 || $instance->current_run_id !== $actual->id) {
                throw new LogicException('This example does not transfer cancellation commit ownership.');
            }
            $row = $root;
        } else {
            throw new LogicException('The native workflow is not a configured machine owner.');
        }
        $profile = $this->profile($row);
        $this->assertGrant($row, $profile);
        if ($execution !== null && ($execution->scope !== $row->scope || $execution->subject !== $row->subject
            || $execution->policyFingerprint !== $profile->fingerprint()
            || ! str_starts_with($execution->executionId, $actual->id . '.'))) {
            throw new LogicException('The original execution/policy does not belong to this native owner.');
        }
        return [$row, $profile];
    }

    private function root(string $subject): object
    {
        $this->ownership->authorize($subject, $this->ownership->principal);
        $row = $this->db->table('example_machine_subjects')
            ->where('scope', Ownership::APPLICATION)->where('subject', $subject)->sole();
        $this->assertGrant($row, Catalogue::order($row->profile, $row->profile_version));
        return $row;
    }

    private function profile(object $row): PolicyProfile
    {
        return $row->parent_subject_id === null ? Catalogue::order($row->profile, $row->profile_version)
            : Catalogue::shipment($row->profile, $row->profile_version);
    }

    private function assertGrant(object $row, PolicyProfile $profile): void
    {
        if ($row->principal !== $this->ownership->principal || $row->authority_version !== self::grant($profile)) {
            throw new LogicException('The current business authority or pinned policy is unavailable.');
        }
    }

    private function authorizeBinding(BindingIdentity $binding, string $principal, string $operation): void
    {
        $this->unlocked();
        $this->ownership->authorize($binding->parentExecution->subject, $principal);
        $row = $this->root($binding->parentExecution->subject);
        if ($row->original_native_run_id !== $binding->parentRunId
            || $binding->parentExecution->policyFingerprint !== Catalogue::order(
                $row->profile,
                $row->profile_version
            )->fingerprint()
            || ($operation === 'reserve' && ((bool) $row->cancellation_requested
                || $row->state !== $binding->parentExecution->sourceState
                || (string) $row->revision !== $binding->parentExecution->sourceRevision))) {
            throw new LogicException('The live business owner refused this child binding.');
        }
    }

    private function authorizeScope(ScopeIdentity $scope, string $principal, string $operation): void
    {
        $this->unlocked();
        $subject = explode('.', $scope->key)[0];
        $this->ownership->authorize($subject, $principal);
        $row = $this->root($subject);
        $rootRun = explode('.', $scope->generation)[0];
        if ($row->original_native_run_id !== $rootRun
            || ($scope->key === $subject . '.order' && $scope->generation !== $row->original_native_run_id)) {
            throw new LogicException('The retained scope belongs to another original business generation.');
        }
    }

    private function initialization(object $row, string $rootRun, string $role): array
    {
        return [
            'subject' => explode('.', $row->subject)[0],
            'integration' => $row->profile,
            'version' => $row->profile_version,
            'principal' => $this->ownership->principal,
            'rootRun' => $rootRun,
            'role' => $role,
            'nativeRun' => $row->original_native_run_id,
        ];
    }

    private function childEntry(WorkflowRun $actual): string
    {
        return self::reference($actual->workflowArguments()[2])->executionId;
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

    private static function grant(PolicyProfile $profile): string
    {
        return Ownership::VERSION . ':' . $profile->fingerprint();
    }

    private function namespace(WorkflowRun $run): void
    {
        if ($run->namespace !== Ownership::NAMESPACE) {
            throw new LogicException('The native namespace is foreign.');
        }
    }

    private function unlocked(): void
    {
        if ($this->db->transactionLevel() !== 0) {
            throw new LogicException('The machine boundary must own its SQL transaction.');
        }
    }

    private function cancellationIntent(object $root, string $rootRun, string $requestKey): object
    {
        PortableDescriptor::identifier($requestKey, 'business cancellation request');
        $intent = $this->db->table('configured_machine_cancellations')
            ->where('id', self::cancellationId($root->subject, $rootRun, $requestKey))->sole();
        if ($intent->subject_id !== $root->id || $intent->original_run_id !== $rootRun
            || $root->original_native_run_id !== $rootRun || ! (bool) $root->cancellation_requested
            || $intent->request_sha256 !== $this->cancellationHash($root->subject, $rootRun, $requestKey)) {
            throw new LogicException('The original business cancellation is not authorized.');
        }
        return $intent;
    }

    private static function cancellationId(string $subject, string $rootRun, string $requestKey): string
    {
        return hash('sha256', PortableDescriptor::encode([$subject, $rootRun, $requestKey]));
    }

    private static function cancellationInstance(string $subject, string $rootRun, string $requestKey): string
    {
        return 'configured-cancel-' . self::cancellationId($subject, $rootRun, $requestKey);
    }

    private function cancellationHash(string $subject, string $rootRun, string $requestKey): string
    {
        return hash(
            'sha256',
            PortableDescriptor::encode([$subject, $rootRun, $requestKey, $this->ownership->principal])
        );
    }

    private function finalizeCancellation(TransitionContext $context, object $row): array
    {
        $execution = $context->execution();
        if (! $context->snapshot->target->satisfied() || ! in_array(
            $context->snapshot->phase,
            [SnapshotPhase::Before, SnapshotPhase::After],
            true
        ) || $execution->sourceState !== $context->snapshot->state
            || $execution->sourceRevision !== $context->snapshot->revision) {
            throw new LogicException('Business cancellation requires its matching satisfied recorded proof.');
        }
        $proof = (new ScopeRepository())->metadata(Ownership::scope($row->subject, $row->original_native_run_id));
        $id = SqlBusinessStore::decisionId($execution, 'transition', $execution->executionId);
        $hash = hash('sha256', PortableDescriptor::encode([$execution->toArray(), $context->snapshot->snapshotId,
            $context->snapshot->target->toArray(), get_object_vars($context->transition)]));
        return $this->db->transaction(function () use ($context, $row, $execution, $proof, $id, $hash): array {
            $current = $this->db->table('example_machine_subjects')
                ->where('id', $row->id)
                ->lockForUpdate()
                ->sole();
            $this->assertGrant($current, $this->profile($current));
            $existing = $this->db->table('example_machine_decisions')
                ->where('id', $id)
                ->first();
            if ($existing !== null) {
                if ($existing->subject_id !== $current->id || $existing->request_sha256 !== $hash) {
                    throw new LogicException('The cancellation decision was reused with different proof.');
                }
                return [
                    'execution' => $execution->toArray(),
                    'status' => $existing->status,
                    'reason' => $existing->reason,
                ];
            }
            // Verified retained scopes cannot reopen. The same locked business fence prevents every SQL writer
            // from admitting new effects; release may discard evidence, but cannot reverse this verification.
            if ($proof['status'] !== 'verified' || $proof['original_run_id'] !== $current->original_native_run_id
                || ! (bool) $current->cancellation_requested || $current->state !== $execution->sourceState
                || (string) $current->revision !== $execution->sourceRevision || $context->transition->to !== 'cancelled'
                || (int) $current->revision >= PHP_INT_MAX) {
                throw new LogicException('The original business cancellation proof or revision changed.');
            }
            $revision = (int) $current->revision + 1;
            $this->db->table('example_machine_subjects')
                ->where('id', $row->id)
                ->update([
                    'state' => 'cancelled',
                    'revision' => $revision,
                ]);
            $this->db->table('example_machine_decisions')
                ->insert([
                    'id' => $id,
                    'subject_id' => $row->id,
                    'request_sha256' => $hash,
                    'status' => TransitionStatus::Committed->value,
                    'reason' => 'verified_business_cancellation_committed',
                ]);
            $this->db->table('example_machine_publications')
                ->insert([
                    'id' => $id,
                    'subject_id' => $row->id,
                    'payload' => PortableDescriptor::encode([
                        'execution' => $execution->toArray(),
                        'state' => 'cancelled',
                        'revision' => (string) $revision,
                        'scope' => json_decode($proof['identity'], true, flags: JSON_THROW_ON_ERROR),
                        'originalRunId' => $current->original_native_run_id,
                    ]),
                ]);
            return [
                'execution' => $execution->toArray(),
                'status' => TransitionStatus::Committed->value,
                'reason' => 'verified_business_cancellation_committed',
            ];
        });
    }
}
