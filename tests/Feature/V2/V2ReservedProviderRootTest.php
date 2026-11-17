<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Request;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Status;
use DurableWorkflow\Examples\ProviderStateMachines\RootCancellationStore;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Workflow\V2\Compensation\Admission\NeverAdmittedRoot;
use Workflow\V2\Compensation\Admission\RootBinding;
use Workflow\V2\Compensation\Admission\RootReservationStore;
use Workflow\V2\Compensation\Definition\PortableDescriptor;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\WorkflowStub;

require_once __DIR__ . '/../../../examples/provider-state-machines/bootstrap.php';

final class V2ReservedProviderRootTest extends TestCase
{
    use \Tests\Support\ProviderMachineFixture;

    public function testOriginalBusinessIntentCanBeClosedBeforeAnyNativeReservation(): void
    {
        $key = $this->terms->subjectKey();
        $identity = $this->store->authority->roots->identity($key);
        $this->assertSame($this->terms->generation, $identity->scope->generation);
        $roots = app(RootReservationStore::class);
        $closed = $roots->closeIntent($identity, 'provider-worker');
        $this->assertInstanceOf(NeverAdmittedRoot::class, $closed);
        $this->assertSame([], $this->effects());
        $this->assertNull(DB::table('provider_machine_assignments')->where('id', $key)->value('root_run'));
        $this->assertEquals($closed, $roots->start($closed->reservation, 'provider-worker', [$key]));
    }

    public function testReservedOriginalRootUsesItsGenuineScopeAndCanonicalChildParent(): void
    {
        $binding = $this->startReserved();
        $key = $this->terms->subjectKey();
        $plan = $this->store->authority->plan($key);
        $this->assertSame($binding->reservation->identity->scope->toArray(), $plan->rootScope()->toArray());
        $this->assertSame($binding->reservation->identity->instanceId(), $plan->binding('shipment')->parentInstanceId);
        $this->assertSame($binding->reservation->originalRunId, $plan->rootRun);
        $this->assertSame(['reserve', 'capture', 'book'], $this->effects(), $this->progress());
        $this->assertSame($plan->rootRun, (new ScopeRepository())->metadata($plan->rootScope())['original_run_id']);
    }

    public function testReservedOriginalRootCancellationCompensatesItsGenuineChildren(): void
    {
        $this->startReserved();
        $key = $this->terms->subjectKey();
        $result = app(RootCancellationStore::class)->request($key, 'cancel-original-root');
        $this->assertSame('requested', $result['status']);
        $this->drain();
        $this->assertSame(
            ['reserve', 'capture', 'book', 'cancel-booking', 'refund', 'release'],
            $this->effects(),
            $this->progress()
        );
        $settled = app(RootCancellationStore::class)->request($key, 'cancel-original-root');
        $this->assertSame('cancelled', $settled['status'], $this->progress());
        $this->assertSame($settled, app(RootCancellationStore::class)->request($key, 'cancel-original-root'));
        $this->assertSame(
            0,
            WorkflowRun::query()->where(
                'workflow_class',
                \DurableWorkflow\Examples\ProviderStateMachines\Cancellation::class
            )->count()
        );
    }

    public function testForeignNativeOwnerCannotCloseTheStillReservedOriginalRoot(): void
    {
        $key = $this->terms->subjectKey();
        $identity = $this->store->authority->roots->identity($key);
        $roots = app(RootReservationStore::class);
        $reservation = $roots->reserve($identity, 'provider-worker');
        try {
            $this->store->authority->roots->reservationFor($key, 'foreign-native-run');
            $this->fail('A foreign native owner was accepted.');
        } catch (\LogicException $error) {
            $this->assertStringContainsString('another native run', $error->getMessage());
        }
        $this->assertSame(
            'root_reserved',
            DB::table('workflow_compensation_scopes')->where('id', $identity->scope->id())->value('status')
        );
        $this->assertSame($reservation->toArray(), $roots->load($identity, 'provider-worker')->toArray());
        $this->assertInstanceOf(RootBinding::class, $roots->start($reservation, 'provider-worker', [$key]));
    }

