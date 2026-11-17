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
use Workflow\V2\Compensation\Persistence\ScopeRepository;

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

    protected function reservedRoot(): bool
    {
        return true;
    }

    private function startReserved(): RootBinding
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
        $this->drain();
        return $binding;
    }
}
