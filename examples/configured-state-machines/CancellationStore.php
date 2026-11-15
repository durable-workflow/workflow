<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ConfiguredStateMachines;

use DurableWorkflow\Examples\BusinessStateMachines\SqlBusinessStore;
use Illuminate\Database\ConnectionInterface;
use LogicException;
use Workflow\V2\Compensation\Definition\PortableDescriptor;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\Compensation\Recovery\ScopeRecovery;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\StartOptions;
use Workflow\V2\StateMachine\Execution\TransitionStatus;
use Workflow\V2\StateMachine\Transition\SnapshotPhase;
use Workflow\V2\StateMachine\Transition\TransitionContext;
use Workflow\V2\Support\CurrentRunResolver;
use Workflow\V2\WorkflowStub;

/** Accepted cancellation fences and verified original-scope business-state finalization. */
final class CancellationStore
{
    private readonly ConnectionInterface $db;

    public function __construct(
        private readonly MachineAuthority $authority
    ) {
        $this->db = $authority->db;
    }

    public function requestCancellation(string $subject, string $rootRun, string $requestKey): array
    {
        $this->authority->unlocked();
        $this->authority->ownership->authorize($subject, $this->authority->ownership->principal);
        PortableDescriptor::identifier($requestKey, 'business cancellation request');
        $actual = WorkflowRun::query()->findOrFail($rootRun);
        app(MachineStore::class)->initialize($actual, $subject, $rootRun);
        $root = $this->authority->root($subject);
        $id = self::cancellationId($subject, $rootRun, $requestKey);
        $hash = $this->cancellationHash($subject, $rootRun, $requestKey);
        $this->db->transaction(function () use ($root, $rootRun, $id, $hash): void {
            $row = $this->db->table('example_machine_subjects')
                ->where('id', $root->id)
                ->lockForUpdate()
                ->sole();
            $this->authority->assertGrant($row, $this->authority->profile($row));
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
                $this->authority->ownership->principal,
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
        [$row] = $this->authority->actor($actual);
        if ($actual->workflow_class !== Cancellation::class || $actual->workflowArguments() !== [
            $subject,
            $rootRun,
            $requestKey,
        ]) {
            throw new LogicException('The finalizer initialization differs from its native admission.');
        }
        return [
            ...$this->authority->initialization($row, $rootRun, 'finalizer'),
            'nativeRun' => $actual->id,
        ];
    }

    public function finishCancellation(string $subject, string $rootRun, string $requestKey): array
    {
        $this->authority->unlocked();
        $root = $this->authority->root($subject);
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
        $this->authority->actor($run);
        return [
            'status' => $run->status->value,
            'runId' => $run->id,
        ];
    }

    public function cancellationIntent(object $root, string $rootRun, string $requestKey): object
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

    public static function cancellationInstance(string $subject, string $rootRun, string $requestKey): string
    {
        return 'configured-cancel-' . self::cancellationId($subject, $rootRun, $requestKey);
    }

    public function commit(TransitionContext $context, object $row): array
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
            $this->authority->assertGrant($current, $this->authority->profile($current));
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

    private static function cancellationId(string $subject, string $rootRun, string $requestKey): string
    {
        return hash('sha256', PortableDescriptor::encode([$subject, $rootRun, $requestKey]));
    }

    private function cancellationHash(string $subject, string $rootRun, string $requestKey): string
    {
        return hash(
            'sha256',
            PortableDescriptor::encode([$subject, $rootRun, $requestKey, $this->authority->ownership->principal])
        );
    }
}
