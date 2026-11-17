<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use LogicException;
use Workflow\V2\Compensation\Admission\NeverAdmittedRoot;
use Workflow\V2\Compensation\Admission\RootBinding;
use Workflow\V2\Compensation\Admission\RootIdentity;
use Workflow\V2\Compensation\Admission\RootReservationStore;
use Workflow\V2\Compensation\Definition\PortableDescriptor;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\Compensation\Recovery\ScopeRecovery;
use Workflow\V2\Compensation\Security\ScopeAuthorization;

/** Original business cancellation exists independently of native owner initialization. */
final readonly class RootCancellationStore
{
    public const VERSION = 'provider-root-cancellation-v1';

    public function __construct(
        private Authority $authority
    ) {
    }

    public function request(string $key, string $requestKey, ?string $recoveryRequestKey = null): array
    {
        if (\Workflow\V2\Support\WorkflowFiberContext::active() || $this->authority->db->transactionLevel() !== 0) {
            throw new LogicException('Root business cancellation requires an unlocked host boundary outside replay.');
        }
        $scopeRequestKey = $recoveryRequestKey ?? $requestKey;
        PortableDescriptor::identifier($scopeRequestKey, 'original root recovery request');
        $identity = $this->authority->roots->identity($key);
        PortableDescriptor::identifier($requestKey, 'original root cancellation request');
        $id = hash('sha256', PortableDescriptor::encode([self::VERSION, $key]));
        $requestHash = hash('sha256', PortableDescriptor::encode([
            self::VERSION, $identity->toArray(), $requestKey, $this->authority->principal,
        ]));
        $this->recordIntent($identity, $id, $requestKey, $requestHash);
        // The business marker fences every writer before native admission is closed.
        $outcome = app(RootReservationStore::class)->closeIntent($identity, $this->authority->principal);
        if ($outcome instanceof NeverAdmittedRoot) {
            return $this->settleStartup($identity, $id, $requestHash, [
                'kind' => 'never_admitted',
                'reservationFingerprint' => $outcome->reservation->fingerprint(),
                'reservedRunId' => $outcome->reservation->originalRunId,
                'closureId' => $outcome->closureId,
            ]);
        }
        return $this->requestAdmitted($outcome, $requestKey, $scopeRequestKey, $id, $requestHash);
    }

    private function requestAdmitted(
        RootBinding $binding,
        string $requestKey,
        string $scopeRequestKey,
        string $id,
        string $requestHash,
    ): array {
        $identity = $binding->reservation->identity;
        $key = $identity->scope->key;
        // Preserve one business request even when a failed scope recovery needs
        // an explicitly authorized successor. An initializer can win at any read.
        $request = app(CancellationStore::class)->request(
            $key,
            $binding->reservation->originalRunId,
            $requestKey,
            $scopeRequestKey
        );
        [$assignment] = $this->authority->assignment($key);
        if ($assignment->root_run !== null) {
            return $this->delegate($binding, $requestKey, $assignment, $request);
        }
        $route = app(ScopeRecovery::class)->request(
            $identity->scope,
            $scopeRequestKey,
            $this->authority->principal,
            'business-cancellation'
        );
        app(ScopeAuthorization::class)->assertAllowed($identity->scope, $this->authority->principal, 'inspect');
        $scope = (new ScopeRepository())->metadata($identity->scope);
        if ($scope['original_run_id'] !== $binding->reservation->originalRunId || $scope['status'] !== 'verified') {
            return [
                'status' => 'requested',
                'requestId' => $id,
                'route' => $route,
            ];
        }
        $result = $this->settleStartup($identity, $id, $requestHash, [
            'kind' => 'verified_original_scope',
            'binding' => $binding->toArray(),
            'scope' => $identity->scope->toArray(),
        ]);
        return $result['status'] === 'original_actor_initialized'
            ? $this->requestAdmitted($binding, $requestKey, $scopeRequestKey, $id, $requestHash) : $result;
    }

    private function recordIntent(RootIdentity $identity, string $id, string $requestKey, string $requestHash): void
    {
        $this->authority->db->transaction(function () use ($identity, $id, $requestKey, $requestHash): void {
            $root = $this->lockRoot($identity);
            $previous = $this->authority->db->table('provider_root_cancellations')
                ->where('id', $id)
                ->first();
            if ($previous !== null) {
                $this->assertRequest($previous, $identity, $requestHash);
            } else {
                if ($root->state === 'cancelled') {
                    throw new LogicException('The original cancellation belongs to another business decision.');
                }
                $this->authority->db->table('provider_root_cancellations')
                    ->insert([
                        'id' => $id,
                        'root_key' => $identity->scope->key,
                        'identity_sha256' => $identity->fingerprint(),
                        'request_key' => $requestKey,
                        'request_sha256' => $requestHash,
                    ]);
            }
            $ids = array_map(
                static fn (string $role): string => Authority::subjectId($identity->scope->key, $role),
                ['order', 'payment', 'shipment']
            );
            $this->authority->db->table('example_machine_subjects')
                ->whereIn('id', $ids)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $this->authority->db->table('example_machine_subjects')
                ->whereIn('id', $ids)
                ->update([
                    'cancellation_requested' => true,
                ]);
        }, 3);
    }

    private function settleStartup(RootIdentity $identity, string $id, string $requestHash, array $proof): array
    {
        // The caller obtained either a permanent closed fence or terminal verified
        // original scope. Neither outcome can become later forward admission.
        if ($this->authority->roots->identity($identity->scope->key)->toArray() !== $identity->toArray()) {
            throw new LogicException('The original startup cancellation intent changed.');
        }
        return $this->authority->db->transaction(function () use ($identity, $id, $requestHash, $proof): array {
            $root = $this->lockRoot($identity);
            $intent = $this->authority->db->table('provider_root_cancellations')
                ->where('id', $id)
                ->sole();
            $this->assertRequest($intent, $identity, $requestHash);
            $result = [
                'version' => 1,
                'status' => 'cancelled',
                'requestId' => $id,
                'businessSubject' => $identity->scope->key,
                'businessGeneration' => $identity->scope->generation,
                'state' => 'cancelled',
                'revision' => '2',
                'proof' => $proof,
            ];
            $payload = PortableDescriptor::encode($result);
            $contentHash = hash('sha256', PortableDescriptor::encode([$requestHash, $result]));
            $previous = $this->authority->db->table('example_machine_decisions')
                ->where('id', $id)
                ->first();
            if ($previous !== null) {
                $publication = $this->authority->db->table('example_machine_publications')
                    ->where('id', $id)
                    ->sole();
                if ($previous->subject_id !== $root->id || $previous->request_sha256 !== $contentHash
                    || $previous->status !== 'committed' || $previous->reason !== self::VERSION
                    || $publication->subject_id !== $root->id || $publication->payload !== $payload
                    || $root->state !== 'cancelled' || (int) $root->revision !== 2 || ! (bool) $root->cancellation_requested) {
                    throw new LogicException('The committed original startup cancellation changed content.');
                }
                return $result;
            }
            $assignment = $this->authority->db->table('provider_machine_assignments')
                ->where('id', $identity->scope->key)
                ->sole();
            if ($assignment->root_run !== null) {
                if ($proof['kind'] === 'never_admitted') {
                    throw new LogicException('A never-admitted root cannot own an initialized business actor.');
                }
                return [
                    'status' => 'original_actor_initialized',
                ];
            }
            $this->assertUnusedBusiness($identity, $root);
            $this->authority->db->table('example_machine_subjects')
                ->where('id', $root->id)
                ->update([
                    'state' => 'cancelled',
                    'revision' => 2,
                ]);
            $this->authority->db->table('example_machine_decisions')
                ->insert([
                    'id' => $id,
                    'subject_id' => $root->id,
                    'request_sha256' => $contentHash,
                    'status' => 'committed',
                    'reason' => self::VERSION,
                ]);
            $this->authority->db->table('example_machine_publications')
                ->insert([
                    'id' => $id,
                    'subject_id' => $root->id,
                    'payload' => $payload,
                ]);
            return $result;
        }, 3);
    }

    private function assertUnusedBusiness(RootIdentity $identity, object $root): void
    {
        $key = $identity->scope->key;
        if ($root->state !== 'received' || (int) $root->revision !== 1 || ! (bool) $root->cancellation_requested
            || $this->authority->db->table('provider_machine_actors')
                ->where('root_key', $key)
                ->exists()
            || $this->authority->db->table('example_machine_decisions')
                ->where('subject_id', $root->id)
                ->exists()
            || $this->authority->db->table('example_machine_publications')
                ->where('subject_id', $root->id)
                ->exists()
            || $this->authority->db->table('example_machine_subjects')
                ->whereIn(
                    'id',
                    [Authority::subjectId($key, 'payment'), Authority::subjectId($key, 'shipment')]
                )->exists()) {
            throw new LogicException('Startup cancellation requires the untouched original business generation.');
        }
    }

    private function lockRoot(RootIdentity $identity): object
    {
        $key = $identity->scope->key;
        $root = $this->authority->db->table('example_machine_subjects')
            ->where('id', Authority::subjectId($key, 'order'))->lockForUpdate()->sole();
        $this->authority->assertGrant($root, $key, 'order');
        $intent = $this->authority->db->table('provider_machine_root_intents')
            ->where('id', $key)
            ->sole();
        if ($intent->policy_version !== RootPolicy::VERSION || $intent->identity_sha256 !== $identity->fingerprint()
            || hash('sha256', $intent->identity) !== $identity->fingerprint()) {
            throw new LogicException('The locked original root intent changed.');
        }
        return $root;
    }

    private function assertRequest(object $request, RootIdentity $identity, string $requestHash): void
    {
        if ($request->root_key !== $identity->scope->key || $request->identity_sha256 !== $identity->fingerprint()
            || $request->request_sha256 !== $requestHash) {
            throw new LogicException('The original root cancellation request changed content.');
        }
    }

    private function delegate(RootBinding $binding, string $requestKey, object $assignment, array $request): array
    {
        if ($assignment->root_run !== $binding->reservation->originalRunId) {
            throw new LogicException('The initialized business owner differs from its original root admission.');
        }
        $scope = $binding->reservation->identity->scope;
        app(ScopeAuthorization::class)->assertAllowed($scope, $this->authority->principal, 'inspect');
        $metadata = (new ScopeRepository())->metadata($scope);
        $committed = $this->committed($binding, $requestKey, $metadata);
        if ($committed !== null) {
            return $committed;
        }
        $origin = \Workflow\V2\Models\WorkflowRun::query()->findOrFail($binding->reservation->originalRunId);
        if ($metadata['original_run_id'] === $origin->id && $metadata['status'] === 'verified'
            && $origin->status->isTerminal()) {
            $request['finalizer'] = app(CancellationStore::class)->finish($scope->key, $origin->id, $requestKey);
        }
        return $request;
    }

    private function committed(RootBinding $binding, string $requestKey, array $metadata): ?array
    {
        $identity = $binding->reservation->identity;
        $root = $this->authority->subject($identity->scope->key, 'order');
        if ($root->state !== 'cancelled') {
            return null;
        }
        if (! (bool) $root->cancellation_requested || $metadata['original_run_id'] !== $binding->reservation->originalRunId
            || $metadata['status'] !== 'verified') {
            throw new LogicException('The original cancellation has no verified compensation proof.');
        }
        app(CancellationStore::class)->intent($identity->scope->key, $binding->reservation->originalRunId, $requestKey);
        $publications = $this->authority->db->table('example_machine_publications as p')
            ->join('example_machine_decisions as d', 'd.id', '=', 'p.id')
            ->where('p.subject_id', $root->id)
            ->where('d.subject_id', $root->id)
            ->where('d.status', 'committed')
            ->where('d.reason', 'verified_business_cancellation_committed')
            ->limit(2)
            ->get(['p.id', 'p.payload']);
        if ($publications->count() !== 1) {
            throw new LogicException('The original cancellation needs exactly one committed business publication.');
        }
        $row = $publications->sole();
        $publication = json_decode($row->payload, true, 32, JSON_THROW_ON_ERROR);
        $execution = Authority::reference($publication['execution'] ?? []);
        if (($publication['transitionKey'] ?? null) !== 'cancel' || ($publication['state'] ?? null) !== 'cancelled'
            || ($publication['revision'] ?? null) !== (string) $root->revision
            || ($publication['originalRunId'] ?? null) !== $binding->reservation->originalRunId
            || \Workflow\V2\Compensation\Definition\ScopeIdentity::fromArray(
                $publication['scope'] ?? []
            )->toArray() !== $identity->scope->toArray()
            || $execution->scope !== $root->scope || $execution->subject !== $root->subject
            || $execution->sourceRevision !== (string) ((int) $root->revision - 1)
            || $row->id !== \DurableWorkflow\Examples\BusinessStateMachines\SqlBusinessStore::decisionId(
                $execution,
                'transition',
                $execution->executionId
            )) {
            throw new LogicException('The committed cancellation publication changed its original business proof.');
        }
        $suffix = '.cancel.' . $execution->sourceRevision;
        if (! str_ends_with($execution->executionId, $suffix)) {
            throw new LogicException('The committed cancellation has no original transition identity.');
        }
        $actual = \Workflow\V2\Models\WorkflowRun::query()->findOrFail(
            substr($execution->executionId, 0, -strlen($suffix))
        );
        $this->authority->actor($actual, $execution);
        return [
            'status' => 'cancelled',
            'requestId' => hash('sha256', PortableDescriptor::encode([self::VERSION, $identity->scope->key])),
            'businessSubject' => $identity->scope->key,
            'businessGeneration' => $identity->scope->generation,
            'state' => 'cancelled',
            'revision' => (string) $root->revision,
            'proof' => [
                'kind' => 'verified_original_scope',
                'binding' => $binding->toArray(),
                'scope' => $identity->scope->toArray(),
                'publicationId' => $row->id,
            ],
        ];
    }
}
