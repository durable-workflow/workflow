<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Request;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Status;
use DurableWorkflow\Examples\ProviderStateMachines\CancellationStore;
use DurableWorkflow\Examples\ProviderStateMachines\Catalogue;
use PDO;
use Tests\Support\ProviderMachineFixture;
use Tests\TestCase;
use Workflow\V2\Compensation\Persistence\ScopeRepository;

require_once __DIR__ . '/../../../examples/provider-state-machines/bootstrap.php';

final class V2ProviderRefundOutcomeTest extends TestCase
{
    use ProviderMachineFixture;

    public function testUnknownRefundReconcilesTheOriginalEffectBeforeStockReleaseAndFinalSettlement(): void
    {
        $stub = $this->start(refund: Status::Unknown);
        $key = $this->terms->subjectKey();
        $cancellation = app(CancellationStore::class);
        $cancellation->request($key, $stub->runId(), 'unknown-refund');
        $this->drain();
        $this->assertOutstandingRefund(Status::Unknown);
        $this->assertSame('pending', $cancellation->finish($key, $stub->runId(), 'unknown-refund')['status']);
        $history = $this->history($stub->runId());
        $plan = $this->store->authority->plan($key);
        $paymentRun = (new ScopeRepository())->metadata($plan->childScope('payment'))['original_run_id'];
        $paymentHistory = $this->history($paymentRun);
        $refund = Request::fromArray($this->terms->descriptor('payment')['compensation']);
        $this->provider->resolve($refund, Status::Confirmed);
        $cancellation->request($key, $stub->runId(), 'original-refund-now-confirmed');
        $this->drain();
        $this->assertSame(
            'verified',
            (new ScopeRepository())->metadata($plan->rootScope())['status'],
            $this->progress()
        );
        $this->assertSame(['capture', 'reserve', 'book', 'cancel-booking', 'refund', 'release'], $this->effects());
        $this->assertSame([$refund->identity->key()], $this->refundOperationIdentities());
        $this->assertSame($history, $this->history($stub->runId()));
        $this->assertSame($paymentHistory, $this->history($paymentRun));
        $this->assertSame('started', $cancellation->finish($key, $stub->runId(), 'unknown-refund')['status']);
        $this->drain();
        $this->assertSame('cancelled', $this->store->authority->subject($key, 'order')->state, $this->progress());
        $this->assertSame($history, $this->history($stub->runId()));
        $this->assertSame($paymentHistory, $this->history($paymentRun));
    }

    public function testDefinitiveRefundRefusalCannotBeConvertedToSettlementByAnotherRecoveryRequest(): void
    {
        $stub = $this->start(refund: Status::Refused);
        $key = $this->terms->subjectKey();
        $cancellation = app(CancellationStore::class);
        $cancellation->request($key, $stub->runId(), 'refused-refund');
        $this->drain();
        $this->assertOutstandingRefund(Status::Refused);
        $history = $this->history($stub->runId());
        $plan = $this->store->authority->plan($key);
        $paymentRun = (new ScopeRepository())->metadata($plan->childScope('payment'))['original_run_id'];
        $paymentHistory = $this->history($paymentRun);
        $refund = Request::fromArray($this->terms->descriptor('payment')['compensation']);
        $requests = $this->refundRequestCount();
        $this->assertGreaterThan(0, $requests);
        $cancellation->request($key, $stub->runId(), 'refused-refund-new-recovery');
        $this->drain();
        $this->assertOutstandingRefund(Status::Refused);
        $this->assertGreaterThan($requests, $this->refundRequestCount());
        $this->assertSame([$refund->identity->key()], $this->refundOperationIdentities());
        $this->assertSame('pending', $cancellation->finish($key, $stub->runId(), 'refused-refund')['status']);
        $this->assertSame($history, $this->history($stub->runId()));
        $this->assertSame($paymentHistory, $this->history($paymentRun));
    }

    protected function recipe(): string
    {
        return Catalogue::PAYMENT_FIRST;
    }

    private function assertOutstandingRefund(Status $status): void
    {
        $key = $this->terms->subjectKey();
        $plan = $this->store->authority->plan($key);
        $repository = new ScopeRepository();
        $this->assertSame('requested', $repository->metadata($plan->rootScope())['status']);
        $this->assertSame('booked', $this->store->authority->subject($key, 'order')->state);
        $this->assertSame(['capture', 'reserve', 'book', 'cancel-booking'], $this->effects());
        $payment = $repository->inspect($plan->childScope('payment'));
        $effect = array_values(array_filter(
            $payment['obligations'],
            static fn (array $row): bool => $row['descriptor']['key'] === 'effect'
        ))[0];
        $this->assertSame('unknown', $effect['compensation_status']);
        $this->assertSame(Status::Confirmed, $this->provider->lookup(
            Request::fromArray($this->terms->descriptor('payment')['forward'])
        )->status);
        $observation = $this->provider->lookup(Request::fromArray($this->terms->descriptor('payment')['compensation']));
        $this->assertSame($status, $observation->status);
        $this->assertNull($observation->effectId);
    }

    private function refundOperationIdentities(): array
    {
        return $this->providerDb->query(
            'SELECT DISTINCT r.operation_id FROM provider_requests r JOIN provider_operations o ON o.id = r.operation_id WHERE r.kind = \'command\' AND json_extract(o.request, \'$.action\') = \'refund\' ORDER BY r.operation_id'
        )->fetchAll(PDO::FETCH_COLUMN);
    }

    private function refundRequestCount(): int
    {
        return (int) $this->providerDb->query(
            'SELECT COUNT(*) FROM provider_requests r JOIN provider_operations o ON o.id = r.operation_id WHERE r.kind = \'command\' AND json_extract(o.request, \'$.action\') = \'refund\''
        )->fetchColumn();
    }
}
