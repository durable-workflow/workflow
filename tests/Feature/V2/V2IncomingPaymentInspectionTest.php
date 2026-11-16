<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Identity;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\IncomingPaymentDescriptor;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\IncomingPaymentJournal;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\IncomingPaymentReceipt;
use DurableWorkflow\Examples\ProviderStateMachines\IncomingPaymentInspectionStore;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use LogicException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\V2\TestIncomingPaymentInspectionWorkflow;
use Tests\TestCase;
use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Compensation\Definition\PortableDescriptor;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\Execution\ForwardResult;
use Workflow\V2\Compensation\Execution\HandlerRegistry;
use Workflow\V2\Compensation\Execution\OperationContext;
use Workflow\V2\Compensation\Execution\Resolution;
use Workflow\V2\Compensation\Execution\ScopedOperation;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
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

require_once __DIR__ . '/../../../examples/provider-state-machines/bootstrap.php';

/** Recorded observations only; common-route fulfillment and original refunds remain separate requirements. */
final class V2IncomingPaymentInspectionTest extends TestCase
{
    private PDO $bankDb;

    private IncomingPaymentJournal $bank;

    private string $bankPath;

    private ?string $originalRun = null;

    private int $bankTime = 1000;

    private bool $allowed = true;

    private int $authorizations = 0;

    protected function setUp(): void
    {
        parent::setUp();
        self::stopWorkers();
        Queue::fake();
        Carbon::setTestNow('2026-01-01T00:00:00Z');
        config([
            'workflows.v2.namespaces' => ['incoming-tests'],
            'workflows.v2.task_dispatch_mode' => 'queue',
        ]);
        $schema = require __DIR__ . '/../../../examples/provider-state-machines/schema.php';
        $schema(DB::connection()->getSchemaBuilder());
        $this->bankPath = tempnam(sys_get_temp_dir(), 'native-incoming-bank-');
        $this->bankDb = new PDO('sqlite:' . $this->bankPath);
        $this->bank = new IncomingPaymentJournal($this->bankDb, 'shop-a', fn (): int => $this->bankTime);
        $this->bank->install();
        $this->bank->register($this->descriptor());
        $scope = $this->scope();
        $this->app->instance(ScopeAuthorization::class, new class($scope) implements ScopeAuthorization {
            public function __construct(
                private readonly ScopeIdentity $expected
            ) {
            }

            public function assertAllowed(ScopeIdentity $scope, string $principal, string $operation): string
            {
                if ($scope->toArray() !== $this->expected->toArray() || $principal !== 'incoming-worker') {
                    throw new LogicException('The original incoming scope or principal is unavailable.');
                }
                return 'incoming-host-v1';
            }
        });
        $this->app->instance(HandlerRegistry::class, new HandlerRegistry([
            'test.incoming.watch' => [
                '1' => new class() implements ScopedOperation {
                    public function assertInput(array $input): void
                    {
                        IncomingPaymentDescriptor::fromArray($input);
                    }

                    public function execute(OperationContext $context): ForwardResult
                    {
                        throw new LogicException('An incoming observation must not manufacture a payment command.');
                    }

                    public function compensate(OperationContext $context): Resolution
                    {
                        return Resolution::unknown('incoming-reference-and-refunds-not-settled');
                    }
                },
            ],
        ]));
        $this->configureInspection();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->app->forgetInstance(IncomingPaymentInspectionStore::class);
        $this->app->forgetInstance(HandlerRegistry::class);
        foreach (['assignments', 'actors', 'assessments', 'admissions', 'milestones', 'cancellations'] as $name) {
            DB::connection()->getSchemaBuilder()->dropIfExists('provider_machine_' . $name);
        }
        unset($this->bank, $this->bankDb);
        if (isset($this->bankPath)) {
            unlink($this->bankPath);
        }
        parent::tearDown();
    }

