<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Facades\Cache;
use LogicException;
use Tests\Fixtures\V2\TestRetainedScopeWorkflow;
use Tests\Fixtures\V2\TestScopedOperation;
use Tests\TestCase;
use Workflow\V2\Compensation\Admission\RootAuthorization;
use Workflow\V2\Compensation\Admission\RootBinding;
use Workflow\V2\Compensation\Admission\RootIdentity;
use Workflow\V2\Compensation\Admission\RootReservationStore;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\Execution\HandlerRegistry;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\Compensation\Recovery\ScopeRecovery;
use Workflow\V2\Compensation\Security\ScopeAuthorization;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\WorkflowStub;

final class V2RootScopeWorkflowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::stopWorkers();
        config([
            'workflows.v2.namespaces' => ['client-a'],
            'queue.default' => 'database',
            'queue.connections.database.queue' => 'root-scope-test',
            'workflows.v2.connection' => 'database',
            'workflows.v2.queue' => 'root-scope-test',
        ]);
        $this->app->instance(HandlerRegistry::class, new HandlerRegistry([
            'test.operation' => [
                '1' => new TestScopedOperation(),
            ],
        ]));
        $this->app->instance(ScopeAuthorization::class, new class() implements ScopeAuthorization {
            public function assertAllowed(ScopeIdentity $scope, string $principal, string $operation): string
            {
                if ($scope->application !== 'root-example' || $scope->namespace !== 'client-a'
                    || $principal !== 'operator-a') {
                    throw new LogicException('Root workflow scope access refused.');
                }
                return 'scope-policy-v1';
            }
        });
    }

    public function testRecordedScopeActivitiesAdoptTheAtomicallyOpenedOriginalRoot(): void
    {
        [$scope, $reservation, $binding] = $this->start();
        $this->assertInstanceOf(RootBinding::class, $binding);
        $this->assertSame('completed', WorkflowRun::query()->findOrFail($reservation->originalRunId)->status->value);
        $ledger = (new ScopeRepository())->inspect($scope);
        $this->assertSame('open', $ledger['status']);
        $this->assertSame($reservation->originalRunId, $ledger['original_run_id']);
        $this->assertCount(2, $ledger['obligations']);
        $this->assertNotSame($scope->generation, $reservation->originalRunId);
        $history = WorkflowHistoryEvent::query()->where('workflow_run_id', $reservation->originalRunId)
            ->orderBy('sequence')
            ->get()
            ->toArray();
        $result = app(ScopeRecovery::class)->request($scope, 'cancel', 'operator-a', 'cancel-order');
        $this->assertSame('recovery', $result['route']);
        $this->drain($result['runId']);
        $this->assertSame('verified', (new ScopeRepository())->inspect($scope)['status']);
        $this->assertSame(['second', 'first'], Cache::get('test:scope:undo-log'));
        $this->assertSame($history, WorkflowHistoryEvent::query()->where('workflow_run_id', $reservation->originalRunId)
            ->orderBy('sequence')
            ->get()
            ->toArray());
        $this->assertSame($binding->toArray(), $this->store()->close($reservation, 'operator-a')->toArray());
    }

    public function testLostForwardAcknowledgementUsesTheGenuineOriginalScopeForCompensation(): void
    {
        [$scope, $reservation, $binding] = $this->start('lost-ack');
        $this->assertInstanceOf(RootBinding::class, $binding);
        $this->assertSame('completed', WorkflowRun::query()->findOrFail($reservation->originalRunId)->status->value);
        $this->assertSame('verified', (new ScopeRepository())->inspect($scope)['status']);
        $this->assertSame(['first'], Cache::get('test:scope:undo-log'));
        $this->assertSame(1, (int) Cache::get('test:scope:effect-count'));
        $this->assertSame($binding->toArray(), $this->store()->close($reservation, 'operator-a')->toArray());
    }

    public function testTechnicalClosureBeforeFirstRegistrationRetainsAGenuineRecoverableScope(): void
    {
        [$scope, $reservation, $binding] = $this->start(drain: false);
        $origin = WorkflowStub::loadRun($reservation->originalRunId);
        $this->assertSame('pending', $origin->status());
        $this->assertCount(0, (new ScopeRepository())->inspect($scope)['obligations']);
        $origin->terminate('Technical closure before first registration.');
        $history = WorkflowHistoryEvent::query()->where('workflow_run_id', $reservation->originalRunId)
            ->orderBy('sequence')
            ->get()
            ->toArray();
        $result = app(ScopeRecovery::class)->request($scope, 'cancel-before-register', 'operator-a', 'cancel-order');
        for ($step = 0; $step < 20; $step++) {
            if ((new ScopeRepository())->inspect($scope)['status'] === 'verified'
                && WorkflowRun::query()->findOrFail($result['runId'])->status->value === 'completed') {
                break;
            }
            $this->artisan('queue:work', [
                '--once' => true,
                '--queue' => 'root-scope-test',
                '--sleep' => 0,
                '--tries' => 1,
            ])->assertExitCode(0)
                ->run();
        }
        $this->assertSame('verified', (new ScopeRepository())->inspect($scope)['status']);
        $this->assertSame('completed', WorkflowRun::query()->findOrFail($result['runId'])->status->value);
        $this->assertSame('terminated', $origin->refresh()->status());
        $this->assertSame($history, WorkflowHistoryEvent::query()->where('workflow_run_id', $reservation->originalRunId)
            ->orderBy('sequence')
            ->get()
            ->toArray());
        $this->assertNull(Cache::get('test:scope:effect-count'));
        $this->assertSame($binding->toArray(), $this->store()->close($reservation, 'operator-a')->toArray());
    }

    public function testFreshApplicationClosesTheOriginalReservationAfterItsAcknowledgementIsLost(): void
    {
        $scope = new ScopeIdentity('root-example', 'client-a', 'order-1', 'original-business-generation');
        $arguments = [$scope->toArray(), 'normal'];
        $identity = RootIdentity::forWorkflow($scope, TestRetainedScopeWorkflow::class, $arguments);
        $this->store()
            ->reserve($identity, 'operator-a');
        $result = $this->coldClose($identity);
        $this->assertSame('closed', $result['kind']);
        $loaded = $this->store()
            ->load($identity, 'operator-a');
        $this->assertSame($result['originalRunId'], $loaded->originalRunId);
        $this->assertSame($result['receipt'], $this->store()->start($loaded, 'operator-a', $arguments)->closureId);
        $this->assertSame(0, WorkflowRun::query()->count());
    }

    public function testFreshApplicationKeepsTheOriginalAdmissionAfterTechnicalClosure(): void
    {
        [$scope, $reservation, $binding] = $this->start(drain: false);
        WorkflowStub::loadRun($reservation->originalRunId)->terminate('Closed before the first registration.');
        $result = $this->coldClose($reservation->identity);
        $this->assertSame('admitted', $result['kind']);
        $this->assertSame($binding->toArray(), $result['receipt']);
        $this->assertSame($reservation->originalRunId, (new ScopeRepository())->metadata($scope)['original_run_id']);
        $this->assertSame(1, WorkflowRun::query()->count());
    }

    private function coldClose(RootIdentity $identity): array
    {
        $input = tempnam(sys_get_temp_dir(), 'root-reservation-input-');
        $report = tempnam(sys_get_temp_dir(), 'root-reservation-report-');
        chmod($input, 0600);
        chmod($report, 0600);
        try {
            file_put_contents($input, json_encode([
                'identity' => $identity->toArray(),
                'database' => \Illuminate\Support\Facades\DB::connection()->getConfig(),
                'report' => $report,
            ], JSON_THROW_ON_ERROR));
            $process = new \Symfony\Component\Process\Process([
                PHP_BINARY, 'vendor/bin/phpunit', 'tests/Support/RootReservationRecoveryProcessTest.php',
            ], dirname(__DIR__, 3), [
                'DWF_ROOT_RESERVATION_INPUT' => $input,
            ], timeout: 30);
            $process->mustRun();
            $result = json_decode(file_get_contents($report), true, flags: JSON_THROW_ON_ERROR);
            $this->assertNotSame(getmypid(), $result['pid']);
            return $result;
        } finally {
            unlink($input);
            unlink($report);
        }
    }

    private function start(string $mode = 'normal', bool $drain = true): array
    {
        $scope = new ScopeIdentity('root-example', 'client-a', 'order-1', 'original-business-generation');
        $arguments = [$scope->toArray(), $mode];
        $identity = RootIdentity::forWorkflow($scope, TestRetainedScopeWorkflow::class, $arguments);
        $reservation = $this->store()
            ->reserve($identity, 'operator-a');
        $binding = $this->store()
            ->start($reservation, 'operator-a', $arguments);
        if ($drain) {
            $this->drain($reservation->originalRunId);
        }
        return [$scope, $reservation, $binding];
    }

    private function drain(string $runId): void
    {
        for ($step = 0; $step < 20; $step++) {
            if (WorkflowRun::query()->findOrFail($runId)->status->isTerminal()) {
                break;
            }
            $this->artisan('queue:work', [
                '--once' => true,
                '--queue' => 'root-scope-test',
                '--sleep' => 0,
                '--tries' => 1,
            ])->assertExitCode(0)
                ->run();
        }
        $this->assertSame('completed', WorkflowRun::query()->findOrFail($runId)->status->value);
    }

    private function store(): RootReservationStore
    {
        return new RootReservationStore(new class() implements RootAuthorization {
            public function assertAllowed(RootIdentity $identity, string $principal, string $operation): string
            {
                if ($principal !== 'operator-a' || $identity->scope->application !== 'root-example'
                    || $identity->scope->namespace !== 'client-a' || $identity->scope->key !== 'order-1'
                    || $identity->scope->generation !== 'original-business-generation'
                    || $identity->workflowClass !== TestRetainedScopeWorkflow::class) {
                    throw new LogicException('Original root relationship refused.');
                }
                return 'root-workflow-policy-v1';
            }
        }, app(ScopeAuthorization::class));
    }
}
