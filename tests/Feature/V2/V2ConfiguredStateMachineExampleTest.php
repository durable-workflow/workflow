<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use DurableWorkflow\Examples\ConfiguredStateMachines\AssessmentStore;
use DurableWorkflow\Examples\ConfiguredStateMachines\Catalogue;
use DurableWorkflow\Examples\ConfiguredStateMachines\Order;
use DurableWorkflow\Examples\ConfiguredStateMachines\Ownership;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ConfiguredMachineFixture;
use Tests\TestCase;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\Compensation\Security\ScopeAuthorization;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\WorkflowReplayer;

require_once __DIR__ . '/../../../examples/configured-state-machines/bootstrap.php';

final class V2ConfiguredStateMachineExampleTest extends TestCase
{
    use ConfiguredMachineFixture;

    public static function integrations(): array
    {
        return [
            'carrier preparation' => ['prepare', ['reserve', 'prepare'], 'working'],
            'imported fulfilment' => ['imported', ['import'], 'received'],
        ];
    }

    #[DataProvider('integrations')]
    public function testBothProfilesUseFreshRecordedRulesAndAwaitTheirOriginalStateBoundShipment(
        string $integration,
        array $actions,
        string $hosting,
    ): void {
        $stub = $this->start($integration);
        $origin = WorkflowRun::query()->findOrFail($stub->runId());
        $child = WorkflowRun::query()->where('id', '!=', $origin->id)->sole();
        $this->assertSame(Catalogue::STATES, Catalogue::order($integration)->states);
        $this->assertSame($hosting, $this->root()->state);
        $this->assertSame(2, (int) $this->root()->revision);
        $this->assertSame(RunStatus::Completed, $child->status, $this->progress());
        $this->assertSame([...$actions, 'deliver'], DB::table('example_machine_effects')->orderBy('action')
            ->get()
            ->sortBy(static fn (object $row): int => array_search($row->action, [...$actions, 'deliver'], true))
            ->pluck('action')
            ->values()
            ->all());
        $this->assertFalse((bool) DB::table('example_machine_bindings')->value('business_outcome_verified'));
        $snapshots = DB::table('configured_machine_assessments')->get()->map(static fn (object $row): array =>
            json_decode($row->snapshot, true, flags: JSON_THROW_ON_ERROR))->filter(static fn (array $snapshot): bool =>
                $snapshot['transitionKey'] === 'begin')->keyBy('phase');
        $this->assertSame('unsatisfied', $snapshots['before']['target']['verdict']);
        $this->assertSame('satisfied', $snapshots['after']['target']['verdict']);
        $this->assertNotSame($snapshots['before']['snapshotId'], $snapshots['after']['snapshotId']);
        $this->assertNotEmpty($snapshots['after']['target']['evidence']);
        $stub->signal('finish');
        $this->drain();
        $this->assertSame(RunStatus::Completed, $origin->refresh()->status, $this->progress());
        $this->assertSame('completed', $this->root()->state);
        $this->assertTrue((bool) DB::table('example_machine_bindings')->value('business_outcome_verified'));
        $this->assertSame(2, WorkflowRun::query()->count());
        $this->assertSame(1, DB::table('example_machine_bindings')->count());
        $queries = [];
        $listening = true;
        DB::listen(static function (QueryExecuted $query) use (&$queries, &$listening): void {
            if ($listening && (str_contains($query->sql, 'example_machine_') || str_contains(
                $query->sql,
                'configured_machine_'
            ))) {
                $queries[] = $query->sql;
            }
        });
        try {
            app(WorkflowReplayer::class)->replay($origin->fresh());
            app(WorkflowReplayer::class)->replay($child->fresh());
        } finally {
            $listening = false;
        }
        $this->assertSame(
            [],
            $queries,
            'Replay must not collect live facts, evaluate configured rules or write business state.'
        );
    }

