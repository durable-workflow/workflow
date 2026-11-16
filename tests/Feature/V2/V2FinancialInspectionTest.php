<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\AuthorizationTerms;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\FinancialDescriptor;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Status;
use DurableWorkflow\Examples\ProviderStateMachines\Authority;
use DurableWorkflow\Examples\ProviderStateMachines\FinancialInspectionStore;
use DurableWorkflow\Examples\ProviderStateMachines\MachineStore;
use DurableWorkflow\Examples\ProviderStateMachines\OrderTerms;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\V2\TestFinancialInspectionWorkflow;
use Tests\Support\FinancialScopeFixture;
use Tests\TestCase;
use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\WorkflowReplayer;
use Workflow\V2\WorkflowStub;

require_once __DIR__ . '/../../../examples/provider-state-machines/bootstrap.php';

/** Actual recorded inspection tasks; the common Order/Payment/Shipment trigger composition remains separate. */
final class V2FinancialInspectionTest extends TestCase
{
    use FinancialScopeFixture {
        setUp as private setUpFinancial;
        tearDown as private tearDownFinancial;
    }

    private bool $inspectionAllowed = true;

    private int $authorizationCalls = 0;

    protected function setUp(): void
    {
        $this->setUpFinancial();
        $schema = require __DIR__ . '/../../../examples/provider-state-machines/schema.php';
        $schema(DB::connection()->getSchemaBuilder());
        $this->configureInspection();
    }

    protected function tearDown(): void
    {
        $this->app->forgetInstance(FinancialInspectionStore::class);
        foreach (['assignments', 'actors', 'assessments', 'admissions', 'milestones', 'cancellations'] as $name) {
            DB::connection()->getSchemaBuilder()->dropIfExists('provider_machine_' . $name);
        }
        $this->tearDownFinancial();
    }

    public function testNativeActivityRecordsUnknownThenOriginalAuthorizationWithoutInventingCapture(): void
    {
        $origin = $this->startInspection();
        $this->assertSame('completed', $origin->refresh()->status());
        $snapshots = $this->snapshots();
        $this->assertCount(2, $snapshots);
        $before = $snapshots[0]['financial'];
        $after = $snapshots[1]['financial'];
        $this->assertSame('unknown', $before['observations']['authorization']['status']);
        $this->assertFalse($before['facts']['authorization_confirmed']);
        $this->assertSame('confirmed', $after['observations']['authorization']['status']);
        $this->assertTrue($after['facts']['authorization_active']);
        $this->assertFalse($after['facts']['capture_confirmed']);
        $this->assertSame('unknown', $after['observations']['capture']['status']);
        $this->assertSame(1000, $after['observedAt']);
        $this->assertSame(1001, $after['originalTerms']['expiresAt']);
        $this->assertSame($origin->runId(), $snapshots[1]['originalRunId']);
        $this->assertSame(
            $this->financialScope()
                ->toArray(),
            ScopeIdentity::fromArray($snapshots[1]['scope'])->toArray()
        );
        $this->assertSame(['authorize'], $this->financialEffects());
        $this->assertSame(1, (int) $this->providerDb->query('SELECT COUNT(*) FROM provider_operations')->fetchColumn());
    }

    public function testReplacementAfterLostAcknowledgementReturnsTheFirstPersistedObservation(): void
    {
        $origin = $this->startInspection();
        $snapshot = $this->snapshots()[1];
        $before = $this->providerRequests();
        $this->providerTime = 2000;
        $this->configureInspection();
        $same = $this->inspectAgain($origin, $snapshot['activityId']);
        $this->assertSame($snapshot, $same);
        $this->assertSame($before, $this->providerRequests());
        $current = $this->inspectAgain($origin, 'fresh-original-inspection');
        $this->assertSame($current, $this->inspectAgain($origin, 'fresh-original-inspection'));
        $this->assertTrue($current['financial']['facts']['authorization_expired']);
        $this->assertTrue($current['financial']['facts']['authorization_confirmed']);
        $this->assertFalse($current['financial']['facts']['authorization_active']);
        $this->assertSame(1001, $current['financial']['originalTerms']['expiresAt']);
        $this->assertSame(['authorize'], $this->financialEffects());
    }

