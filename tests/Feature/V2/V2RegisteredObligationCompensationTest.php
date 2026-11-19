<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Status;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\V2\TestRegisteredFinancialCompensationWorkflow;
use Tests\Support\FinancialScopeFixture;
use Tests\TestCase;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\Compensation\Recovery\ScopeRecovery;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\WorkflowReplayer;
use Workflow\V2\WorkflowStub;

require_once __DIR__ . '/../../../examples/configured-state-machines/providers/bootstrap.php';

final class V2RegisteredObligationCompensationTest extends TestCase
{
    use FinancialScopeFixture;

    public static function financialModes(): array
    {
        return [['captured', ['authorize', 'capture-authorized', 'refund-authorized']],
            ['unused', ['authorize', 'void-authorization']]];
    }

    #[DataProvider('financialModes')]
    public function testExplicitAndAutomaticCompensationShareVerifiedOriginalRows(string $mode, array $effects): void
    {
        $origin = $this->startRegistered($mode);
        $this->assertSame('completed', $origin->refresh()->status());
        $ledger = (new ScopeRepository())->inspect($this->financialScope());
        $this->assertSame('verified', $ledger['status']);
        $this->assertSame(['verified', 'verified'], array_column($ledger['obligations'], 'compensation_status'));
        $this->assertSame($effects, $this->financialEffects());
        $before = $this->requestCount();
        $history = $this->financialHistory($origin->runId());
        app(WorkflowReplayer::class)->replay(WorkflowRun::query()->findOrFail($origin->runId()));
        $this->assertSame($before, $this->requestCount());
        $this->assertSame($history, $this->financialHistory($origin->runId()));
    }

    public function testUnknownRefundThrowsAndKeepsEarlierPermissionOutstandingForOriginalRecovery(): void
    {
        $origin = $this->startRegistered('captured', Status::Unknown);
        $this->assertSame('failed', $origin->refresh()->status());
        $ledger = (new ScopeRepository())->inspect($this->financialScope());
        $this->assertSame('requested', $ledger['status']);
        $this->assertSame(['pending', 'unknown'], array_column($ledger['obligations'], 'compensation_status'));
        $this->assertSame(['authorize', 'capture-authorized'], $this->financialEffects());
        $ids = array_column($ledger['obligations'], 'id');
        $history = $this->financialHistory($origin->runId());
        $this->provider->resolve($this->financialRequest('refund'), Status::Confirmed);
        app(ScopeRecovery::class)->request(
            $this->financialScope(),
            'original-refund-resolved',
            'financial-worker',
            'source-fixture-cancel'
        );
        $this->drainFinancialTasks();
        $after = (new ScopeRepository())->inspect($this->financialScope());
        $this->assertSame('verified', $after['status']);
        $this->assertSame($ids, array_column($after['obligations'], 'id'));
        $this->assertSame(['authorize', 'capture-authorized', 'refund-authorized'], $this->financialEffects());
        $this->assertSame($history, $this->financialHistory($origin->runId()));
    }

    public function testExplicitEarlierCompensationCannotBypassTheLaterMoneyObligation(): void
    {
        $origin = $this->startRegistered('out-of-order');
        $this->assertSame('failed', $origin->refresh()->status());
        $ledger = (new ScopeRepository())->inspect($this->financialScope());
        $this->assertSame('requested', $ledger['status']);
        $this->assertSame(['pending', 'pending'], array_column($ledger['obligations'], 'compensation_status'));
        $this->assertSame(['authorize', 'capture-authorized'], $this->financialEffects());
    }

    private function startRegistered(string $mode, Status $refund = Status::Confirmed): WorkflowStub
    {
        foreach (['authorize', 'capture', 'void', 'refund'] as $key) {
            $this->provider->plan($this->financialRequest($key), $key === 'refund' ? $refund : Status::Confirmed);
        }
        $origin = WorkflowStub::make(
            TestRegisteredFinancialCompensationWorkflow::class,
            'registered-financial',
            'financial-tests'
        );
        $origin->start($this->financialScope()->toArray(), $this->financialDescriptor(), $mode);
        $this->originalRun = $origin->runId();
        $this->drainFinancialTasks();
        return $origin;
    }

    private function requestCount(): int
    {
        return (int) $this->providerDb->query('SELECT COUNT(*) FROM provider_requests')
            ->fetchColumn();
    }
}
