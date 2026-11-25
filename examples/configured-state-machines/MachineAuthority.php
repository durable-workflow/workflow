<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ConfiguredStateMachines;

use Illuminate\Database\ConnectionInterface;
use LogicException;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\Execution\OperationContext;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\StateMachine\Binding\BindingIdentity;
use Workflow\V2\StateMachine\Definition\PolicyProfile;
use Workflow\V2\StateMachine\Execution\ExecutionReference;

/** Original native/business ownership and current host authorization; no workflow replay calls. */
final class MachineAuthority
{
    public readonly Ownership $ownership;

    public function __construct(
        public readonly ConnectionInterface $db,
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

    public function actor(WorkflowRun $actual, ?ExecutionReference $execution = null): array
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
        $row = match ($actual->workflow_class) {
            Order::class => $this->orderActor($actual, $root),
            Shipment::class => $this->shipmentActor($actual, $root, $args),
            Cancellation::class => $this->cancellationActor($actual, $root, $args, $subject),
        };
        $profile = $this->profile($row);
        $this->assertGrant($row, $profile);
        if ($execution !== null && ($execution->scope !== $row->scope || $execution->subject !== $row->subject
            || $execution->policyFingerprint !== $profile->fingerprint()
            || ! str_starts_with($execution->executionId, $actual->id . '.'))) {
            throw new LogicException('The original execution/policy does not belong to this native owner.');
        }
        return [$row, $profile];
    }

    public function root(string $subject): object
    {
        $this->ownership->authorize($subject, $this->ownership->principal);
        $row = $this->db->table('example_machine_subjects')
            ->where('scope', Ownership::APPLICATION)->where('subject', $subject)->sole();
        $this->assertGrant($row, Catalogue::order($row->profile, $row->profile_version));
        return $row;
    }

    public function profile(object $row): PolicyProfile
    {
        return $row->parent_subject_id === null ? Catalogue::order($row->profile, $row->profile_version)
            : Catalogue::shipment($row->profile, $row->profile_version);
    }

    public function assertGrant(object $row, PolicyProfile $profile): void
    {
        if ($row->principal !== $this->ownership->principal || $row->authority_version !== self::grant($profile)) {
            throw new LogicException('The current business authority or pinned policy is unavailable.');
        }
    }

    public function authorizeBinding(BindingIdentity $binding, string $principal, string $operation): void
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
        $profile = $this->profile($row);
        if ($profile->definition !== null) {
            // The catalog also pins the child's registered definition, not only its machine/version names.
            Catalogue::machines()->assertBinding(
                $profile,
                $binding,
                Catalogue::shipment($row->profile, $row->profile_version)
            );
        }
    }

    public function authorizeScope(ScopeIdentity $scope, string $principal, string $operation): void
    {
        $this->unlocked();
        if (! in_array($operation, ['register', 'forward', 'compensate', 'participate', 'inspect', 'request'], true)) {
            throw new LogicException('The scope operation is outside the current host authority.');
        }
        $subject = explode('.', $scope->key)[0];
        $this->ownership->authorize($subject, $principal);
        $row = $this->root($subject);
        $rootRun = explode('.', $scope->generation)[0];
        if ($row->original_native_run_id !== $rootRun
            || ($scope->key === $subject . '.order' && $scope->generation !== $row->original_native_run_id)) {
            throw new LogicException('The retained scope belongs to another original business generation.');
        }
    }

    public function authorizeEffect(OperationContext $context, string $operation): array
    {
        $this->authorizeScope($context->scope, $this->ownership->principal, $operation);
        $execution = self::reference($context->obligation->input['execution']);
        $root = $this->root(explode('.', $context->scope->key)[0]);
        $child = $context->scope->key !== $root->subject . '.order';
        $row = $child ? $this->db->table('example_machine_subjects')
            ->where('scope', Ownership::SHIPMENT_APPLICATION)
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

    public function owner(OperationContext $context): bool
    {
        $input = $context->obligation->input;
        $this->authorizeScope($context->scope, $this->ownership->principal, 'inspect');
        $root = $this->root($input['subject']);
        $row = $input['role'] === 'order' ? $root : $this->db->table('example_machine_subjects')
            ->where('scope', Ownership::SHIPMENT_APPLICATION)->where('subject', $context->scope->key)->sole();
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

    public function initialization(object $row, string $rootRun, string $role): array
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

    public function childEntry(WorkflowRun $actual): string
    {
        return self::reference($actual->workflowArguments()[2])->executionId;
    }

    public static function grant(PolicyProfile $profile): string
    {
        return Ownership::VERSION . ':' . $profile->fingerprint();
    }

    public function namespace(WorkflowRun $run): void
    {
        if ($run->namespace !== Ownership::NAMESPACE) {
            throw new LogicException('The native namespace is foreign.');
        }
    }

    public function unlocked(): void
    {
        if ($this->db->transactionLevel() !== 0) {
            throw new LogicException('The machine boundary must own its SQL transaction.');
        }
    }

    private function orderActor(WorkflowRun $actual, object $root): object
    {
        if ($root->original_native_run_id !== $actual->id) {
            throw new LogicException('This is not the original Order owner.');
        }
        return $root;
    }

    private function shipmentActor(WorkflowRun $actual, object $root, array $args): object
    {
        $begin = self::reference($args[2] ?? []);
        $binding = Ownership::binding($begin, $args[1] ?? '', $root->profile, $root->profile_version);
        if ($actual->workflow_instance_id !== 'dw-bound-child-' . $binding->key() || $root->original_native_run_id !== $binding->parentRunId) {
            throw new LogicException('This is not the original Shipment state entry.');
        }
        $row = $this->db->table('example_machine_subjects')
            ->where('native_instance_id', $actual->workflow_instance_id)
            ->sole();
        if ($row->original_native_run_id !== $actual->id || $row->parent_subject_id !== $root->id) {
            throw new LogicException('The admitted Shipment owner changed.');
        }
        return $row;
    }

    private function cancellationActor(WorkflowRun $actual, object $root, array $args, string $subject): object
    {
        $rootRun = $args[1] ?? '';
        $requestKey = $args[2] ?? '';
        if (! is_string($rootRun) || ! is_string($requestKey)
            || $root->original_native_run_id !== $rootRun
            || $actual->workflow_instance_id !== CancellationStore::cancellationInstance(
                $subject,
                $rootRun,
                $requestKey
            )) {
            throw new LogicException('The finalizer belongs to another original business cancellation.');
        }
        app(CancellationStore::class)->cancellationIntent($root, $rootRun, $requestKey);
        $instance = WorkflowInstance::query()->findOrFail($actual->workflow_instance_id);
        if ((int) $instance->run_count !== 1 || $instance->current_run_id !== $actual->id) {
            throw new LogicException('This example does not transfer cancellation commit ownership.');
        }
        return $root;
    }
}