    public function testClosedOriginalRootDrivesItsProofOnlyFinalizerAndKeepsAllOriginalHistories(): void
    {
        $binding = $this->startReserved();
        $key = $this->terms->subjectKey();
        $runId = $binding->reservation->originalRunId;
        WorkflowStub::loadRun($runId)->signal('finish');
        $this->drain();
        $this->assertSame(RunStatus::Completed, WorkflowRun::query()->findOrFail($runId)->status);
        $original = [];
        foreach (WorkflowRun::query()->get() as $run) {
            $original[$run->id] = $this->history($run->id);
        }
        $cancellation = app(RootCancellationStore::class);
        $request = $cancellation->request($key, 'cancel-closed-original');
        $this->assertSame('recovery', $request['route']['route']);
        $this->drain();
        $cancellation->request($key, 'cancel-closed-original');
        $this->drain();
        $this->assertSame('cancelled', $this->store->authority->subject($key, 'order')->state, $this->progress());
        $result = $cancellation->request($key, 'cancel-closed-original');
        $this->assertSame('cancelled', $result['status']);
        $this->assertSame(['reserve', 'capture', 'book', 'cancel-booking', 'refund', 'release'], $this->effects());
        $this->assertSame($result, $cancellation->request($key, 'cancel-closed-original'));
        $runs = WorkflowRun::query()->count();
        $this->drain();
        $this->assertSame($runs, WorkflowRun::query()->count());
        foreach ($original as $id => $history) {
            $this->assertSame($history, $this->history($id));
        }
    }

    public function testOwnerInitializedBeforeLedgerRegistrationStillUsesItsGenuineScopeAndOneFinalizer(): void
    {
        $binding = $this->admitReserved();
        $key = $this->terms->subjectKey();
        $runId = $binding->reservation->originalRunId;
        $this->drain(static fn (): bool => DB::table('provider_machine_actors')->exists());
        $this->assertSame($runId, DB::table('provider_machine_assignments')->value('root_run'));
        $this->assertSame(0, DB::table('workflow_compensation_obligations')->count());
        $this->assertTrue(
            WorkflowStub::loadRun($runId)->terminate('Stop after original actor initialization.')->accepted()
        );
        $this->drain();
        $original = $this->history($runId);
        $cancellation = app(RootCancellationStore::class);
        $request = $cancellation->request($key, 'cancel-before-owner-registration');
        $this->assertSame('recovery', $request['route']['route']);
        $this->drain();
        $finalizer = $cancellation->request($key, 'cancel-before-owner-registration');
        $this->assertSame('started', $finalizer['finalizer']['status'], $this->progress());
        $this->drain();
        $result = $cancellation->request($key, 'cancel-before-owner-registration');
        $this->assertSame('cancelled', $result['status'], $this->progress());
        $this->assertSame('2', $result['revision']);
        $this->assertSame($runId, $result['proof']['binding']['reservation']['originalRunId']);
        $this->assertSame([], $this->effects());
        $this->assertSame($original, $this->history($runId));
        $this->assertSame(1, DB::table('example_machine_publications')->count());
        $this->assertSame(1, DB::table('provider_machine_cancellations')->count());
        $this->assertSame($result, $cancellation->request($key, 'cancel-before-owner-registration'));
        $publicationId = $result['proof']['publicationId'];
        $originalPayload = DB::table('example_machine_publications')->where('id', $publicationId)->value('payload');
        $payload = json_decode($originalPayload, true, flags: JSON_THROW_ON_ERROR);
        $payload['originalRunId'] = 'foreign-original-run';
        DB::table('example_machine_publications')->where('id', $publicationId)->update([
            'payload' => PortableDescriptor::encode($payload),
        ]);
        try {
            $cancellation->request($key, 'cancel-before-owner-registration');
            $this->fail('A substituted original business publication was accepted.');
        } catch (\LogicException $error) {
            $this->assertStringContainsString('publication changed', $error->getMessage());
        } finally {
            DB::table('example_machine_publications')->where('id', $publicationId)->update([
                'payload' => $originalPayload,
            ]);
        }
        DB::table('example_machine_publications')->where('id', $publicationId)->delete();
        try {
            $cancellation->request($key, 'cancel-before-owner-registration');
            $this->fail('A mutable cancelled state replaced the missing business publication.');
        } catch (\LogicException $error) {
            $this->assertStringContainsString('exactly one committed business publication', $error->getMessage());
        }
        $this->assertSame(
            1,
            WorkflowRun::query()->where(
                'workflow_class',
                \DurableWorkflow\Examples\ProviderStateMachines\Cancellation::class
            )->count()
        );
        $this->assertSame([], $this->effects());
    }

    protected function reservedRoot(): bool
    {
        return true;
    }

    private function startReserved(): RootBinding
    {
        $binding = $this->admitReserved();
        $this->drain();
        return $binding;
    }

    private function admitReserved(): RootBinding
    {
        foreach (['order', 'payment', 'shipment'] as $role) {
            $descriptor = $this->terms->descriptor($role);
            foreach (['forward', 'compensation'] as $kind) {
                $this->provider->plan(Request::fromArray($descriptor[$kind]), Status::Confirmed);
            }
        }
        $key = $this->terms->subjectKey();
        $roots = app(RootReservationStore::class);
        $reservation = $roots->reserve($this->store->authority->roots->identity($key), 'provider-worker');
        $binding = $roots->start($reservation, 'provider-worker', [$key]);
        $this->assertInstanceOf(RootBinding::class, $binding);
        return $binding;
    }
}
