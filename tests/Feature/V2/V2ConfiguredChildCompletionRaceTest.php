<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use DurableWorkflow\Examples\ConfiguredStateMachines\CommitActivity;
use DurableWorkflow\Examples\ConfiguredStateMachines\Ownership;
use DurableWorkflow\Examples\ConfiguredStateMachines\Shipment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ConfiguredMachineFixture;
use Tests\Support\WorkerLossControl;
use Tests\TestCase;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\Compensation\Recovery\RecoveryWorkflow;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\StateMachine\Binding\ChildReservation;
use Workflow\V2\WorkflowStub;

/**
 * TECH-03: a business cancellation races the completion of the bound Shipment that the Order awaits.
 * Every cut leaves one authoritative business decision: the child is compensated exactly once, through its
 * original retained scope, before the earlier parent effects, and only verified recovery commits `cancelled`.
 */
final class V2ConfiguredChildCompletionRaceTest extends TestCase
{
    use ConfiguredMachineFixture;
    use WorkerLossControl;

    private const REQUEST = 'cancel-racing-shipment';

    /**
     * Completion first: the child's business completion commits before cancellation is accepted.
     */
    public static function completionFirst(): array
    {
        return [
            'child completion committed, child run still open' => ['child-committed', false],
            'parent verified the child outcome' => ['outcome-verified', false],
            'parent completion commit already scheduled' => ['parent-commit-scheduled', true],
        ];
    }

    #[DataProvider('completionFirst')]
    public function testCompletionCommittedFirstIsCompensatedThroughTheRetainedChildScopeBeforeTheParent(
        string $cut,
        bool $parentCommitRefused
    ): void {
        [$stub, $child] = $this->awaitShipment();
        $this->drain(match ($cut) {
            'child-committed' => static fn (): bool => DB::table('example_machine_subjects')
                ->whereNotNull('parent_subject_id')
                ->value('state') === 'completed',
            'outcome-verified' => static fn (): bool => (bool) DB::table('example_machine_bindings')->value(
                'business_outcome_verified'
            ),
            'parent-commit-scheduled' => fn (): bool => $this->commits($stub->runId()) === 2,
        });
        $this->assertSame('completed', $this->shipment()->state, $this->progress());
        $this->assertSame(2, (int) $this->shipment()->revision);
        $this->assertSame(['committed'], $this->decisions($this->shipment()->id));
        $this->assertSame($cut !== 'child-committed', (bool) DB::table('example_machine_bindings')->value(
            'business_outcome_verified'
        ));
        $this->assertSame($cut === 'child-committed', ! $child->refresh()->status->isTerminal());

        $this->cancelAndSettle($stub, $child);

        // The child's verified business completion stays historical; its retained scope compensated it.
        $this->assertSame('completed', $this->shipment()->state);
        $this->assertSame(['committed'], $this->decisions($this->shipment()->id));
        $this->assertSame(
            $parentCommitRefused ? ['cancellation_requested', 'committed', 'committed'] : ['committed', 'committed'],
            $this->decisions($this->root()->id),
            'The Order completion commit never wins after the accepted cancellation.'
        );
        $this->assertSame(3, DB::table('example_machine_publications')->count());
    }

    /**
     * Cancellation first: the child's deliver effect is active and its completion commit is scheduled.
     */
    public function testCancellationAcceptedFirstRefusesTheChildCompletionCommitAndCompensatesTheChild(): void
    {
        [$stub, $child] = $this->awaitShipment();
        $this->drain(static fn (): bool => ActivityExecution::query()->where('workflow_run_id', $child->id)
            ->where('activity_class', CommitActivity::class)
            ->exists());
        $this->assertSame('received', $this->shipment()->state, $this->progress());
        $this->assertSame(1, DB::table('example_machine_effects')->where('subject_id', $this->shipment()->id)
            ->where('action', 'deliver')
            ->where('status', 'active')
            ->count());

        $this->cancelAndSettle($stub, $child);

        // The scheduled completion commit ran after the fence and was refused, never committed.
        $this->assertSame(['cancellation_requested'], $this->decisions($this->shipment()->id));
        $this->assertSame('received', $this->shipment()->state);
        $this->assertSame(1, (int) $this->shipment()->revision);
        $this->assertSame('cancellation_requested', $child->refresh()->workflowOutput()['status']);
        $this->assertFalse((bool) DB::table('example_machine_bindings')->value('business_outcome_verified'));
        $this->assertSame(['committed', 'committed'], $this->decisions($this->root()->id));
        $this->assertSame(2, DB::table('example_machine_publications')->count());
    }

