<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Request;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Status;
use DurableWorkflow\Examples\ProviderStateMachines\AssessmentStore;
use DurableWorkflow\Examples\ProviderStateMachines\Authority;
use DurableWorkflow\Examples\ProviderStateMachines\Booking;
use DurableWorkflow\Examples\ProviderStateMachines\CancellationStore;
use DurableWorkflow\Examples\ProviderStateMachines\Catalogue;
use DurableWorkflow\Examples\ProviderStateMachines\Order;
use DurableWorkflow\Examples\ProviderStateMachines\Payment;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\WorkflowReplayer;
use Workflow\V2\WorkflowStub;

require_once __DIR__ . '/../../../examples/provider-state-machines/bootstrap.php';

/** Actual embedded native machines, with a provider database independent of DW/business transactions. */
final class V2ProviderStateMachineExampleTest extends TestCase
{
    use \Tests\Support\ProviderMachineFixture;

    public static function acknowledgements(): array
    {
        return [
            'ordinary acknowledgements' => [false],
            'lost capture booking and refund acknowledgements' => [true],
        ];
    }

    #[DataProvider('acknowledgements')]
    public function testPreHandoverCancellationConfirmsBookingCancelThenRefundThenOriginalReservationRelease(
        bool $lost
    ): void {
        foreach (['order', 'payment', 'shipment'] as $role) {
            $descriptor = $this->terms->descriptor($role);
            $this->provider->plan(
                Request::fromArray($descriptor['forward']),
                Status::Confirmed,
                $lost && $role !== 'order'
            );
            $this->provider->plan(
                Request::fromArray($descriptor['compensation']),
                Status::Confirmed,
                $lost && $role === 'payment'
            );
        }
        $stub = WorkflowStub::make(Order::class, 'provider-order-' . $this->terms->subjectKey(), Catalogue::NAMESPACE);
        $stub->start($this->terms->subjectKey());
        $this->drain();
        $root = $this->store->authority->subject($this->terms->subjectKey(), 'order');
        $this->assertSame('booked', $root->state, $this->progress());
        $this->assertSame(4, (int) $root->revision);
        $children = WorkflowRun::query()->whereIn('workflow_class', [Payment::class, Booking::class])->get();
        $this->assertCount(2, $children);
        $childHistories = [];
        foreach ($children as $child) {
            $this->assertSame(RunStatus::Completed, $child->status, $this->progress());
            $this->assertContains($child->workflowOutput()['businessOutcome'], ['captured', 'booked']);
            $childHistories[$child->id] = $this->history($child->id);
        }
        $this->assertSame(['reserve', 'capture', 'book'], $this->effects());
        $request = app(CancellationStore::class)->request(
            $this->terms->subjectKey(),
            $stub->runId(),
            'customer-cancel-1'
        );
        $this->assertSame('requested', $request['status']);
        $this->drain();
        $this->assertSame(
            'cancelled',
            $this->store->authority->subject($this->terms->subjectKey(), 'order')
->state,
            $this->progress()
        );
        $this->assertSame(['reserve', 'capture', 'book', 'cancel-booking', 'refund', 'release'], $this->effects());
        $plan = $this->store->authority->plan($this->terms->subjectKey());
        foreach ([$plan->rootScope(), $plan->childScope('payment'), $plan->childScope('shipment')] as $scope) {
            $this->assertSame('verified', (new ScopeRepository())->metadata($scope)['status'], $this->progress());
        }
        foreach ($childHistories as $id => $history) {
            $this->assertSame($history, $this->history($id));
        }
        $requests = (int) $this->providerDb->query('SELECT COUNT(*) FROM provider_requests')
            ->fetchColumn();
        foreach (WorkflowRun::query()->where('namespace', Catalogue::NAMESPACE)->get() as $run) {
            app(WorkflowReplayer::class)->replay($run);
        }
        $this->assertSame(
            $requests,
            (int) $this->providerDb->query('SELECT COUNT(*) FROM provider_requests')
                ->fetchColumn()
        );
        $this->assertSame(6, count($this->effects()));
        $commands = (int) $this->providerDb->query("SELECT COUNT(*) FROM provider_requests WHERE kind = 'command'")
            ->fetchColumn();
        $lost ? $this->assertGreaterThan(6, $commands) : $this->assertSame(6, $commands);
    }

