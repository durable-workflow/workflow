<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Request;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Status;
use DurableWorkflow\Examples\ProviderStateMachines\CancellationStore;
use DurableWorkflow\Examples\ProviderStateMachines\Catalogue;
use Tests\Support\ProviderMachineFixture;
use Tests\TestCase;

require_once __DIR__ . '/../../../examples/provider-state-machines/bootstrap.php';

final class V2CommonReserveFirstProviderMachineTest extends TestCase
{
    use ProviderMachineFixture;

    public function testTheCommonRouteUsesReservationBeforeCaptureAndVerifiedCancellation(): void
    {
        $stub = $this->start();
        $key = $this->terms->subjectKey();
        $this->assertSame(['reserve', 'capture', 'book'], $this->effects());
        $this->assertSame('booked', $this->store->authority->subject($key, 'order')->state);
        app(CancellationStore::class)->request($key, $stub->runId(), 'cancel-reserve-first-checkout');
        $this->drain();
        $this->assertSame(['reserve', 'capture', 'book', 'cancel-booking', 'refund', 'release'], $this->effects());
        $this->assertSame('cancelled', $this->store->authority->subject($key, 'order')->state, $this->progress());
    }

    public function testPendingStockCannotCaptureMoneyAndReconciliationKeepsOneReservation(): void
    {
        $stub = $this->start(reservation: Status::Processing);
        $key = $this->terms->subjectKey();
        $this->assertSame([], $this->effects());
        $this->assertSame('received', $this->store->authority->subject($key, 'order')->state);
        $stub->signal('reconcile-stock');
        $this->drain();
        $this->assertSame([], $this->effects());
        $forward = Request::fromArray($this->terms->descriptor('order')['forward']);
        $this->provider->resolve($forward, Status::Confirmed);
        $stub->signal('reconcile-stock');
        $this->drain();
        $this->assertSame(['reserve', 'capture', 'book'], $this->effects());
        $query = $this->providerDb->prepare(
            'SELECT COUNT(*) FROM provider_requests WHERE operation_id = ? AND kind = ?'
        );
        $query->execute([$forward->identity->key(), 'command']);
        $this->assertSame(1, (int) $query->fetchColumn());
    }

    protected function recipe(): string
    {
        return Catalogue::COMMON_RESERVE_FIRST;
    }
}