    /**
     * A real worker dies by SIGKILL after the Shipment's deliver effect commits and before DW records it.
     * Cancellation is accepted while no worker is alive. A fresh process reconciles that same operation; the
     * child never commits completion, and its one effect is compensated once, before the parent effects.
     */
    public function testCancellationWhileTheDeliverWorkerIsLostReconcilesAndCompensatesTheSameEffectOnce(): void
    {
        $this->requireWorkerLossSupport();
        [$stub, $child] = $this->awaitShipment();
        $lost = null;
        [$cut, $resumed] = $this->loseWorkerThenRecover(
            'tests/Support/ConfiguredChildRaceProcessLossProcessTest.php',
            'DWF_CONFIGURED_CHILD_RACE_INPUT',
            [
                'subject' => self::SUBJECT,
                'principal' => self::PRINCIPAL,
                'rootRun' => $stub->runId(),
                'clock' => now()
                    ->toISOString(),
            ],
            function (array $cut, array $data) use ($stub, &$lost): array {
                $lost = $this->obligation($cut);
                // The SQL effect committed; DW has not recorded its outcome.
                $this->assertSame('in_flight', $lost['forward_status']);
                $this->assertNull($lost['forward_result']);
                $this->assertSame(
                    ['active'],
                    DB::table('example_machine_effects')->where('id', $cut['operationId'])->pluck('status')->all()
                );
                $request = $this->store->requestCancellation(self::SUBJECT, $stub->runId(), self::REQUEST);
                $this->assertSame('origin', $request['route']['route']);
                $this->assertSame(
                    'pending',
                    $this->store->finishCancellation(self::SUBJECT, $stub->runId(), self::REQUEST)['status']
                );
                return [
                    ...$data,
                    'clock' => $cut['clock'],
                ];
            },
        );
        Carbon::setTestNow($resumed['clock']);
        $this->assertSame(['verified', false], [$resumed['scope'], $resumed['idle']], $this->progress());
        $recovered = $this->obligation($cut);
        // The same original operation was reconciled and compensated; no replacement identity appeared.
        $this->assertSame([$lost['id'], $lost['fingerprint']], [$recovered['id'], $recovered['fingerprint']]);
        $this->assertSame('verified', $recovered['compensation_status']);
        $this->assertSame(
            ['deliver'],
            DB::table('example_machine_compensation_log')->where('operation_id', $cut['operationId'])
                ->pluck('action')
                ->all()
        );
        $this->assertSettledOnce($stub, $child);
        $this->assertNotContains('committed', $this->decisions($this->shipment()->id));
        $this->assertSame('received', $this->shipment()->state);
        $this->assertSame(['committed', 'committed'], $this->decisions($this->root()->id));
    }

    /**
     * The Order has committed `working` and is awaiting its bound Shipment, which has not run yet.
     *
     * @return array{WorkflowStub, WorkflowRun}
     */
    private function awaitShipment(): array
    {
        $this->store->seed(self::SUBJECT, 'prepare');
        $stub = $this->admit();
        $this->drain(static fn (): bool => WorkflowRun::query()->where('workflow_class', Shipment::class)->exists());
        $child = WorkflowRun::query()->where('workflow_class', Shipment::class)->sole();
        $this->drain(pausedInstance: $child->workflow_instance_id);
        $this->assertSame('working', $this->root()->state, $this->progress());
        $stub->signal('finish');
        $this->drain(pausedInstance: $child->workflow_instance_id);
        $this->assertSame(RunStatus::Waiting, WorkflowRun::query()->findOrFail($stub->runId())->status);
        $this->assertSame(RunStatus::Pending, $child->refresh()->status);
        $this->assertSame(0, DB::table('example_machine_effects')->where('action', 'deliver')->count());
        return [$stub, $child];
    }