    public function testRecordedPartialPaymentDoesNotBecomeFullSettlementOrCardCapture(): void
    {
        $origin = $this->startInspection();
        $first = $this->snapshots()[0];
        $this->assertSame(0, $first['incoming']['receivedAmountMinor']);
        $this->receive('transfer-one', 400, 1000);
        $origin->signal('incoming-observed');
        $this->drain();
        $this->assertSame('completed', $origin->refresh()->status());
        $snapshots = $this->snapshots();
        $this->assertCount(2, $snapshots);
        $after = $snapshots[1];
        $this->assertSame(400, $after['incoming']['receivedAmountMinor']);
        $this->assertSame(600, $after['incoming']['remainingAmountMinor']);
        $this->assertFalse($after['incoming']['fullAmountReceived']);
        $this->assertFalse($after['incoming']['nonExecutionEstablished']);
        $this->assertSame($origin->runId(), $after['originalRunId']);
        $this->assertSame($this->descriptor()->fingerprint(), IncomingPaymentDescriptor::fromArray(
            $after['incoming']['original']
        )->fingerprint());
        $ledger = (new ScopeRepository())->inspect($this->scope());
        $this->assertSame($origin->runId(), $ledger['original_run_id']);
        $this->assertSame('open', $ledger['status']);
        $this->assertCount(1, $ledger['obligations']);
        $this->assertSame('registered', $ledger['obligations'][0]['forward_status']);
        $this->assertSame(
            1,
            (int) $this->bankDb->query('SELECT COUNT(*) FROM incoming_payment_receipts')
                ->fetchColumn()
        );
    }

    public function testLateOriginalTransferRemainsVisibleWithoutRestartingItsDeadline(): void
    {
        $origin = $this->startInspection();
        $this->receive('on-time', 400, 1000);
        $this->bankTime = 1200;
        $this->receive('late-original', 600, 1200);
        $origin->signal('incoming-observed');
        $this->drain();
        $snapshot = $this->snapshots()[1]['incoming'];
        $this->assertSame(1100, $snapshot['original']['deadline']);
        $this->assertSame(1200, $snapshot['observedAt']);
        $this->assertTrue($snapshot['deadlinePassed']);
        $this->assertSame(1000, $snapshot['receivedAmountMinor']);
        $this->assertSame(400, $snapshot['receivedBeforeDeadlineMinor']);
        $this->assertTrue($snapshot['fullAmountReceived']);
        $this->assertFalse($snapshot['requiredAmountReceivedBeforeDeadline']);
        $this->assertFalse($snapshot['nonExecutionEstablished']);
        $this->assertSame(['on-time', 'late-original'], array_column($snapshot['receipts'], 'transfer'));
    }

    public function testReplacementReusesItsRecordedObservationAndANewActivityReadsNewFacts(): void
    {
        $origin = $this->startInspection();
        $first = $this->snapshots()[0];
        $this->receive('transfer-one', 1000, 1000);
        $this->configureInspection();
        $this->assertSame($first, $this->inspectAgain($origin, $first['activityId']));
        $this->assertSame(1, DB::table('provider_machine_assessments')->count());
        $next = $this->inspectAgain($origin, 'replacement-fresh-observation');
        $this->assertSame(1000, $next['incoming']['receivedAmountMinor']);
        $this->assertSame(0, $first['incoming']['receivedAmountMinor']);
        $this->assertSame($next, $this->inspectAgain($origin, 'replacement-fresh-observation'));
        $this->assertSame(2, DB::table('provider_machine_assessments')->count());
    }

    public function testNativeReplayDoesNotReadTheProviderOrRepeatIncomingEffects(): void
    {
        $origin = $this->startInspection();
        $this->receive('transfer-one', 1000, 1000);
        $origin->signal('incoming-observed');
        $this->drain();
        $history = $this->history($origin->runId());
        $before = $this->authorizations;
        $this->allowed = false;
        app(WorkflowReplayer::class)->replay(WorkflowRun::query()->findOrFail($origin->runId()));
        $this->assertSame($before, $this->authorizations);
        $this->assertSame($history, $this->history($origin->runId()));
        $this->assertCount(2, $this->snapshots());
        $this->assertSame(
            1,
            (int) $this->bankDb->query('SELECT COUNT(*) FROM incoming_payment_receipts')
                ->fetchColumn()
        );
    }

    public function testCachedReadRequiresTheCurrentGrantBeforeProtectedSql(): void
    {
        $origin = $this->startInspection();
        $first = $this->snapshots()[0];
        $this->allowed = false;
        $queries = 0;
        $actual = WorkflowRun::query()->findOrFail($origin->runId());
        DB::connection()->listen(static function () use (&$queries): void {
            ++$queries;
        });
        try {
            app(IncomingPaymentInspectionStore::class)->inspect(
                $actual,
                $first['activityId'],
                $this->scope()
                    ->toArray(),
                $this->descriptor()
                    ->toArray()
            );
            $this->fail('Recorded facts must not bypass current host authorization.');
        } catch (LogicException $error) {
            $this->assertSame('The original incoming inspection grant is unavailable.', $error->getMessage());
        }
        $this->assertSame(0, $queries);
    }

