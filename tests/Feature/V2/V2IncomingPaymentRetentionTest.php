<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Identity;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\IncomingPaymentDescriptor;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\IncomingPaymentJournal;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\IncomingPaymentOperation;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\IncomingPaymentReceipt;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Journal;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Request;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Status;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use LogicException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\V2\TestIncomingPaymentRetentionWorkflow;
use Tests\TestCase;
use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Compensation\Definition\PortableDescriptor;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\Execution\HandlerRegistry;
use Workflow\V2\Compensation\Execution\OperationContext;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\Compensation\Recovery\ScopeRecovery;
use Workflow\V2\Compensation\Security\ScopeAuthorization;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\WorkflowReplayer;
use Workflow\V2\WorkflowStub;

require_once __DIR__ . '/../../../examples/configured-state-machines/providers/bootstrap.php';

/** Native retained incoming-money duty; common Order/Payment policy and installed/cold-process proof remain separate. */
final class V2IncomingPaymentRetentionTest extends TestCase
{
    private PDO $providerDb;

    private Journal $provider;

    private string $providerPath;

    private ?string $originalRun = null;

    private int $providerTime = 1000;

    private string $contract = IncomingPaymentJournal::CLOSABLE;

    private bool $allowed = true;

    private int $authorizations = 0;

    protected function setUp(): void
    {
        parent::setUp();
        self::stopWorkers();
        Queue::fake();
        Carbon::setTestNow('2026-01-01T00:00:00Z');
        config([
            'workflows.v2.namespaces' => ['incoming-retained'],
            'workflows.v2.task_dispatch_mode' => 'queue',
            'queue.default' => 'redis',
        ]);
        $this->providerPath = tempnam(sys_get_temp_dir(), 'native-incoming-retained-');
        $this->providerDb = new PDO('sqlite:' . $this->providerPath);
        $this->provider = new Journal($this->providerDb, 'shop-a', fn (): int => $this->providerTime);
        $this->provider->install();
        $this->provider->installIncomingPaymentSupport();
        $this->configureHandler();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->app->forgetInstance(HandlerRegistry::class);
        unset($this->provider, $this->providerDb);
        if (isset($this->providerPath)) {
            unlink($this->providerPath);
        }
        parent::tearDown();
    }

    #[DataProvider('acknowledgements')]
    public function testCompletedOriginRetainsLateIncomingMoneyAndRefundsTheSameOriginalTransfer(bool $lost): void
    {
        $origin = $this->start();
        $this->assertSame('completed', $origin->refresh()->status());
        $history = $this->history();
        $this->providerTime = 2000;
        $receipt = $this->receive('late-original-transfer', 1500);
        $request = $this->refund($receipt);
        $this->provider->plan($request, Status::Confirmed, $lost);
        $this->recover('late-cancel');
        $ledger = (new ScopeRepository())->inspect($this->scope());
        $this->assertSame('verified', $ledger['status']);
        $this->assertSame(['verified'], array_column($ledger['obligations'], 'compensation_status'));
        $this->assertSame([1500], $this->effectAmounts());
        $this->assertSame($history, $this->history());
        $this->assertSame('completed', $origin->refresh()->status());
        $this->assertSame(1500, $this->provider->incomingPayments()->inspect($this->original())['receivedAmountMinor']);
        $before = $this->authorizations;
        $requests = (int) $this->providerDb->query('SELECT COUNT(*) FROM provider_requests')
            ->fetchColumn();
        $this->allowed = false;
        app(WorkflowReplayer::class)->replay(WorkflowRun::query()->findOrFail($origin->runId()));
        $this->assertSame($before, $this->authorizations);
        $this->assertSame(
            $requests,
            (int) $this->providerDb->query('SELECT COUNT(*) FROM provider_requests')
                ->fetchColumn()
        );
        $this->assertSame($history, $this->history());
    }

    public static function acknowledgements(): array
    {
        return [[false], [true]];
    }