    #[DataProvider('integrations')]
    public function testClosedRunsRecoverChildFirstAndOnlyVerifiedProofCanCommitBusinessCancelled(
        string $integration,
        array $actions,
        string $hosting,
    ): void {
        $stub = $this->start($integration);
        $stub->signal('finish');
        $this->drain();
        $origin = WorkflowRun::query()->findOrFail($stub->runId());
        $child = WorkflowRun::query()->where('id', '!=', $origin->id)->sole();
        $parentHistory = $this->history($origin->id);
        $childHistory = $this->history($child->id);
        $request = $this->store->requestCancellation(self::SUBJECT, $origin->id, 'late-order-cancel');
        $this->assertSame('recovery', $request['route']['route']);
        $this->assertSame(
            'pending',
            $this->store->finishCancellation(self::SUBJECT, $origin->id, 'late-order-cancel')['status']
        );
        $this->assertSame('completed', $this->root()->state);
        $this->drain();
        $this->assertSame(
            'verified',
            (new ScopeRepository())->metadata(Ownership::scope(self::SUBJECT, $origin->id))['status']
        );
        $this->assertSame(['deliver', ...array_reverse($actions)], DB::table('example_machine_compensation_log')
            ->orderBy('sequence')
            ->pluck('action')
            ->all());
        $this->assertSame(0, DB::table('example_machine_effects')->where('status', 'active')->count());
        $this->assertSame(
            'completed',
            $this->root()
->state,
            'A verified ledger is still separate from the business-state commit.'
        );
        $finalizer = $this->store->finishCancellation(self::SUBJECT, $origin->id, 'late-order-cancel');
        $this->drain();
        $this->assertSame('cancelled', $this->root()->state, $this->progress());
        $this->assertSame(RunStatus::Completed, WorkflowRun::query()->findOrFail($finalizer['runId'])->status);
        $this->assertSame(
            $finalizer['runId'],
            $this->store->finishCancellation(self::SUBJECT, $origin->id, 'late-order-cancel')['runId']
        );
        $this->assertSame($parentHistory, $this->history($origin->id));
        $this->assertSame($childHistory, $this->history($child->id));
        $this->assertSame(count($actions) + 1, DB::table('example_machine_effects')->count());
        $this->assertSame(4, DB::table('example_machine_publications')->count());
    }

    public function testActiveOrderCancellationUsesNativeSagaWithoutRepeatingTheObserversUndo(): void
    {
        $stub = $this->start('prepare');
        $request = $this->store->requestCancellation(self::SUBJECT, $stub->runId(), 'waiting-order-cancel');
        $this->assertSame('origin', $request['route']['route']);
        $this->drain();
        // An unresolved child can finish after the original parent has closed.
        // Resume the same host intent so its retained parent recovery can proceed.
        $this->store->requestCancellation(self::SUBJECT, $stub->runId(), 'waiting-order-cancel');
        $this->drain();
        $this->assertSame(
            'verified',
            (new ScopeRepository())->metadata(Ownership::scope(self::SUBJECT, $stub->runId()))['status'],
            $this->progress()
        );
        $this->assertSame(['deliver', 'prepare', 'reserve'], DB::table('example_machine_compensation_log')
            ->orderBy('sequence')
            ->pluck('action')
            ->all());
        $this->store->finishCancellation(self::SUBJECT, $stub->runId(), 'waiting-order-cancel');
        $this->drain();
        $this->assertSame('cancelled', $this->root()->state, $this->progress());
        $this->assertSame(1, DB::table('example_machine_bindings')->count());
    }