    public function testClosedOriginalOrderAndChildrenKeepTheirHistoriesDuringRecoveryAndProofOnlyFinalization(): void
    {
        $stub = $this->start();
        $stub->signal('finish');
        $this->drain();
        $this->assertSame(RunStatus::Completed, WorkflowRun::query()->findOrFail($stub->runId())->status);
        $original = [];
        foreach (WorkflowRun::query()->whereIn(
            'workflow_class',
            [Order::class, Payment::class, Booking::class]
        )->get() as $run) {
            $original[$run->id] = $this->history($run->id);
        }
        $cancellation = app(CancellationStore::class);
        $request = $cancellation->request($this->terms->subjectKey(), $stub->runId(), 'after-booking-completion');
        $this->assertSame('recovery', $request['route']['route']);
        $this->drain();
        $finalizer = $cancellation->finish($this->terms->subjectKey(), $stub->runId(), 'after-booking-completion');
        $this->assertSame('started', $finalizer['status'], $this->progress());
        $this->drain();
        $this->assertSame(
            'cancelled',
            $this->store->authority->subject($this->terms->subjectKey(), 'order')
->state,
            $this->progress()
        );
        $this->assertSame(['reserve', 'capture', 'book', 'cancel-booking', 'refund', 'release'], $this->effects());
        foreach ($original as $id => $history) {
            $this->assertSame($history, $this->history($id));
        }
    }

    public function testDelayedChildRecoveryCannotAdvanceItsParentAndAFreshRequestUsesTheVerifiedLedger(): void
    {
        $stub = $this->start();
        $key = $this->terms->subjectKey();
        $plan = $this->store->authority->plan($key);
        $cancellation = app(CancellationStore::class);
        $cancellation->request($key, $stub->runId(), 'booking-worker-delayed');
        // Deliberately leave this child's work queued while the original parent's finite attempts run.
        $this->drain(pausedInstance: $plan->childScope('shipment')->recoveryInstanceId());
        $this->assertSame(RunStatus::Failed, WorkflowRun::query()->findOrFail($stub->runId())->status);
        $this->assertSame('requested', (new ScopeRepository())->metadata($plan->rootScope())['status']);
        $this->assertSame(['reserve', 'capture', 'book'], $this->effects());
        $this->assertSame('booked', $this->store->authority->subject($key, 'order')->state);
        $this->assertSame('pending', $cancellation->finish($key, $stub->runId(), 'booking-worker-delayed')['status']);
        $history = $this->history($stub->runId());

        $this->drain();
        $this->assertSame('verified', (new ScopeRepository())->metadata($plan->childScope('shipment'))['status']);
        $this->assertSame('requested', (new ScopeRepository())->metadata($plan->rootScope())['status']);
        $this->assertSame(['reserve', 'capture', 'book', 'cancel-booking'], $this->effects());
        $cancellation->request($key, $stub->runId(), 'booking-now-confirmed');
        $this->drain();
        $this->assertSame(
            'verified',
            (new ScopeRepository())->metadata($plan->rootScope())['status'],
            $this->progress()
        );
        $this->assertSame(['reserve', 'capture', 'book', 'cancel-booking', 'refund', 'release'], $this->effects());
        $this->assertSame($history, $this->history($stub->runId()));
        $this->assertSame('started', $cancellation->finish($key, $stub->runId(), 'booking-now-confirmed')['status']);
        $this->drain();
        $this->assertSame('cancelled', $this->store->authority->subject($key, 'order')->state, $this->progress());
    }

