<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Journal;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Status;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FinancialScopeFixture;
use Tests\TestCase;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\Compensation\Recovery\ScopeRecovery;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\WorkflowReplayer;

require_once __DIR__ . '/../../../examples/configured-state-machines/providers/bootstrap.php';

/** Real recorded scope tasks; no Order/Shipment capture-trigger or business-settlement claim. */
final class V2FinancialRetainedScopeTest extends TestCase
{
    use FinancialScopeFixture;

    public function testClosedAuthorizationOnlyOriginVoidsUnusedPermissionAndKeepsOriginalHistory(): void
    {
        $this->provider->plan($this->financialRequest('authorize'), Status::Confirmed);
        $this->provider->plan($this->financialRequest('void'), Status::Confirmed);
        $origin = $this->startFinancial();
        $this->assertSame('completed', $origin->refresh()->status());
        $history = $this->financialHistory($origin->runId());
        $this->assertSame(['authorize'], $this->financialEffects());
        $this->recoverFinancial('void-original-permission');
        $ledger = (new ScopeRepository())->inspect($this->financialScope());
        $this->assertSame('verified', $ledger['status']);
        $this->assertSame(['verified', 'verified'], array_column($ledger['obligations'], 'compensation_status'));
        $this->assertSame(['authorize', 'void-authorization'], $this->financialEffects());
        $this->assertSame(Status::NotExecuted, $this->provider->attempt($this->financialRequest('capture'))->status);
        $this->assertSame($history, $this->financialHistory($origin->runId()));
        $before = (int) $this->providerDb->query('SELECT COUNT(*) FROM provider_requests')
            ->fetchColumn();
        app(WorkflowReplayer::class)->replay(WorkflowRun::query()->findOrFail($origin->runId()));
        $this->assertSame(
            $before,
            (int) $this->providerDb->query('SELECT COUNT(*) FROM provider_requests')
                ->fetchColumn()
        );
    }