    public function testChildRecoveryAfterOriginFailureResumesTheSameParentWithoutRepeatingAnyUndo(): void
    {
        $stub = $this->start('prepare');
        $reservation = \Workflow\V2\StateMachine\Binding\ChildReservation::fromArray(json_decode(
            DB::table('example_machine_bindings')->sole()->reservation,
            true,
            flags: JSON_THROW_ON_ERROR
        ));
        $childScope = Ownership::childScope($reservation->identity->parentExecution);
        $parentScope = Ownership::scope(self::SUBJECT, $stub->runId());
        $request = $this->store->requestCancellation(self::SUBJECT, $stub->runId(), 'delayed-original-child');
        $this->assertSame('origin', $request['route']['route']);
        $this->drain(
            static fn (): bool => WorkflowRun::query()->findOrFail($stub->runId())->status->isTerminal(),
            $childScope->recoveryInstanceId()
        );
        $this->assertSame(
            RunStatus::Failed,
            WorkflowRun::query()->findOrFail($stub->runId())->status,
            $this->progress()
        );
        $history = $this->history($stub->runId());
        $this->assertSame('requested', (new ScopeRepository())->metadata($parentScope)['status']);
        $this->assertSame([], DB::table('example_machine_compensation_log')->pluck('action')->all());
        $this->drain();
        $this->assertSame('verified', (new ScopeRepository())->metadata($childScope)['status']);
        $this->assertSame('requested', (new ScopeRepository())->metadata($parentScope)['status']);
        $resume = $this->store->requestCancellation(self::SUBJECT, $stub->runId(), 'delayed-original-child');
        $this->assertSame('recovery', $resume['route']['route']);
        $this->assertNotSame($stub->runId(), $resume['route']['runId']);
        $this->drain();
        $this->assertSame('verified', (new ScopeRepository())->metadata($parentScope)['status'], $this->progress());
        $this->assertSame(
            ['deliver', 'prepare', 'reserve'],
            DB::table('example_machine_compensation_log')->orderBy('sequence')->pluck('action')->all()
        );
        $this->assertSame($history, $this->history($stub->runId()));
        $this->assertSame(1, DB::table('example_machine_bindings')->count());
        $this->assertSame($request['requestId'], $resume['requestId']);
    }

    public function testAlreadySatisfiedImportedTargetSkipsActionsAndChildAdmission(): void
    {
        $this->store->seed(self::SUBJECT, 'imported', prevalidated: true);
        $stub = $this->admit('reconcile');
        $this->drain();
        $this->assertSame(
            RunStatus::Completed,
            WorkflowRun::query()->findOrFail($stub->runId())->status,
            $this->progress()
        );
        $this->assertSame('completed', $this->root()->state);
        $this->assertSame(0, DB::table('example_machine_effects')->count());
        $this->assertSame(0, DB::table('example_machine_bindings')->count());
        $this->assertSame(0, DB::table('configured_machine_admissions')->count());
        $this->assertSame(1, DB::table('configured_machine_assessments')->count());
        $this->assertSame(1, WorkflowRun::query()->count());
    }

    public function testCommonInvariantCannotBeRemovedByTheImportedProfile(): void
    {
        $this->store->seed(self::SUBJECT, 'imported', enabled: false);
        $stub = $this->admit();
        $this->drain();
        $run = WorkflowRun::query()->findOrFail($stub->runId());
        $this->assertSame(RunStatus::Completed, $run->status, $this->progress());
        $this->assertSame('blocked', $run->workflowOutput()['status']);
        $this->assertSame('received', $this->root()->state);
        $this->assertSame(0, DB::table('example_machine_effects')->count());
        $this->assertSame(0, DB::table('example_machine_bindings')->count());
        $this->assertSame(0, DB::table('example_machine_publications')->count());
    }

    public function testTechnicalChildCompletionDoesNotReplaceTheRequiredBusinessEvidence(): void
    {
        $stub = $this->start('prepare');
        DB::table('example_machine_subjects')->whereNotNull('parent_subject_id')->update([
            'state' => 'working',
        ]);
        $stub->signal('finish');
        $this->drain();
        $this->assertNotSame('completed', $this->root()->state);
        $this->assertFalse((bool) DB::table('example_machine_bindings')->value('business_outcome_verified'));
        $this->assertGreaterThan(0, DB::table('example_machine_effects')->where('status', 'active')->count());
    }

    public function testClosedRootBeforeRecordedRegistrationRemainsUnresolved(): void
    {
        $this->store->seed(self::SUBJECT, 'prepare');
        $stub = $this->admit();
        $this->drain(fn (): bool => $this->root()->original_native_run_id !== null);
        $this->assertTrue($stub->terminate('before-owner-registration')->accepted());
        $this->drain();
        $result = $this->store->requestCancellation(self::SUBJECT, $stub->runId(), 'closed-startup');
        $this->assertSame('unresolved', $result['status']);
        $this->assertSame('original_scope_not_initialized', $result['reason']);
        $this->assertTrue((bool) $this->root()->cancellation_requested);
        $this->assertSame('received', $this->root()->state);
        $this->assertSame(0, DB::table('workflow_compensation_scopes')->count());
        $this->assertSame(0, DB::table('example_machine_effects')->count());
    }

