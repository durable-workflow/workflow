<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\AuthorizationTerms;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Request;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Status;
use DurableWorkflow\Examples\ProviderStateMachines\AuthorizationCheckout;
use DurableWorkflow\Examples\ProviderStateMachines\CancellationStore;
use DurableWorkflow\Examples\ProviderStateMachines\Catalogue;
use DurableWorkflow\Examples\ProviderStateMachines\FinancialCatalogue;
use DurableWorkflow\Examples\ProviderStateMachines\OrderTerms;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ProviderMachineFixture;
use Tests\TestCase;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\WorkflowStub;

require_once __DIR__ . '/../../../examples/provider-state-machines/bootstrap.php';

/** Native cancellation must reconcile the original admitted capture before choosing refund or void. */
final class V2CaptureCancellationMachineTest extends TestCase
{
    use ProviderMachineFixture {
        setUp as private setUpProvider;
    }

    protected function setUp(): void
    {
        $this->setUpProvider();
        $this->provider->installAuthorizationSupport();
    }

    public static function captureOutcomes(): array
    {
        return [
            'unknown then captured' => [Status::Unknown, Status::Confirmed],
            'processing then captured' => [Status::Processing, Status::Confirmed],
            'unknown then not executed' => [Status::Unknown, Status::NotExecuted],
            'processing then not executed' => [Status::Processing, Status::NotExecuted],
        ];
    }

    #[DataProvider('captureOutcomes')]
    public function testCancellationWaitsForTheOriginalCaptureOutcome(Status $initial, Status $resolved): void
    {
        $origin = $this->startCapture($initial);
        $key = $this->terms->subjectKey();
        $plan = $this->store->authority->plan($key);
        $scopes = new ScopeRepository();
        $original = $scopes->inspect($plan->childScope('payment'));
        $ids = array_column($original['obligations'], 'id');
        $this->assertCount(3, $ids);
        $this->assertSame(['authorize', 'reserve'], $this->effects(), $this->progress());
        $capture = Request::fromArray($this->terms->financialDescriptor()['capture']);
        $this->assertSame($initial, $this->provider->lookup($capture)->status);

        $cancellation = app(CancellationStore::class);
        $cancellation->request($key, $origin->runId(), 'cancel-original-pending-capture');
        $this->drain();
        $this->assertSame('requested', $scopes->metadata($plan->rootScope())['status'], $this->progress());
        $this->assertNotSame('cancelled', $this->orderState());
        $this->assertSame(['authorize', 'reserve'], $this->effects());
        $this->assertSame('pending', $cancellation->finish(
            $key,
            $origin->runId(),
            'cancel-original-pending-capture'
        )['status']);
        $this->assertSame($initial, $this->provider->lookup($capture)->status);
        $this->assertTrue($this->provider->lookup($capture)->admissionClosed);

        $this->provider->resolve($capture, $resolved);
        $cancellation->request($key, $origin->runId(), 'reconcile-original-capture');
        $this->drain();
        if ($resolved === Status::Confirmed) {
            $this->assertSame(['authorize', 'reserve', 'capture-authorized'], $this->effects());
            $this->assertSame('requested', $scopes->metadata($plan->rootScope())['status']);
            $this->assertNotSame('cancelled', $this->orderState());
            $refund = Request::fromArray($this->terms->financialDescriptor()['refund']);
            $this->assertSame(Status::Unknown, $this->provider->lookup($refund)->status);
            $this->provider->resolve($refund, Status::Confirmed);
            $cancellation->request($key, $origin->runId(), 'reconcile-original-refund');
            $this->drain();
        }
        $this->assertSame('verified', $scopes->metadata($plan->rootScope())['status'], $this->progress());
        $this->assertSame(
            $resolved === Status::Confirmed
                ? ['authorize', 'reserve', 'capture-authorized', 'refund-authorized', 'release']
                : ['authorize', 'reserve', 'void-authorization', 'release'],
            $this->effects()
        );
        $payment = $scopes->inspect($plan->childScope('payment'));
        $this->assertSame($original['original_run_id'], $payment['original_run_id']);
        $this->assertSame($ids, array_column($payment['obligations'], 'id'));
        $this->assertSame('verified', $payment['status']);
        $this->assertCaptureIdentity($capture, $resolved === Status::Confirmed);

        $rootHistory = $this->history($origin->runId());
        $paymentHistory = $this->history($original['original_run_id']);
        $this->assertTrue(WorkflowRun::query()->findOrFail($origin->runId())->status->isTerminal());
        $this->assertTrue(WorkflowRun::query()->findOrFail($original['original_run_id'])->status->isTerminal());
        $this->assertSame('started', $cancellation->finish(
            $key,
            $origin->runId(),
            'cancel-original-pending-capture'
        )['status']);
        $this->drain();
        $this->assertSame('cancelled', $this->orderState(), $this->progress());
        $this->assertSame($rootHistory, $this->history($origin->runId()));
        $this->assertSame($paymentHistory, $this->history($original['original_run_id']));
    }

    protected function recipe(): string
    {
        return FinancialCatalogue::AFTER_STOCK;
    }

    protected function orderTerms(): OrderTerms
    {
        return new OrderTerms(
            'shop-a',
            'purchase-1',
            'business-generation-1',
            1499,
            'USD',
            'sku-1',
            2,
            new AuthorizationTerms(1499, 'USD', Carbon::now()->getTimestamp() + 3600)
        );
    }

    protected function providerClock(): ?\Closure
    {
        return static fn (): int => Carbon::now()->getTimestamp();
    }

    private function startCapture(Status $status): WorkflowStub
    {
        foreach ($this->terms->descriptor('order') as $request) {
            $this->provider->plan(Request::fromArray($request), Status::Confirmed);
        }
        foreach ($this->terms->financialDescriptor() as $stage => $request) {
            $this->provider->plan(Request::fromArray($request), match ($stage) {
                'capture' => $status,
                'refund' => Status::Unknown,
                default => Status::Confirmed,
            });
        }
        $capture = Request::fromArray($this->terms->financialDescriptor()['capture']);
        $origin = WorkflowStub::make(
            AuthorizationCheckout::class,
            'provider-order-' . $this->terms->subjectKey(),
            Catalogue::NAMESPACE
        );
        $origin->start($this->terms->subjectKey());
        $this->drain(function () use ($capture, $status): bool {
            $q = $this->providerDb->prepare('SELECT status FROM provider_operations WHERE id = ?');
            $q->execute([$capture->identity->key()]);
            return $q->fetchColumn() === $status->value;
        });
        return $origin;
    }

    private function orderState(): string
    {
        return $this->store->authority->subject($this->terms->subjectKey(), 'order')
->state;
    }

    private function assertCaptureIdentity(Request $capture, bool $committed): void
    {
        $operations = $this->providerDb->query(
            'SELECT id FROM provider_operations WHERE json_extract(request, \'$.action\') = \'capture-authorized\''
        )->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame([$capture->identity->key()], $operations);
        $q = $this->providerDb->prepare(
            'SELECT COUNT(*) FROM provider_requests WHERE operation_id = ? AND kind = \'command\''
        );
        $q->execute([$capture->identity->key()]);
        $this->assertSame(1, (int) $q->fetchColumn());
        $q = $this->providerDb->prepare('SELECT COUNT(*) FROM provider_effects WHERE operation_id = ?');
        $q->execute([$capture->identity->key()]);
        $this->assertSame($committed ? 1 : 0, (int) $q->fetchColumn());
    }
}