    public function testProcessingRefundBlocksOriginalReleaseUntilAConfirmedLateResultAndFreshRecovery(): void
    {
        $stub = $this->start(refund: Status::Processing);
        $key = $this->terms->subjectKey();
        $cancellation = app(CancellationStore::class);
        $cancellation->request($key, $stub->runId(), 'processing-refund');
        $this->drain();
        $plan = $this->store->authority->plan($key);
        $this->assertSame('requested', (new ScopeRepository())->metadata($plan->rootScope())['status']);
        $this->assertSame(['reserve', 'capture', 'book', 'cancel-booking'], $this->effects());
        $this->assertSame('booked', $this->store->authority->subject($key, 'order')->state);
        $this->assertSame('pending', $cancellation->finish($key, $stub->runId(), 'processing-refund')['status']);
        $history = $this->history($stub->runId());
        $failedChildren = WorkflowRun::query()->where(
            'workflow_instance_id',
            $plan->childScope('payment')
                ->recoveryInstanceId()
        )->get();
        $this->assertCount(1, $failedChildren, $this->progress());
        $failedChild = $failedChildren->sole();
        $this->assertSame(RunStatus::Failed, $failedChild->status);
        $failedChildHistory = $this->history($failedChild->id);
        $this->provider->resolve(
            Request::fromArray($this->terms->descriptor('payment')['compensation']),
            Status::Confirmed
        );
        $cancellation->request($key, $stub->runId(), 'confirmed-late-refund');
        $this->drain();
        $this->assertSame(
            'verified',
            (new ScopeRepository())->metadata($plan->rootScope())['status'],
            $this->progress()
        );
        $this->assertSame(['reserve', 'capture', 'book', 'cancel-booking', 'refund', 'release'], $this->effects());
        $this->assertSame($history, $this->history($stub->runId()));
        $childRuns = WorkflowRun::query()->where(
            'workflow_instance_id',
            $plan->childScope('payment')
                ->recoveryInstanceId()
        )->get();
        $this->assertCount(2, $childRuns);
        $this->assertCount(
            1,
            $childRuns->filter(static fn (WorkflowRun $run): bool => $run->status === RunStatus::Completed)
        );
        $this->assertSame($failedChildHistory, $this->history($failedChild->id));
        $this->assertSame('started', $cancellation->finish($key, $stub->runId(), 'confirmed-late-refund')['status']);
        $this->drain();
        $this->assertSame('cancelled', $this->store->authority->subject($key, 'order')->state, $this->progress());
    }

    public function testCancellationBeforeRecordedOwnerInitializationNeverFabricatesAMissingScope(): void
    {
        $key = $this->terms->subjectKey();
        $stub = WorkflowStub::make(Order::class, 'provider-order-' . $key, Catalogue::NAMESPACE);
        $stub->start($key);
        $this->assertTrue($stub->terminate('before-recorded-owner')->accepted());
        $request = app(CancellationStore::class)->request($key, $stub->runId(), 'cancel-before-owner');
        $this->assertSame('unresolved', $request['status']);
        $this->assertSame('original_scope_not_initialized', $request['reason']);
        $this->assertSame(0, DB::table('workflow_compensation_scopes')->count());
        $this->assertSame([], $this->effects());
        $this->assertTrue((bool) $this->store->authority->subject($key, 'order')->cancellation_requested);
    }

    public function testForeignAssessmentIsRefusedBeforeItsInvalidProtectedPayloadIsDecoded(): void
    {
        $stub = $this->start();
        $key = $this->terms->subjectKey();
        $foreign = DB::table('provider_machine_assessments')->where(
            'actor_id',
            Authority::subjectId($key, 'payment')
        )->first();
        $payload = json_decode($foreign->payload, true, flags: JSON_THROW_ON_ERROR)['snapshot'];
        DB::table('provider_machine_assessments')->where('id', $foreign->id)->update([
            'payload' => '{invalid protected JSON',
        ]);
        $actual = WorkflowRun::query()->findOrFail($stub->runId());
        $execution = $this->store->execution($actual, 'cancel');
        $this->expectException(\Illuminate\Database\RecordsNotFoundException::class);
        app(AssessmentStore::class)->commit($actual, [
            'execution' => $execution,
            'transition' => get_object_vars(Catalogue::transition('order', 'cancel')),
            'snapshotId' => $foreign->id,
            'phase' => 'before',
            'expectedState' => 'booked',
            'expectedRevision' => '4',
            'machineAssessment' => $payload['machineAssessment'],
        ]);
    }
}