    public function testForeignPrincipalIsRefusedBeforeReadingProtectedBusinessOrNativeData(): void
    {
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        try {
            $this->store->requestCancellation('case-bbbbbbbbbbbbbbbb-g1', 'foreign-run', 'foreign-cancel');
            $this->fail('A foreign business subject cannot query the original execution.');
        } catch (LogicException $error) {
            $this->assertSame('The worker cannot access this business subject.', $error->getMessage());
        }
        $this->assertSame([], $queries);
    }

    public function testLostCommitAcknowledgementReturnsOriginalDecisionAfterCancellationWithoutAnotherPublication(): void
    {
        $stub = $this->start('prepare');
        $actual = WorkflowRun::query()->findOrFail($stub->runId());
        $snapshot = DB::table('configured_machine_assessments')->get()->map(static fn (object $row): array =>
            json_decode($row->snapshot, true, flags: JSON_THROW_ON_ERROR))->sole(static fn (array $value): bool =>
                $value['transitionKey'] === 'begin' && $value['phase'] === 'after');
        $request = [
            'execution' => $snapshot['execution'],
            'transition' => get_object_vars(Catalogue::transition(Catalogue::order('prepare'), 'begin')),
            'snapshotId' => $snapshot['snapshotId'],
            'phase' => $snapshot['phase'],
            'expectedState' => $snapshot['state'],
            'expectedRevision' => $snapshot['revision'],
        ];
        $publications = DB::table('example_machine_publications')->count();
        $revision = $this->root()
->revision;
        $first = app(AssessmentStore::class)->commit($actual, $request);
        $this->store->requestCancellation(self::SUBJECT, $actual->id, 'cancel-after-commit');
        $this->assertSame($first, app(AssessmentStore::class)->commit($actual, $request));
        $this->assertSame('committed', $first['status']);
        $this->assertSame($revision, $this->root()->revision);
        $this->assertSame($publications, DB::table('example_machine_publications')->count());
    }

    public function testCancellationAfterReservationBeforeChildAdmissionUsesPermanentNeverAdmittedFence(): void
    {
        $this->store->seed(self::SUBJECT, 'prepare');
        $stub = $this->admit();
        $this->drain(
            static fn (): bool => WorkflowInstance::query()->whereNotNull('native_child_reservation')->exists()
        );
        $this->assertSame(1, WorkflowRun::query()->count());
        $this->store->requestCancellation(self::SUBJECT, $stub->runId(), 'cancel-before-admission');
        $this->drain();
        $this->assertSame(
            'verified',
            (new ScopeRepository())->metadata(Ownership::scope(self::SUBJECT, $stub->runId()))['status'],
            $this->progress()
        );
        $this->assertSame(1, WorkflowRun::query()->count());
        $this->assertSame(0, DB::table('example_machine_bindings')->count());
        $this->assertSame(0, DB::table('example_machine_effects')->where('status', 'active')->count());
        $reservation = WorkflowInstance::query()->whereNotNull('native_child_reservation')->sole();
        $this->assertSame(
            'closed',
            json_decode($reservation->native_child_reservation, true, flags: JSON_THROW_ON_ERROR)['status']
        );
    }

    public function testEarlyCancellationWaitsForTheOriginalOwnersGenuineRecordedRegistration(): void
    {
        $this->store->seed(self::SUBJECT, 'prepare');
        $stub = $this->admit();
        $this->drain(fn (): bool => $this->root()->original_native_run_id !== null);
        $this->assertSame(
            'unresolved',
            $this->store->requestCancellation(self::SUBJECT, $stub->runId(), 'early-cancel')['status']
        );
        $this->assertSame(0, DB::table('workflow_compensation_scopes')->count());
        $this->drain();
        $metadata = (new ScopeRepository())->inspect(Ownership::scope(self::SUBJECT, $stub->runId()));
        $this->assertSame('verified', $metadata['status'], $this->progress());
        $this->assertSame($stub->runId(), $metadata['original_run_id']);
        $this->assertSame(['owner'], array_column(array_column($metadata['obligations'], 'descriptor'), 'key'));
        $this->assertSame(0, DB::table('example_machine_effects')->count());
        $this->store->finishCancellation(self::SUBJECT, $stub->runId(), 'early-cancel');
        $this->drain();
        $this->assertSame('cancelled', $this->root()->state);
    }