    public function testNativeReplayUsesRecordedInspectionResultsWithoutLiveProviderReads(): void
    {
        $origin = $this->startInspection();
        $before = $this->providerRequests();
        $history = $this->financialHistory($origin->runId());
        $this->providerTime = 2000;
        app(WorkflowReplayer::class)->replay(WorkflowRun::query()->findOrFail($origin->runId()));
        $this->assertSame($before, $this->providerRequests());
        $this->assertSame($history, $this->financialHistory($origin->runId()));
        $this->assertCount(2, $this->snapshots());
    }

    public function testCachedInspectionStillRequiresTheCurrentHostGrant(): void
    {
        $origin = $this->startInspection();
        $snapshot = $this->snapshots()[1];
        $before = $this->providerRequests();
        $this->inspectionAllowed = false;
        try {
            $this->inspectAgain($origin, $snapshot['activityId']);
            $this->fail('Cached provider facts must not bypass the host grant.');
        } catch (LogicException $error) {
            $this->assertSame('The original financial inspection grant is unavailable.', $error->getMessage());
        }
        $this->assertSame($before, $this->providerRequests());
    }

    public function testCallerTransactionIsRefusedBeforeTheHostGrantOrProviderRead(): void
    {
        $origin = $this->startInspection();
        $before = $this->providerRequests();
        $calls = $this->authorizationCalls;
        DB::connection()->beginTransaction();
        try {
            $this->inspectAgain($origin, 'nested-inspection');
            $this->fail('Inspection cannot inherit the caller transaction.');
        } catch (LogicException $error) {
            $this->assertSame(
                'Financial inspection requires its own application transaction boundary.',
                $error->getMessage()
            );
        } finally {
            DB::connection()->rollBack();
        }
        $this->assertSame($calls, $this->authorizationCalls);
        $this->assertSame($before, $this->providerRequests());
    }

    public function testAnotherScopeOrDescriptorCannotReadTheOriginalCachedObservation(): void
    {
        $origin = $this->startInspection();
        $activity = $this->snapshots()[1]['activityId'];
        $scope = $this->financialScope()
            ->toArray();
        $descriptor = $this->financialDescriptor();
        $foreignScope = [
            ...$scope,
            'key' => 'another-payment',
        ];
        $foreignDescriptor = $descriptor;
        foreach ($foreignDescriptor as &$request) {
            $request['terms']['amountMinor'] = 999;
        }
        unset($request);
        $before = $this->providerRequests();
        foreach ([[$foreignScope, $descriptor],
            [$scope, $foreignDescriptor],
        ] as [$selectedScope, $selectedDescriptor]) {
            try {
                app(FinancialInspectionStore::class)->inspect(
                    WorkflowRun::query()->findOrFail($origin->runId()),
                    $activity,
                    $selectedScope,
                    $selectedDescriptor
                );
                $this->fail('An original inspection cannot be read through another owner or money descriptor.');
            } catch (LogicException $error) {
                $this->assertSame('The original financial inspection grant is unavailable.', $error->getMessage());
            }
        }
        $this->assertSame($before, $this->providerRequests());
    }

    public static function corruptedInspection(): array
    {
        return [['actor_id', 'another-owner'], ['request_sha256', 'changed-request'], ['payload', '{}']];
    }

    #[DataProvider('corruptedInspection')]
    public function testCorruptStoredObservationDoesNotFallBackToLiveProviderState(string $field, string $value): void
    {
        $origin = $this->startInspection();
        $snapshot = $this->snapshots()[1];
        DB::table('provider_machine_assessments')->where('id', $snapshot['snapshotId'])->update([
            $field => $value,
        ]);
        $before = $this->providerRequests();
        try {
            $this->inspectAgain($origin, $snapshot['activityId']);
            $this->fail('A corrupt observation cannot be replaced with a new provider read.');
        } catch (LogicException $error) {
            $this->assertSame(
                'The original financial inspection identity or recorded contents changed.',
                $error->getMessage()
            );
        }
        $this->assertSame($before, $this->providerRequests());
    }