    public function testNeverDispatchedReferenceCreationHasTheNativeAdmissionFenceWithoutProviderEffects(): void
    {
        $origin = $this->start(false);
        $this->assertSame('completed', $origin->refresh()->status());
        $this->assertSame(
            0,
            (int) $this->providerDb->query('SELECT COUNT(*) FROM incoming_payment_references')
                ->fetchColumn()
        );
        $this->recover('never-opened-reference');
        $ledger = (new ScopeRepository())->inspect($this->scope());
        $this->assertSame('verified', $ledger['status']);
        $row = $ledger['obligations'][0];
        $this->assertSame('registered', $row['forward_status']);
        $proof = json_decode($row['evidence'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['never-dispatched:' . $row['id']], $proof['evidence']);
        $this->assertTrue($proof['forwardSettled']);
        $this->assertSame(
            0,
            (int) $this->providerDb->query('SELECT COUNT(*) FROM incoming_payment_closures')
                ->fetchColumn()
        );
        $this->assertSame([], $this->effectAmounts());
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The original incoming-payment reference is unavailable.');
        $this->receive('after-fence', 1000);
    }

    #[DataProvider('pendingRefunds')]
    public function testRefundUncertaintySurvivesReplacementAndOnlyOriginalConfirmationSettlesIt(Status $status): void
    {
        $this->start();
        $receipt = $this->receive('original-transfer', 1000);
        $refund = $this->refund($receipt);
        $this->provider->plan($refund, $status);
        $this->recover('refund-not-complete');
        $ledger = (new ScopeRepository())->inspect($this->scope());
        $this->assertSame('requested', $ledger['status']);
        $this->assertSame(['unknown'], array_column($ledger['obligations'], 'compensation_status'));
        $this->assertSame([], $this->effectAmounts());
        $ids = array_column($ledger['obligations'], 'id');
        $this->provider->resolve($refund, Status::Confirmed);
        $this->providerDb = new PDO('sqlite:' . $this->providerPath);
        $this->provider = new Journal($this->providerDb, 'shop-a', fn (): int => $this->providerTime);
        $this->configureHandler();
        $this->recover('original-refund-now-confirmed');
        $after = (new ScopeRepository())->inspect($this->scope());
        $this->assertSame('verified', $after['status']);
        $this->assertSame($ids, array_column($after['obligations'], 'id'));
        $this->assertSame([1000], $this->effectAmounts());
    }

    public static function pendingRefunds(): array
    {
        return [[Status::Unknown], [Status::Processing]];
    }

    public function testPartlyCompensatedTransfersResumeWithoutRepeatingConfirmedProviderEffects(): void
    {
        $this->start();
        $first = $this->receive('advance', 400);
        $this->providerTime = 1001;
        $second = $this->receive('balance', 600);
        $this->provider->plan($this->refund($first), Status::Confirmed);
        $pending = $this->refund($second);
        $this->provider->plan($pending, Status::Unknown);
        $this->recover('first-cancel');
        $this->assertSame([400], $this->effectAmounts());
        $this->assertSame('requested', (new ScopeRepository())->inspect($this->scope())['status']);
        $this->provider->resolve($pending, Status::Confirmed);
        $this->recover('resume-original-cancel');
        $this->assertSame('verified', (new ScopeRepository())->inspect($this->scope())['status']);
        $this->assertSame([400, 600], $this->effectAmounts());
    }

    public function testUnfencedCancellationKeepsLateIncomingAdmissionAndRetainedDutyVisibleAfterRefund(): void
    {
        $this->contract = IncomingPaymentJournal::OPEN;
        $this->start();
        $this->providerTime = 2000;
        $this->recover('expired-but-unfenced');
        $this->assertSame('requested', (new ScopeRepository())->inspect($this->scope())['status']);
        $receipt = $this->receive('late-after-cancel', 1000);
        $this->provider->plan($this->refund($receipt), Status::Confirmed);
        $this->recover('refund-actual-late-money');
        $this->assertSame([1000], $this->effectAmounts());
        $this->assertSame('requested', (new ScopeRepository())->inspect($this->scope())['status']);
        $this->assertSame(
            0,
            (int) $this->providerDb->query('SELECT COUNT(*) FROM incoming_payment_closures')
                ->fetchColumn()
        );
        $this->receive('still-open-transfer', 1);
        $this->assertSame(1001, $this->provider->incomingPayments()->inspect($this->original())['receivedAmountMinor']);
    }

    public function testRehashedForeignReceivableIsRefusedBeforeAnyProviderClosureOrRefund(): void
    {
        $this->start();
        $foreign = $this->obligation()
            ->toArray();
        $foreign['input']['original']['identity']['generation'] = 'foreign-period';
        $payload = PortableDescriptor::encode($foreign);
        DB::table('workflow_compensation_obligations')->where('scope_id', $this->scope()->id())->update([
            'descriptor' => $payload,
            'fingerprint' => hash('sha256', $payload),
        ]);
        $this->recover('refuse-foreign-period');
        $this->assertSame('requested', (new ScopeRepository())->inspect($this->scope())['status']);
        foreach (['incoming_payment_closures', 'provider_requests', 'provider_effects'] as $table) {
            $this->assertSame(0, (int) $this->providerDb->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn());
        }
        $this->assertSame(
            1,
            (int) $this->providerDb->query('SELECT COUNT(*) FROM incoming_payment_references')
                ->fetchColumn()
        );
    }

    private function configureHandler(): void
    {
        $scope = $this->scope();
        $this->app->instance(ScopeAuthorization::class, new class($scope) implements ScopeAuthorization {
            public function __construct(
                private readonly ScopeIdentity $expected
            ) {
            }

            public function assertAllowed(ScopeIdentity $scope, string $principal, string $operation): string
            {
                if ($scope->toArray() !== $this->expected->toArray() || $principal !== 'incoming-worker') {
                    throw new LogicException('The original incoming scope/principal is unavailable.');
                }
                return 'incoming-host-v1';
            }
        });
        $this->app->instance(HandlerRegistry::class, new HandlerRegistry([
            'test.incoming.retained' => [
                '1' => new IncomingPaymentOperation($this->provider, function (
                    OperationContext $context,
                    string $mode
                ): void {
                    ++$this->authorizations;
                    if (! $this->allowed || $context->scope->toArray() !== $this->scope()->toArray()
                        || $context->originalRunId !== $this->originalRun || ! in_array(
                            $mode,
                            ['forward', 'compensate'],
                            true
                        )
                        || $context->obligation->fingerprint() !== $this->obligation()
                            ->fingerprint()) {
                        throw new LogicException(
                            'The host refused the original incoming business/run/scope/descriptor.'
                        );
                    }
                    $actual = WorkflowRun::query()->findOrFail($context->originalRunId);
                    $ledger = (new ScopeRepository())->inspect($context->scope);
                    $row = $ledger['obligations'][0] ?? null;
                    if ($actual->namespace !== 'incoming-retained' || $actual->workflow_class !== TestIncomingPaymentRetentionWorkflow::class
                        || $ledger['original_run_id'] !== $actual->id || count(
                            $ledger['obligations']
                        ) !== 1 || $row === null
                        || $row['id'] !== $context->operationId || $row['fingerprint'] !== $this->obligation()->fingerprint()
                        || Obligation::fromArray($row['descriptor'])->fingerprint() !== $this->obligation()
                            ->fingerprint()) {
                        throw new LogicException('The original retained incoming duty is unavailable.');
                    }
                }),
            ],
        ]));
    }

    private function start(bool $execute = true): WorkflowStub
    {
        $origin = WorkflowStub::make(
            TestIncomingPaymentRetentionWorkflow::class,
            'incoming-origin',
            'incoming-retained'
        );
        $origin->start($this->scope()->toArray(), $this->obligation()->input, $execute);
        $this->originalRun = $origin->runId();
        $this->drain();
        return $origin;
    }

    private function recover(string $key): void
    {
        app(ScopeRecovery::class)->request($this->scope(), $key, 'incoming-worker', 'original-bank-cancellation');
        $this->drain();
    }

    private function drain(): void
    {
        for ($i = 0; $i < 150; ++$i) {
            $task = WorkflowTask::query()->whereRelation('run', 'namespace', 'incoming-retained')
                ->where('status', TaskStatus::Ready)->orderBy('available_at')->orderBy('created_at')->orderBy(
                    'id'
                )->first();
            if ($task === null) {
                return;
            }
            if ($task->available_at !== null && $task->available_at->isFuture()) {
                Carbon::setTestNow($task->available_at->copy()->addSecond());
            }
            $job = match ($task->task_type) {
                TaskType::Workflow => new RunWorkflowTask($task->id),
                TaskType::Activity => new RunActivityTask($task->id),
                TaskType::Timer => new RunTimerTask($task->id),
            };
            $this->app->call([$job, 'handle']);
        }
        $this->fail('The bounded incoming retained tasks did not settle.');
    }

    private function obligation(): Obligation
    {
        return new Obligation('incoming.watch', 'test.incoming.retained', '1', [
            'original' => $this->original()
                ->toArray(),
            'admissionContract' => $this->contract,
        ]);
    }

    private function scope(): ScopeIdentity
    {
        return new ScopeIdentity('shop-a', 'incoming-retained', 'purchase-1.payment', 'g1');
    }

    private function original(): IncomingPaymentDescriptor
    {
        return new IncomingPaymentDescriptor(new Identity(
            'shop-a',
            'purchase-1',
            'g1',
            'incoming'
        ), 'original-bank-reference', 1000, 'USD', 1100);
    }

    private function receive(string $transfer, int $amount): IncomingPaymentReceipt
    {
        $receipt = new IncomingPaymentReceipt(
            $this->original()
                ->identity->key(),
            $transfer,
            $amount,
            'USD',
            $this->providerTime
        );
        $this->provider->incomingPayments()
            ->record($this->original(), $receipt);
        return $receipt;
    }

    private function refund(IncomingPaymentReceipt $receipt): Request
    {
        return IncomingPaymentOperation::refundRequest($this->scope(), 'incoming.watch', $this->original(), $receipt);
    }

    private function effectAmounts(): array
    {
        return array_map('intval', $this->providerDb->query("SELECT json_extract(o.request, '$.terms.amountMinor')
            FROM provider_effects e JOIN provider_operations o ON o.id = e.operation_id ORDER BY e.sequence")->fetchAll(
            PDO::FETCH_COLUMN
        ));
    }

    private function history(): array
    {
        return WorkflowHistoryEvent::query()->where('workflow_run_id', $this->originalRun)->orderBy('sequence')->get()
            ->map(
                static fn (WorkflowHistoryEvent $event): array => [
                    $event->event_type->value,
                    PortableDescriptor::encode($event->payload),
                ]
            )->all();
    }
}