    public function testAnotherRootScopeGenerationCannotReuseThisBusinessOwnersAuthority(): void
    {
        $stub = $this->start('prepare');
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The retained scope belongs to another original business generation.');
        app(ScopeAuthorization::class)->assertAllowed(
            Ownership::scope(self::SUBJECT, $stub->runId() . '.begin.1'),
            self::PRINCIPAL,
            'request'
        );
    }

    #[DataProvider('integrations')]
    public function testSharedVersionTwoDefinitionsDriveBothProfilesAndVerifiedCancellation(
        string $integration,
        array $actions,
        string $hosting
    ): void {
        $stub = $this->start($integration, '2');
        $this->assertSame('2', $this->root()->profile_version);
        $this->assertSame($hosting, $this->root()->state);
        $stub->signal('finish');
        $this->drain();
        $origin = WorkflowRun::query()->findOrFail($stub->runId());
        $this->assertSame(RunStatus::Completed, $origin->status, $this->progress());
        $this->assertSame('completed', $this->root()->state);
        $history = $this->history($origin->id);
        $this->store->requestCancellation(self::SUBJECT, $origin->id, 'defined-late-cancel');
        $this->drain();
        $finalizer = $this->store->finishCancellation(self::SUBJECT, $origin->id, 'defined-late-cancel');
        $this->drain();
        $this->assertSame('cancelled', $this->root()->state, $this->progress());
        $this->assertSame(RunStatus::Completed, WorkflowRun::query()->findOrFail($finalizer['runId'])->status);
        $this->assertSame(['deliver', ...array_reverse($actions)], DB::table('example_machine_compensation_log')
            ->orderBy('sequence')
            ->pluck('action')
            ->all());
        $this->assertSame($history, $this->history($origin->id));
        foreach (DB::table('configured_machine_assessments')->get() as $record) {
            $snapshot = json_decode($record->snapshot, true, flags: JSON_THROW_ON_ERROR);
            $facts = json_decode($record->facts, true, flags: JSON_THROW_ON_ERROR);
            $explanation = json_decode($record->explanation, true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('satisfied', $snapshot['machineAssessment']['decision']['verdict']);
            $this->assertSame($snapshot['snapshotId'], $snapshot['machineAssessment']['snapshotId']);
            $this->assertTrue($facts['values']['model_consistent']);
            $this->assertSame(
                $explanation['machine']['configurationFingerprint'],
                $snapshot['machineAssessment']['invariantFingerprint']
            );
            if ($snapshot['transitionKey'] === 'cancel') {
                $this->assertFalse($facts['values']['forward_open']);
                $this->assertSame(
                    Catalogue::order($integration, '2')->definition->fingerprint(),
                    $snapshot['machineAssessment']['definitionFingerprint']
                );
            }
        }
        $queries = [];
        $listening = true;
        DB::listen(static function (QueryExecuted $query) use (&$queries, &$listening): void {
            if ($listening && (str_contains($query->sql, 'example_machine_') || str_contains(
                $query->sql,
                'configured_machine_'
            ))) {
                $queries[] = $query->sql;
            }
        });
        try {
            foreach (WorkflowRun::query()->whereIn('workflow_class', [Order::class,
                \DurableWorkflow\Examples\ConfiguredStateMachines\Shipment::class,
                \DurableWorkflow\Examples\ConfiguredStateMachines\Cancellation::class])->get() as $run) {
                app(WorkflowReplayer::class)->replay($run);
            }
        } finally {
            $listening = false;
        }
        $this->assertSame([], $queries, 'Original, child and cancellation replay must consume recorded assessments.');
    }

    public function testAnAlreadySatisfiedTargetCannotCommitARevisionOutsideTheSharedModel(): void
    {
        $this->store->seed(self::SUBJECT, 'imported', prevalidated: true, version: '2');
        DB::table('example_machine_subjects')->whereNull('parent_subject_id')->update([
            'revision' => 0,
        ]);
        $stub = $this->admit('reconcile');
        $this->drain();
        $run = WorkflowRun::query()->findOrFail($stub->runId());
        $this->assertSame(RunStatus::Completed, $run->status, $this->progress());
        $this->assertSame('blocked', $run->workflowOutput()['status']);
        $this->assertSame('received', $this->root()->state);
        $this->assertSame(0, DB::table('example_machine_effects')->count());
        $this->assertSame(0, DB::table('example_machine_decisions')->count());
        $this->assertSame(0, DB::table('example_machine_publications')->count());
        $snapshot = json_decode(
            DB::table('configured_machine_assessments')->sole()->snapshot,
            true,
            flags: JSON_THROW_ON_ERROR
        );
        $this->assertSame('satisfied', $snapshot['target']['verdict']);
        $this->assertSame('unsatisfied', $snapshot['machineAssessment']['decision']['verdict']);
    }

    public function testTheProtectedBoundaryRejectsASubstitutedMachineAssessment(): void
    {
        $stub = $this->start('prepare', '2');
        $origin = WorkflowRun::query()->findOrFail($stub->runId());
        $snapshot = DB::table('configured_machine_assessments')->get()->map(static fn (object $row): array =>
            json_decode($row->snapshot, true, flags: JSON_THROW_ON_ERROR))->first(static fn (array $row): bool =>
                $row['transitionKey'] === 'begin' && $row['phase'] === 'after');
        $proof = $snapshot['machineAssessment'];
        $proof['decision']['reason'] = 'foreign-proof';
        $before = DB::table('example_machine_decisions')->count();
        try {
            app(AssessmentStore::class)->commit($origin, [
                'execution' => $snapshot['execution'],
                'transition' => get_object_vars(Catalogue::transition(Catalogue::order('prepare', '2'), 'begin')),
                'snapshotId' => $snapshot['snapshotId'],
                'phase' => $snapshot['phase'],
                'expectedState' => $snapshot['state'],
                'expectedRevision' => $snapshot['revision'],
                'machineAssessment' => $proof,
            ]);
            $this->fail('A changed invariant proof cannot reuse a recorded transition decision.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('substituted its shared machine assessment', $error->getMessage());
        }
        $this->assertSame($before, DB::table('example_machine_decisions')->count());
    }

    public function testAnUnapprovedProfileRewriteRefusesAndOriginalVersionOneCompensationStillRecovers(): void
    {
        $stub = $this->start('prepare');
        $original = $this->root();
        DB::table('example_machine_subjects')->where('id', $original->id)->update([
            'profile_version' => '2',
            'authority_version' => \DurableWorkflow\Examples\ConfiguredStateMachines\MachineAuthority::grant(
                Catalogue::order('prepare', '2')
            ),
        ]);
        $stub->signal('finish');
        $this->drain();
        $this->assertSame(
            RunStatus::Failed,
            WorkflowRun::query()->findOrFail($stub->runId())->status,
            $this->progress()
        );
        $this->assertContains(
            'The pinned policy does not match this execution.',
            WorkflowFailure::query()->where('workflow_run_id', $stub->runId())->pluck('message')->all()
        );
        $this->assertSame('working', $this->root()->state);
        $this->assertSame(3, DB::table('example_machine_effects')->count());
        DB::table('example_machine_subjects')->where('id', $original->id)->update([
            'profile_version' => '1',
            'authority_version' => $original->authority_version,
        ]);
        $history = $this->history($stub->runId());
        $this->store->requestCancellation(self::SUBJECT, $stub->runId(), 'old-profile-recovery');
        $this->drain();
        $this->assertSame(
            'verified',
            (new ScopeRepository())->metadata(Ownership::scope(self::SUBJECT, $stub->runId()))['status'],
            $this->progress()
        );
        $this->assertSame(
            ['deliver', 'prepare', 'reserve'],
            DB::table('example_machine_compensation_log')->orderBy('sequence')->pluck('action')->all()
        );
        $this->assertSame(0, DB::table('example_machine_effects')->where('status', 'active')->count());
        $this->assertSame($history, $this->history($stub->runId()));
    }
}