    public function testMissingOriginalPairRefusesBeforeAnyProviderInspectionOrEffect(): void
    {
        $origin = $this->startInspection(paired: false);
        $this->assertSame('failed', $origin->refresh()->status());
        $this->assertSame(0, $this->providerRequests());
        $this->assertSame([], $this->financialEffects());
        $this->assertSame(0, DB::table('provider_machine_assessments')->count());
        $this->assertCount(1, (new ScopeRepository())->inspect($this->financialScope())['obligations']);
    }

    public function testAuthorizationTermsCannotBeSeededIntoAnImmediateCaptureRecipe(): void
    {
        $terms = new OrderTerms(
            'shop-a',
            'purchase-1',
            'g1',
            1375,
            'USD',
            'sku-1',
            1,
            new AuthorizationTerms(1375, 'USD', 1001)
        );
        $store = new MachineStore(new Authority(DB::connection(), 'financial-worker', [$terms->subjectKey()]));
        try {
            $store->seed($terms);
            $this->fail('An authorization assignment cannot silently select the legacy immediate capture path.');
        } catch (LogicException $error) {
            $this->assertSame(
                'The selected native recipe does not implement authorization and capture.',
                $error->getMessage()
            );
        }
        $this->assertSame(0, DB::table('provider_machine_assignments')->count());
        $this->assertSame(0, $this->providerRequests());
    }

    private function configureInspection(): void
    {
        $this->app->instance(FinancialInspectionStore::class, new FinancialInspectionStore(
            DB::connection(),
            $this->provider,
            function (WorkflowRun $actual, ScopeIdentity $scope, FinancialDescriptor $descriptor): void {
                ++$this->authorizationCalls;
                if (! $this->inspectionAllowed || $actual->id !== $this->originalRun
                    || $actual->workflow_class !== TestFinancialInspectionWorkflow::class
                    || $scope->toArray() !== $this->financialScope()
                        ->toArray()
                    || $descriptor->toArray() !== FinancialDescriptor::fromArray(
                        $this->financialDescriptor()
                    )->toArray()) {
                    throw new LogicException('The original financial inspection grant is unavailable.');
                }
                $ledger = (new ScopeRepository())->inspect($scope);
                if ($ledger['original_run_id'] !== $actual->id || count($ledger['obligations']) !== 2) {
                    throw new LogicException('The original paired financial obligations are unavailable.');
                }
                foreach (['authorize', 'capture'] as $position => $key) {
                    $row = $ledger['obligations'][$position];
                    $expected = $this->financialObligation($key)
                        ->fingerprint();
                    if ($row['fingerprint'] !== $expected || (int) $row['position'] !== $position + 1
                        || Obligation::fromArray($row['descriptor'])->fingerprint() !== $expected) {
                        throw new LogicException('The original financial pair or its ordering changed.');
                    }
                }
            }
        ));
    }

    private function startInspection(bool $paired = true): WorkflowStub
    {
        $this->provider->plan($this->financialRequest('authorize'), Status::Confirmed);
        $origin = WorkflowStub::make(TestFinancialInspectionWorkflow::class, 'inspection-origin', 'financial-tests');
        $origin->start($this->financialScope()->toArray(), $this->financialDescriptor(), $paired);
        $this->originalRun = $origin->runId();
        $this->drainFinancialTasks();
        return $origin;
    }

    private function inspectAgain(WorkflowStub $origin, string $activityId): array
    {
        return app(FinancialInspectionStore::class)->inspect(
            WorkflowRun::query()->findOrFail($origin->runId()),
            $activityId,
            $this->financialScope()
                ->toArray(),
            $this->financialDescriptor()
        );
    }

    private function snapshots(): array
    {
        $values = DB::table('provider_machine_assessments')->get()->map(
            static fn (object $row): array => json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR)
        )->all();
        usort($values, static fn (array $a, array $b): int =>
            (int) $a['financial']['facts']['authorization_confirmed'] <=>
            (int) $b['financial']['facts']['authorization_confirmed']);
        return $values;
    }

    private function providerRequests(): int
    {
        return (int) $this->providerDb->query('SELECT COUNT(*) FROM provider_requests')
            ->fetchColumn();
    }
}