    public function testCallerTransactionIsRefusedBeforeHostAuthorization(): void
    {
        $origin = $this->startInspection();
        $before = $this->authorizations;
        DB::connection()->beginTransaction();
        try {
            $this->inspectAgain($origin, 'nested-observation');
            $this->fail('Incoming observation must not inherit the caller transaction.');
        } catch (LogicException $error) {
            $this->assertSame(
                'Incoming inspection requires its own application transaction boundary.',
                $error->getMessage()
            );
        } finally {
            DB::connection()->rollBack();
        }
        $this->assertSame($before, $this->authorizations);
    }

    #[DataProvider('foreignTargets')]
    public function testAnotherOriginalTargetCannotReadTheCachedObservation(string $target): void
    {
        $origin = $this->startInspection();
        $scope = $this->scope()
            ->toArray();
        $descriptor = $this->descriptor()
            ->toArray();
        if ($target === 'scope-generation') {
            $scope['generation'] = 'other-generation';
        } elseif ($target === 'scope-owner') {
            $scope['key'] = 'other-purchase.payment';
        } elseif ($target === 'currency') {
            $descriptor['currency'] = 'EUR';
        } else {
            $descriptor['identity'][$target] = 'other-original';
        }
        $this->bankDb->exec('DROP TABLE incoming_payment_receipts');
        try {
            app(IncomingPaymentInspectionStore::class)->inspect(
                WorkflowRun::query()->findOrFail($origin->runId()),
                $this->snapshots()[0]['activityId'],
                $scope,
                $descriptor
            );
            $this->fail('A cached incoming fact cannot be rebound to another original target.');
        } catch (LogicException $error) {
            $this->assertSame('The original incoming inspection grant is unavailable.', $error->getMessage());
        }
    }

    public static function foreignTargets(): iterable
    {
        foreach (['scope-generation', 'scope-owner', 'client', 'subject', 'generation', 'currency'] as $target) {
            yield $target => [$target];
        }
    }

    public function testARehashedForeignOwnerSnapshotIsStillRefused(): void
    {
        $origin = $this->startInspection();
        $snapshot = $this->snapshots()[0];
        $snapshot['originalRunId'] = 'foreign-native-run';
        $payload = PortableDescriptor::encode($snapshot);
        DB::table('provider_machine_assessments')->where('id', $snapshot['snapshotId'])->update([
            'payload' => $payload,
            'payload_sha256' => hash('sha256', $payload),
        ]);
        $this->bankDb->exec('DROP TABLE incoming_payment_receipts');
        try {
            $this->inspectAgain($origin, $snapshot['activityId']);
            $this->fail('A valid payload hash cannot authorize another original owner.');
        } catch (LogicException $error) {
            $this->assertSame(
                'The recorded incoming snapshot substituted its original business or native owner.',
                $error->getMessage()
            );
        }
    }

    public function testCachedFactsCannotReplaceAMissingRetainedMoneyDuty(): void
    {
        $origin = $this->startInspection();
        $snapshot = $this->snapshots()[0];
        DB::table('workflow_compensation_obligations')->where('scope_id', $this->scope()->id())->delete();
        $this->bankDb->exec('DROP TABLE incoming_payment_receipts');
        try {
            $this->inspectAgain($origin, $snapshot['activityId']);
            $this->fail('The original retained money duty must still exist.');
        } catch (LogicException $error) {
            $this->assertSame('The original incoming money duty is unavailable.', $error->getMessage());
        }
    }

    #[DataProvider('substitutedIncomingFacts')]
    public function testRehashingCannotSubstituteIncomingReceiptsOrTheirTotals(string $target): void
    {
        $origin = $this->startInspection();
        $this->receive('transfer-one', 1000, 1000);
        $origin->signal('incoming-observed');
        $this->drain();
        $snapshot = $this->snapshots()[1];
        if ($target === 'receipt') {
            $snapshot['incoming']['receipts'][0]['receivable'] = 'another-receivable';
        } else {
            $snapshot['incoming']['remainingAmountMinor'] = 1000;
        }
        $payload = PortableDescriptor::encode($snapshot);
        DB::table('provider_machine_assessments')->where('id', $snapshot['snapshotId'])->update([
            'payload' => $payload,
            'payload_sha256' => hash('sha256', $payload),
        ]);
        $this->bankDb->exec('DROP TABLE incoming_payment_receipts');
        try {
            $this->inspectAgain($origin, $snapshot['activityId']);
            $this->fail('Rehashing a snapshot cannot authorize foreign money or contradictory totals.');
        } catch (LogicException $error) {
            $this->assertSame($target === 'receipt'
                ? 'The recorded incoming snapshot substituted its original receipt evidence.'
                : 'The recorded incoming totals or deadline contradict their receipt evidence.', $error->getMessage());
        }
    }