    public static function refundAcknowledgement(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('refundAcknowledgement')]
    public function testClosedCapturedOriginRefundsMoneyBeforeSettlingConsumedPermission(bool $lost): void
    {
        $this->provider->plan($this->financialRequest('authorize'), Status::Confirmed);
        $this->provider->plan($this->financialRequest('capture'), Status::Confirmed);
        $this->provider->plan($this->financialRequest('refund'), Status::Confirmed, loseAcknowledgement: $lost);
        $origin = $this->startFinancial(capture: true);
        $this->assertSame('completed', $origin->refresh()->status());
        $history = $this->financialHistory($origin->runId());
        $this->providerTime = 2000;
        $this->recoverFinancial('refund-original-capture');
        $ledger = (new ScopeRepository())->inspect($this->financialScope());
        $this->assertSame('verified', $ledger['status']);
        $this->assertSame(['authorize', 'capture-authorized', 'refund-authorized'], $this->financialEffects());
        $this->assertSame(['verified', 'verified'], array_column($ledger['obligations'], 'compensation_status'));
        $this->assertSame($history, $this->financialHistory($origin->runId()));
        $this->assertSame(Status::Confirmed, $this->provider->lookup($this->financialRequest('capture'))->status);
        $refunds = $this->providerDb->query("SELECT COUNT(*) FROM provider_effects e JOIN provider_operations o
            ON o.id = e.operation_id WHERE json_extract(o.request, '$.action') = 'refund-authorized'")->fetchColumn();
        $this->assertSame(1, (int) $refunds);
    }

    public function testAdmittedUnknownCaptureMustReconcileItsOriginalLateOutcomeBeforeRefund(): void
    {
        $this->provider->plan($this->financialRequest('authorize'), Status::Confirmed);
        $capture = $this->financialRequest('capture');
        $this->provider->plan($capture, Status::Unknown);
        $origin = $this->startFinancial(capture: true);
        $this->assertSame('failed', $origin->refresh()->status());
        $history = $this->financialHistory($origin->runId());
        $this->recoverFinancial('capture-unknown');
        $ledger = (new ScopeRepository())->inspect($this->financialScope());
        $this->assertSame('requested', $ledger['status']);
        $this->assertSame('pending', $ledger['obligations'][0]['compensation_status']);
        $this->assertSame('unknown', $ledger['obligations'][1]['compensation_status']);
        $this->assertSame(['authorize'], $this->financialEffects());
        $ids = array_column($ledger['obligations'], 'id');
        $this->providerTime = 2000;
        $this->provider->resolve($capture, Status::Confirmed);
        $this->provider->plan($this->financialRequest('refund'), Status::Confirmed);
        $this->replaceFinancialProvider();
        $this->recoverFinancial('original-capture-now-confirmed');
        $after = (new ScopeRepository())->inspect($this->financialScope());
        $this->assertSame('verified', $after['status']);
        $this->assertSame($ids, array_column($after['obligations'], 'id'));
        $this->assertSame(['authorize', 'capture-authorized', 'refund-authorized'], $this->financialEffects());
        $this->assertSame($history, $this->financialHistory($origin->runId()));
    }

    public function testUnknownRefundBlocksTheEarlierPermissionObligationAcrossReplacement(): void
    {
        $this->provider->plan($this->financialRequest('authorize'), Status::Confirmed);
        $this->provider->plan($this->financialRequest('capture'), Status::Confirmed);
        $refund = $this->financialRequest('refund');
        $this->provider->plan($refund, Status::Unknown);
        $origin = $this->startFinancial(capture: true);
        $history = $this->financialHistory($origin->runId());
        $this->recoverFinancial('refund-unknown');
        $ledger = (new ScopeRepository())->inspect($this->financialScope());
        $this->assertSame('requested', $ledger['status']);
        $this->assertSame('pending', $ledger['obligations'][0]['compensation_status']);
        $this->assertSame('unknown', $ledger['obligations'][1]['compensation_status']);
        $this->assertSame(['authorize', 'capture-authorized'], $this->financialEffects());
        $this->provider->resolve($refund, Status::Confirmed);
        $this->replaceFinancialProvider();
        $this->recoverFinancial('original-refund-now-confirmed');
        $this->assertSame('verified', (new ScopeRepository())->inspect($this->financialScope())['status']);
        $this->assertSame(['authorize', 'capture-authorized', 'refund-authorized'], $this->financialEffects());
        $this->assertSame($history, $this->financialHistory($origin->runId()));
    }

    public function testMissingPairedRegistrationRefusesBeforeAnyProviderOperation(): void
    {
        $this->provider->plan($this->financialRequest('authorize'), Status::Confirmed);
        $origin = $this->startFinancial(paired: false);
        $this->assertSame('failed', $origin->refresh()->status());
        foreach ([
            'provider_requests',
            'provider_operations',
            'provider_effects',
            'provider_authorization_links',
        ] as $table) {
            $this->assertSame(0, (int) $this->providerDb->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn());
        }
        $this->recoverFinancial('missing-pair-must-not-settle');
        $ledger = (new ScopeRepository())->inspect($this->financialScope());
        $this->assertSame('requested', $ledger['status']);
        $this->assertCount(1, $ledger['obligations']);
        $this->assertSame(['unknown'], array_column($ledger['obligations'], 'compensation_status'));
        $this->assertSame([], $this->financialEffects());
    }

    public function testExpiryBetweenOriginalAuthorizationAndCaptureRemainsVisibleWithoutMoney(): void
    {
        $this->provider->plan($this->financialRequest('authorize'), Status::Confirmed);
        $this->provider->plan($this->financialRequest('capture'), Status::Confirmed);
        $this->provider->plan($this->financialRequest('void'), Status::Confirmed);
        $origin = $this->startFinancial(
            capture: true,
            stop: fn (): bool => $this->financialEffects() === ['authorize']
        );
        $this->assertSame(['authorize'], $this->financialEffects());
        $this->providerTime = 1001;
        $this->drainFinancialTasks();
        $this->assertSame('completed', $origin->refresh()->status());
        $result = WorkflowRun::query()->findOrFail($origin->runId())->workflowOutput();
        $this->assertSame('refused', $result['capture']['status']);
        $this->assertNull($result['capture']['effectId']);
        $this->assertSame(['authorize'], $this->financialEffects());
        $this->recoverFinancial('expired-permission');
        $this->assertSame('verified', (new ScopeRepository())->inspect($this->financialScope())['status']);
        $this->assertSame(['authorize', 'void-authorization'], $this->financialEffects());
    }

    private function recoverFinancial(string $key): void
    {
        app(ScopeRecovery::class)->request($this->financialScope(), $key, 'financial-worker', 'source-fixture-cancel');
        $this->drainFinancialTasks();
    }

    private function replaceFinancialProvider(): void
    {
        $this->providerDb = new PDO('sqlite:' . $this->providerPath);
        $this->provider = new Journal($this->providerDb, 'shop-a', fn (): int => $this->providerTime);
        $this->configureFinancialHandlers();
    }
}