    private function cancelAndSettle(WorkflowStub $stub, WorkflowRun $child): void
    {
        $request = $this->store->requestCancellation(self::SUBJECT, $stub->runId(), self::REQUEST);
        $this->assertSame('origin', $request['route']['route']);
        $this->assertSame(
            'pending',
            $this->store->finishCancellation(self::SUBJECT, $stub->runId(), self::REQUEST)['status'],
            'An accepted request is not a business cancellation.'
        );
        $this->drain();
        $this->assertSettledOnce($stub, $child);
    }

    /**
     * One verified recovery of each original operation, child first, then one proof-only business cancellation.
     */
    private function assertSettledOnce(WorkflowStub $stub, WorkflowRun $child): void
    {
        $rootScope = Ownership::scope(self::SUBJECT, $stub->runId());
        $childScope = $this->childScope();
        $this->assertSame('verified', (new ScopeRepository())->metadata($rootScope)['status'], $this->progress());
        $childLedger = (new ScopeRepository())->inspect($childScope);
        $this->assertSame('verified', $childLedger['status']);
        $this->assertSame($child->id, $childLedger['original_run_id']);
        $this->assertSame(
            ['owner', $child->id . '.deliver.1.deliver'],
            array_column(array_column($childLedger['obligations'], 'descriptor'), 'key'),
            'Observation added no second child compensation participant.'
        );
        // Child first, then the earlier parent effects; every original operation is undone exactly once.
        $compensated = DB::table('example_machine_compensation_log')->orderBy('sequence')->get();
        $this->assertSame(['deliver', 'prepare', 'reserve'], $compensated->pluck('action')->all());
        $effects = DB::table('example_machine_effects')->get();
        $this->assertSame(['voided', 'voided', 'voided'], $effects->pluck('status')->all());
        $this->assertEqualsCanonicalizing(
            $effects->pluck('id')
                ->all(),
            $compensated->pluck('operation_id')
                ->all()
        );
        $this->assertSame(1, WorkflowRun::query()->where('workflow_class', Shipment::class)->count());
        $this->assertLessThanOrEqual(
            1,
            WorkflowRun::query()->where('workflow_class', RecoveryWorkflow::class)->count()
        );
        $this->assertSame(1, DB::table('example_machine_bindings')->count());
        $this->assertSame('working', $this->root()->state, 'Verified recovery alone does not commit cancelled.');

        $finalizer = $this->store->finishCancellation(self::SUBJECT, $stub->runId(), self::REQUEST);
        $this->drain();
        $this->assertSame('cancelled', $this->root()->state, $this->progress());
        $this->assertSame(
            $finalizer['runId'],
            $this->store->finishCancellation(self::SUBJECT, $stub->runId(), self::REQUEST)['runId']
        );
        $this->assertSame(['deliver', 'prepare', 'reserve'], DB::table('example_machine_compensation_log')
            ->orderBy('sequence')
            ->pluck('action')
            ->all());
    }

    private function obligation(array $cut): array
    {
        $ledger = (new ScopeRepository())->inspect(ScopeIdentity::fromArray($cut['scope']));
        $rows = array_values(array_filter(
            $ledger['obligations'],
            static fn (array $row): bool => $row['id'] === $cut['operationId']
        ));
        $this->assertCount(1, $rows);
        return $rows[0];
    }

    private function shipment(): object
    {
        return DB::table('example_machine_subjects')->whereNotNull('parent_subject_id')->sole();
    }

    private function childScope(): ScopeIdentity
    {
        $reservation = ChildReservation::fromArray(json_decode(
            DB::table('example_machine_bindings')->sole()->reservation,
            true,
            flags: JSON_THROW_ON_ERROR
        ));
        return Ownership::childScope($reservation->identity->parentExecution);
    }

    private function commits(string $run): int
    {
        return ActivityExecution::query()->where('workflow_run_id', $run)
            ->where('activity_class', CommitActivity::class)
            ->count();
    }

    /**
     * @return list<string> Sorted decision statuses; decision identities are content hashes, not insertion order.
     */
    private function decisions(string $subjectId): array
    {
        return DB::table('example_machine_decisions')->where('subject_id', $subjectId)
            ->pluck('status')
            ->sort()
            ->values()
            ->all();
    }
}