    public static function substitutedIncomingFacts(): iterable
    {
        yield 'another original receivable' => ['receipt'];
        yield 'contradictory remaining balance' => ['total'];
    }

    private function configureInspection(): void
    {
        $this->app->instance(IncomingPaymentInspectionStore::class, new IncomingPaymentInspectionStore(
            DB::connection(),
            $this->bank,
            function (WorkflowRun $actual, ScopeIdentity $scope, IncomingPaymentDescriptor $descriptor): void {
                ++$this->authorizations;
                if (! $this->allowed || $actual->id !== $this->originalRun
                    || $actual->workflow_class !== TestIncomingPaymentInspectionWorkflow::class
                    || $scope->toArray() !== $this->scope()
                        ->toArray()
                    || $descriptor->fingerprint() !== $this->descriptor()
                        ->fingerprint()) {
                    throw new LogicException('The original incoming inspection grant is unavailable.');
                }
                $ledger = (new ScopeRepository())->inspect($scope);
                $obligation = new Obligation('incoming.watch', 'test.incoming.watch', '1', $descriptor->toArray());
                if ($ledger['original_run_id'] !== $actual->id || count($ledger['obligations']) !== 1
                    || $ledger['obligations'][0]['fingerprint'] !== $obligation->fingerprint()
                    || Obligation::fromArray($ledger['obligations'][0]['descriptor'])->fingerprint()
                        !== $obligation->fingerprint()) {
                    throw new LogicException('The original incoming money duty is unavailable.');
                }
            }
        ));
    }

    private function descriptor(): IncomingPaymentDescriptor
    {
        return new IncomingPaymentDescriptor(
            new Identity('shop-a', 'purchase-1', 'g1', 'bank-receivable'),
            'original-bank-reference',
            1000,
            'USD',
            1100
        );
    }

    private function scope(): ScopeIdentity
    {
        return new ScopeIdentity('shop-a', 'incoming-tests', 'purchase-1.payment', 'g1');
    }

    private function receive(string $id, int $amount, int $time): void
    {
        $this->bank->record($this->descriptor(), new IncomingPaymentReceipt(
            $this->descriptor()
                ->identity->key(),
            $id,
            $amount,
            'USD',
            $time
        ));
    }

    private function startInspection(): WorkflowStub
    {
        $origin = WorkflowStub::make(TestIncomingPaymentInspectionWorkflow::class, 'incoming-origin', 'incoming-tests');
        $origin->start($this->scope()->toArray(), $this->descriptor()->toArray());
        $this->originalRun = $origin->runId();
        $this->drain();
        $this->assertCount(1, $this->snapshots());
        return $origin;
    }

    private function inspectAgain(WorkflowStub $origin, string $activityId): array
    {
        return app(IncomingPaymentInspectionStore::class)->inspect(
            WorkflowRun::query()->findOrFail($origin->runId()),
            $activityId,
            $this->scope()
                ->toArray(),
            $this->descriptor()
                ->toArray()
        );
    }

    private function snapshots(): array
    {
        return DB::table('provider_machine_assessments')->orderBy('id')->get()->map(
            static fn (object $row): array => json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR)
        )->sortBy(static fn (array $snapshot): int => $snapshot['incoming']['receivedAmountMinor'])->values()->all();
    }

    private function history(string $run): array
    {
        return WorkflowHistoryEvent::query()->where('workflow_run_id', $run)->orderBy('sequence')->get()->map(
            static fn (WorkflowHistoryEvent $event): array => [$event->event_type->value,
                PortableDescriptor::encode($event->payload)]
        )->all();
    }

    private function drain(): void
    {
        for ($i = 0; $i < 100; ++$i) {
            $task = WorkflowTask::query()->whereRelation('run', 'namespace', 'incoming-tests')
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
        $this->fail('The bounded incoming observation tasks did not settle.');
    }
}
