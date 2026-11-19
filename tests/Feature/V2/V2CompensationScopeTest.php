<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\V2\TestPrePatchForwardWorkflow;
use Tests\Fixtures\V2\TestRetainedScopeWorkflow;
use Tests\Fixtures\V2\TestScopedOperation;
use Tests\TestCase;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\Execution\HandlerRegistry;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\Compensation\Recovery\RecoveryWorkflow;
use Workflow\V2\Compensation\Recovery\ScopeRecovery;
use Workflow\V2\Compensation\Recovery\ScopeRetention;
use Workflow\V2\Compensation\Security\ScopeAuthorization;
use Workflow\V2\Compensation\Security\ScopeAuthorizationRefused;
use Workflow\V2\Contracts\WorkflowControlPlane;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\StartOptions;
use Workflow\V2\Support\ConfiguredV2Models;
use Workflow\V2\Support\WorkflowFiberContext;
use Workflow\V2\Support\WorkflowReplayer;
use Workflow\V2\Support\WorkflowRunRetentionCleanup;
use Workflow\V2\WorkflowStub;

final class V2CompensationScopeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'workflows.v2.namespaces' => ['client-a'],
            'queue.default' => 'sync',
            'queue.connections.sync.driver' => 'sync',
        ]);
        $this->app->instance(HandlerRegistry::class, new HandlerRegistry([
            'test.operation' => [
                '1' => new TestScopedOperation(),
            ],
        ]));
        $this->app->instance(ScopeAuthorization::class, new class() implements ScopeAuthorization {
            public function assertAllowed(ScopeIdentity $scope, string $principal, string $operation): string
            {
                if ($scope->namespace !== 'client-a' || $principal !== 'operator-a') {
                    throw new \LogicException('Scope access refused.');
                }
                return 'test-policy-1';
            }
        });
    }

    protected function tearDown(): void
    {
        \Illuminate\Support\Carbon::setTestNow();
        parent::tearDown();
    }

    public function testClosedOriginUsesCanonicalRecoveryAndKeepsOriginalTerminalHistory(): void
    {
        [$scope, $origin] = $this->completedOrigin();
        $before = WorkflowHistoryEvent::query()->where('workflow_run_id', $origin->runId())->count();
        $result = app(ScopeRecovery::class)->request($scope, 'cancel-1', 'operator-a', 'order-cancelled');
        $this->assertSame('verified', (new ScopeRepository())->inspect($scope)['status']);
        $this->assertSame('completed', WorkflowRun::query()->findOrFail($result['runId'])->status->value);
        $this->assertSame('completed', $origin->refresh()->status());
        $this->assertSame($before, WorkflowHistoryEvent::query()->where('workflow_run_id', $origin->runId())->count());
        $this->assertSame(['second', 'first'], Cache::get('test:scope:undo-log'));
        foreach ([
            $origin->runId() => 'dw.compensation.scope-activities',
            $result['runId'] => 'dw.compensation.recovery-activities',
        ] as $runId => $changeId) {
            $markers = WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)
                ->where('event_type', \Workflow\V2\Enums\HistoryEventType::VersionMarkerRecorded)->get();
            $this->assertContains([$changeId, 1], $markers->map(static fn (WorkflowHistoryEvent $event): array => [
                $event->payload['change_id'], $event->payload['version'],
            ])->all());
        }
    }

    public function testNativeSagaCompensatesAnEffectWhoseForwardResultWasLost(): void
    {
        WorkflowStub::fake();
        $scope = $this->identity();
        $workflow = WorkflowStub::make(TestRetainedScopeWorkflow::class, 'lost-ack-origin', 'client-a');
        $workflow->start($scope->toArray(), 'lost-ack');
        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame('verified', (new ScopeRepository())->inspect($scope)['status']);
        $this->assertSame(['first'], Cache::get('test:scope:undo-log'));
        $this->assertSame(1, (int) Cache::get('test:scope:effect-count'));
    }

    #[DataProvider('operatorStops')]
    public function testClosureAfterOriginLookupRoutesTheSameRetainedRequestToRecovery(string $stop): void
    {
        [$scope, $origin] = $this->waitingClosureRaceOrigin();
        $armed = true;
        $history = null;
        \Illuminate\Support\Facades\DB::listen(static function (QueryExecuted $query) use (
            &$armed,
            &$history,
            $origin,
            $stop,
        ): void {
            if (! $armed || ! str_starts_with(strtolower(ltrim($query->sql)), 'select')
                || ! str_contains($query->sql, 'workflow_runs')
                || ! in_array($origin->runId(), $query->bindings, true)
                || $query->connection->transactionLevel() !== 0) {
                return;
            }
            // The original status has been read, but cooperative delivery has not begun.
            $armed = false;
            $origin->{$stop}('Technical closure wins before the cleanup request reaches its origin.');
            $history = WorkflowHistoryEvent::query()->where('workflow_run_id', $origin->runId())
                ->orderBy('id')
                ->get()
                ->toArray();
        });
        try {
            $result = app(ScopeRecovery::class)->request($scope, 'closure-race', 'operator-a', 'cancel-order');
        } finally {
            $armed = false;
        }
        $this->assertNotNull($history, 'The test must hit the unlocked origin-status read before routing.');
        $this->assertSame('recovery', $result['route']);
        $this->assertNotSame($origin->runId(), $result['runId']);
        $this->assertSame('requested', $result['scopeStatus']);
        $this->assertSame('observe_recovery', $result['nextAction']);
        $this->assertNull(Cache::get('test:scope:undo-log'));
        $this->drainClosureRaceTasks();
        $this->assertClosureRaceResolved($scope, $origin, $result['runId'], $history);
    }

    #[DataProvider('operatorStops')]
    public function testAcceptedOriginRequestSurvivesClosureBeforeItsSagaRuns(string $stop): void
    {
        [$scope, $origin] = $this->waitingClosureRaceOrigin();
        $accepted = app(ScopeRecovery::class)->request($scope, 'closure-race', 'operator-a', 'cancel-order');
        $this->assertSame('origin', $accepted['route']);
        $this->assertSame($origin->runId(), $accepted['runId']);
        $this->assertSame('requested', (new ScopeRepository())->inspect($scope)['status']);
        $this->assertNull(Cache::get('test:scope:undo-log'));
        $origin->{$stop}('Technical closure wins after request acceptance but before Saga cleanup.');
        $history = WorkflowHistoryEvent::query()->where('workflow_run_id', $origin->runId())
            ->orderBy('id')
            ->get()
            ->toArray();
        $result = app(ScopeRecovery::class)->request($scope, 'closure-race', 'operator-a', 'cancel-order');
        $this->assertSame('recovery', $result['route']);
        $this->assertSame('requested', $result['scopeStatus']);
        $this->drainClosureRaceTasks();
        $this->assertClosureRaceResolved($scope, $origin, $result['runId'], $history);
    }

    public function testRecoveryWorkerLossDoesNotRepeatVerifiedCompensation(): void
    {
        if (! function_exists('posix_kill')) {
            $this->markTestSkipped('The recovery-worker loss control requires POSIX signals.');
        }
        [$scope, $origin] = $this->waitingClosureRaceOrigin();
        $origin->terminate('Recover the original effects from the retained ledger.');
        $history = WorkflowHistoryEvent::query()->where('workflow_run_id', $origin->runId())
            ->orderBy('id')
            ->get()
            ->toArray();
        $input = tempnam(sys_get_temp_dir(), 'scope-resume-input-');
        $report = tempnam(sys_get_temp_dir(), 'scope-resume-report-');
        chmod($input, 0600);
        chmod($report, 0600);
        $process = null;
        try {
            $data = [
                'scope' => $scope->toArray(),
                'database' => config('database.connections.' . config('database.default')),
                'redis' => config('database.redis'),
                'cache' => config('cache.stores.redis'),
                'cache_prefix' => config('cache.prefix'),
                'report' => $report,
                'crash_after_second' => true,
            ];
            file_put_contents($input, json_encode($data, JSON_THROW_ON_ERROR));
            $command = [PHP_BINARY, 'vendor/bin/phpunit', 'tests/Support/CompensationScopeRecoveryProcessTest.php'];
            $process = new \Symfony\Component\Process\Process(
                $command,
                dirname(__DIR__, 3),
                [
                    'DWF_SCOPE_RECOVERY_INPUT' => $input,
                ],
                timeout: 30
            );
            $process->start();
            $crashedPid = $process->getPid();
            try {
                $process->wait();
                $this->fail('The recovery worker must die after exactly one verified compensation.');
            } catch (\Symfony\Component\Process\Exception\ProcessSignaledException $error) {
                $this->assertSame(SIGKILL, $error->getProcess()->getTermSignal());
            }
            $cut = json_decode(file_get_contents($report), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($crashedPid, $cut['pid']);
            $this->assertNotSame(getmypid(), $crashedPid);
            $this->assertSame('requested', $cut['status']);
            $before = (new ScopeRepository())->inspect($scope);
            $this->assertSame('pending', $before['obligations'][0]['compensation_status']);
            $this->assertSame('verified', $before['obligations'][1]['compensation_status']);
            $this->assertSame(['second'], Cache::get('test:scope:undo-log'));
            $this->assertSame('first', Cache::get('test:scope:effect:' . $before['obligations'][0]['id']));
            $this->assertNull(Cache::get('test:scope:effect:' . $before['obligations'][1]['id']));

            $data['crash_after_second'] = false;
            file_put_contents($input, json_encode($data, JSON_THROW_ON_ERROR));
            file_put_contents($report, '');
            $process = new \Symfony\Component\Process\Process(
                $command,
                dirname(__DIR__, 3),
                [
                    'DWF_SCOPE_RECOVERY_INPUT' => $input,
                ],
                timeout: 30
            );
            $process->mustRun();
            $recovered = json_decode(file_get_contents($report), true, flags: JSON_THROW_ON_ERROR);
            $this->assertNotSame(getmypid(), $recovered['pid']);
            $this->assertNotSame($crashedPid, $recovered['pid']);
            $this->assertSame($cut['runId'], $recovered['runId']);
            $this->assertSame('verified', $recovered['status']);
            $after = (new ScopeRepository())->inspect($scope);
            $this->assertSame($origin->runId(), $after['original_run_id']);
            $this->assertSame(['verified', 'verified'], array_column($after['obligations'], 'compensation_status'));
            $this->assertSame(array_column($before['obligations'], 'id'), array_column($after['obligations'], 'id'));
            $this->assertSame(['second', 'first'], Cache::get('test:scope:undo-log'));
            $this->assertSame(2, (int) Cache::get('test:scope:effect-count'));
            $this->assertSame(
                1,
                WorkflowRun::query()->where('workflow_instance_id', $scope->recoveryInstanceId())->count()
            );
            $this->assertSame('terminated', $origin->refresh()->status());
            $this->assertSame($history, WorkflowHistoryEvent::query()->where('workflow_run_id', $origin->runId())
                ->orderBy('id')
                ->get()
                ->toArray());
        } finally {
            if ($process !== null && $process->isRunning()) {
                $process->stop(0, SIGKILL);
            }
            unlink($input);
            unlink($report);
        }
    }

    public static function operatorStops(): array
    {
        return [
            'cancel' => ['cancel'],
            'terminate' => ['terminate'],
        ];
    }

    #[DataProvider('operatorStops')]
    public function testFreshRequestsResumeStoppedRecoveryWithoutRebindingOldRequests(string $stop): void
    {
        [$scope, $origin] = $this->completedOrigin();
        Cache::put('test:scope:unknown-first', true);
        $first = app(ScopeRecovery::class)->request($scope, 'recovery-1', 'operator-a', 'cancel');
        $firstStub = WorkflowStub::loadRun($first['runId']);
        $this->assertFalse($firstStub->completed());
        $stopped = $stop === 'cancel' ? $firstStub->cancel('operator-stop') : $firstStub->terminate('operator-stop');
        $this->assertTrue($stopped->accepted());
        $firstHistory = WorkflowHistoryEvent::query()->where('workflow_run_id', $first['runId'])
            ->orderBy('sequence')
            ->get()
            ->toArray();
        $second = app(ScopeRecovery::class)->request($scope, 'recovery-2', 'operator-a', 'cancel');
        $this->assertNotSame($first['runId'], $second['runId']);
        $secondStub = WorkflowStub::loadRun($second['runId']);
        $stopped = $stop === 'cancel' ? $secondStub->cancel('operator-stop') : $secondStub->terminate('operator-stop');
        $this->assertTrue($stopped->accepted());
        $secondHistory = WorkflowHistoryEvent::query()->where('workflow_run_id', $second['runId'])
            ->orderBy('sequence')
            ->get()
            ->toArray();

        foreach ([['recovery-1', $first], ['recovery-2', $second]] as [$key, $receipt]) {
            $again = app(ScopeRecovery::class)->request($scope, $key, 'operator-a', 'cancel');
            $this->assertSame($receipt['runId'], $again['runId']);
            $this->assertSame($receipt['commandId'], $again['commandId']);
            $this->assertSame($stop === 'cancel' ? 'cancelled' : 'terminated', $again['recoveryStatus']);
            $this->assertSame('submit_fresh_authorized_request', $again['nextAction']);
        }
        $this->assertSame(
            2,
            WorkflowRun::query()->where('workflow_instance_id', $scope->recoveryInstanceId())->count()
        );
        Cache::forget('test:scope:unknown-first');
        $third = app(ScopeRecovery::class)->request($scope, 'recovery-3', 'operator-a', 'cancel');
        $this->assertNotContains($third['runId'], [$first['runId'], $second['runId']]);
        $this->assertTrue(WorkflowStub::loadRun($third['runId'])->completed());
        $this->assertSame('verified', (new ScopeRepository())->inspect($scope)['status']);
        $this->assertSame(1, count(array_filter(
            Cache::get('test:scope:undo-log'),
            static fn (string $key): bool => $key === 'second',
        )));
        $this->assertSame($firstHistory, WorkflowHistoryEvent::query()->where('workflow_run_id', $first['runId'])
            ->orderBy('sequence')
            ->get()
            ->toArray());
        $this->assertSame($secondHistory, WorkflowHistoryEvent::query()->where('workflow_run_id', $second['runId'])
            ->orderBy('sequence')
            ->get()
            ->toArray());
        $retained = app(ScopeRecovery::class)->request($scope, 'recovery-1', 'operator-a', 'cancel');
        $this->assertSame($first['runId'], $retained['runId']);
        $this->assertSame($first['commandId'], $retained['commandId']);
        $this->assertSame('verified', $retained['scopeStatus']);
        $this->assertSame('none', $retained['nextAction']);
        $this->assertSame($origin->runId(), (new ScopeRepository())->inspect($scope)['original_run_id']);
    }

    public function testRetainedRecoveryRequestCannotChangeItsInitialBudget(): void
    {
        [$scope] = $this->completedOrigin();
        Cache::put('test:scope:unknown-first', true);
        $profile = StartOptions::returnExistingActive()->withExecutionTimeout(600)->withRunTimeout(300);
        $first = app(ScopeRecovery::class)->request($scope, 'bounded-recovery', 'operator-a', 'cancel', $profile);
        $this->assertTrue(WorkflowStub::loadRun($first['runId'])->terminate('operator-stop')->accepted());
        $history = WorkflowHistoryEvent::query()->where('workflow_run_id', $first['runId'])->count();
        try {
            app(ScopeRecovery::class)->request(
                $scope,
                'bounded-recovery',
                'operator-a',
                'cancel',
                $profile->withExecutionTimeout(601),
            );
            $this->fail('The same recovery request admitted a different initial budget.');
        } catch (\LogicException $error) {
            $this->assertStringContainsString('profile changed', $error->getMessage());
        }
        $this->assertSame(
            1,
            WorkflowRun::query()->where('workflow_instance_id', $scope->recoveryInstanceId())->count()
        );
        $this->assertSame($history, WorkflowHistoryEvent::query()->where('workflow_run_id', $first['runId'])->count());
    }

    public function testRecoveryRefusesCallerTransactionsBeforeHostAuthorization(): void
    {
        [$scope] = $this->completedOrigin();
        $calls = 0;
        $this->app->instance(ScopeAuthorization::class, new class($calls) implements ScopeAuthorization {
            public function __construct(
                private int &$calls
            ) {
            }

            public function assertAllowed(ScopeIdentity $scope, string $principal, string $operation): string
            {
                ++$this->calls;
                return 'policy';
            }
        });
        $database = ConfiguredV2Models::connection();
        $database->beginTransaction();
        try {
            app(ScopeRecovery::class)->request($scope, 'inside-transaction', 'operator-a', 'cancel');
            $this->fail('Recovery ran host authorization under a caller transaction.');
        } catch (\LogicException $error) {
            $this->assertStringContainsString('unlocked host context', $error->getMessage());
            $this->assertSame(0, $calls);
        } finally {
            $database->rollBack();
        }
    }

    public function testRedriveResponseCannotReturnAnotherCanonicalScopeInTheSameNamespace(): void
    {
        [$scope] = $this->completedOrigin();
        Cache::put('test:scope:unknown-first', true);
        $first = app(ScopeRecovery::class)->request($scope, 'recovery-1', 'operator-a', 'cancel');
        $this->driveNativeRetries($first['runId']);
        $foreign = new ScopeIdentity('shop', 'client-a', 'foreign-response', '1');
        WorkflowStub::make(TestRetainedScopeWorkflow::class, 'foreign-response-origin', 'client-a')
            ->start($foreign->toArray(), 'complete');
        $foreignRecovery = app(ScopeRecovery::class)->request($foreign, 'foreign-1', 'operator-a', 'cancel');
        $before = [
            WorkflowRun::query()->count(),
            WorkflowCommand::query()->count(),
            WorkflowHistoryEvent::query()->count(),
        ];
        $runtime = $this->createMock(WorkflowControlPlane::class);
        $runtime->expects($this->once())
            ->method('redrive')
            ->willReturn([
                'accepted' => true,
                'workflow_run_id' => $foreignRecovery['runId'],
            ]);
        $this->app->instance(WorkflowControlPlane::class, $runtime);
        try {
            app(ScopeRecovery::class)->request($scope, 'recovery-2', 'operator-a', 'cancel');
            $this->fail('The scoped facade accepted a foreign native response.');
        } catch (\LogicException $error) {
            $this->assertStringContainsString('exact canonical scope', $error->getMessage());
        }
        $this->assertSame($before, [
            WorkflowRun::query()->count(),
            WorkflowCommand::query()->count(),
            WorkflowHistoryEvent::query()->count(),
        ]);
        $this->assertSame('requested', (new ScopeRepository())->inspect($scope)['status']);
    }

    public function testRetryExhaustionStaysUnresolvedAndNativeRedriveReusesVerifiedWork(): void
    {
        [$scope] = $this->completedOrigin();
        Cache::put('test:scope:unknown-first', true);
        $first = app(ScopeRecovery::class)->request($scope, 'cancel-1', 'operator-a', 'order-cancelled');
        $this->driveNativeRetries($first['runId']);
        $this->assertSame('failed', WorkflowRun::query()->findOrFail($first['runId'])->status->value);
        $this->assertSame('requested', (new ScopeRepository())->inspect($scope)['status']);
        $this->assertSame(
            1,
            count(array_filter(Cache::get('test:scope:undo-log'), static fn (string $key): bool => $key === 'second'))
        );
        Cache::forget('test:scope:unknown-first');
        $again = app(ScopeRecovery::class)->request($scope, 'cancel-2', 'operator-a', 'order-cancelled');
        $this->assertNotSame($first['runId'], $again['runId']);
        $this->assertSame('verified', (new ScopeRepository())->inspect($scope)['status']);
        $this->assertSame(
            1,
            count(array_filter(Cache::get('test:scope:undo-log'), static fn (string $key): bool => $key === 'second'))
        );
        $this->assertSame(
            2,
            WorkflowRun::query()->where('workflow_instance_id', $scope->recoveryInstanceId())->count()
        );
    }

    public function testNativeActiveAdoptionDoesNotPreventRecoveryRedriveAfterFailure(): void
    {
        [$scope] = $this->completedOrigin();
        Cache::put('test:scope:unknown-first', true);
        $first = app(ScopeRecovery::class)->request($scope, 'cancel-1', 'operator-a', 'cancel');
        $adopted = WorkflowStub::make(RecoveryWorkflow::class, $scope->recoveryInstanceId(), $scope->namespace)
            ->attemptStart(StartOptions::returnExistingActive(), $scope->toArray());
        $this->assertTrue($adopted->returnedExistingActive());
        $this->assertSame($first['runId'], $adopted->runId());
        $started = WorkflowHistoryEvent::query()->where('workflow_run_id', $first['runId'])
            ->where('event_type', \Workflow\V2\Enums\HistoryEventType::WorkflowStarted)->firstOrFail();
        $adoption = WorkflowHistoryEvent::query()->where('workflow_command_id', $adopted->commandId())->firstOrFail();
        $this->assertGreaterThan($started->sequence, $adoption->sequence);
        $this->driveNativeRetries($first['runId']);
        $this->assertSame('failed', WorkflowRun::query()->findOrFail($first['runId'])->status->value);
        Cache::forget('test:scope:unknown-first');
        $redrive = app(ScopeRecovery::class)->request($scope, 'cancel-2', 'operator-a', 'cancel');
        $this->assertNotSame($first['runId'], $redrive['runId']);
        $this->assertSame('verified', (new ScopeRepository())->inspect($scope)['status']);
        $this->assertSame(1, count(array_filter(
            Cache::get('test:scope:undo-log'),
            static fn (string $key): bool => $key === 'second'
        )));
    }

    public function testFreshRequestsCanRedriveTwoFailuresWithoutRepeatingVerifiedWork(): void
    {
        [$scope] = $this->completedOrigin();
        Cache::put('test:scope:unknown-first', true);
        $first = app(ScopeRecovery::class)->request($scope, 'cancel-1', 'operator-a', 'cancel');
        $this->driveNativeRetries($first['runId']);
        $retained = app(ScopeRecovery::class)->request($scope, 'cancel-1', 'operator-a', 'cancel');
        $this->assertSame($first['runId'], $retained['runId']);
        $this->assertSame($first['commandId'], $retained['commandId']);
        $this->assertSame('submit_fresh_authorized_request', $retained['nextAction']);
        $second = app(ScopeRecovery::class)->request($scope, 'cancel-2', 'operator-a', 'cancel');
        $this->assertNotSame($first['runId'], $second['runId']);
        $duplicate = app(ScopeRecovery::class)->request($scope, 'cancel-2', 'operator-a', 'cancel');
        $this->assertSame($second['runId'], $duplicate['runId']);
        $this->driveNativeRetries($second['runId']);
        $this->assertSame('failed', WorkflowRun::query()->findOrFail($second['runId'])->status->value);
        Cache::forget('test:scope:unknown-first');
        $third = app(ScopeRecovery::class)->request($scope, 'cancel-3', 'operator-a', 'cancel');
        $this->assertNotSame($second['runId'], $third['runId']);
        $this->assertSame('verified', (new ScopeRepository())->inspect($scope)['status']);
        $this->assertSame(
            3,
            WorkflowRun::query()->where('workflow_instance_id', $scope->recoveryInstanceId())->count()
        );
        $this->assertSame(3, \Workflow\V2\Support\ConfiguredV2Models::connection()
            ->table('workflow_compensation_requests')
            ->where('scope_id', $scope->id())
            ->count());
        $this->assertSame(1, count(array_filter(
            Cache::get('test:scope:undo-log'),
            static fn (string $key): bool => $key === 'second'
        )));
    }

    public function testForwardEvidenceSurvivesReleaseWithoutRetainingBusinessInputsOrResults(): void
    {
        [$scope] = $this->completedOrigin();
        $repository = new ScopeRepository();
        $observations = array_values(array_filter(
            $repository->journal($scope),
            static fn (array $row): bool => $row['kind'] === 'forward_outcome'
        ));
        $this->assertCount(2, $observations);
        foreach ($observations as $row) {
            $record = json_decode($row['data'], true, flags: JSON_THROW_ON_ERROR);
            $this->assertArrayHasKey('evidence', $record);
            $this->assertSame(['provider:' . $record['obligationId']], $record['evidence']);
            $this->assertArrayNotHasKey('value', $record);
            $this->assertArrayNotHasKey('input', $record);
        }
        app(ScopeRecovery::class)->request($scope, 'cancel', 'operator-a', 'cancel');
        app(\Workflow\V2\Compensation\Recovery\ScopeRetention::class)->release($scope, 'operator-a');
        $retained = array_values(array_filter(
            $repository->journal($scope),
            static fn (array $row): bool => $row['kind'] === 'forward_outcome'
        ));
        $this->assertSame($observations, $retained);
        foreach ($repository->inspect($scope)['obligations'] as $row) {
            $this->assertNull($row['forward_result']);
            $this->assertArrayNotHasKey('input', $row['descriptor']);
        }
    }

    public function testColdReplayDoesNotResolveMutableHandlersOrRepeatProviderEffects(): void
    {
        [$scope, $origin] = $this->completedOrigin();
        $effects = Cache::get('test:scope:effect-count');
        $this->app->instance(HandlerRegistry::class, new HandlerRegistry([]));
        app(WorkflowReplayer::class)->replay(WorkflowRun::query()->findOrFail($origin->runId()));
        $this->assertSame($effects, Cache::get('test:scope:effect-count'));
        $this->assertNull(Cache::get('test:scope:undo-log'));
        $this->assertSame('open', (new ScopeRepository())->inspect($scope)['status']);
    }

    public function testUnavailablePinnedHandlerFailsBeforeCompensationEffect(): void
    {
        [$scope] = $this->completedOrigin();
        $this->app->instance(HandlerRegistry::class, new HandlerRegistry([]));
        app(ScopeRecovery::class)->request($scope, 'cancel-1', 'operator-a', 'order-cancelled');
        $this->assertSame('requested', (new ScopeRepository())->inspect($scope)['status']);
        $this->assertNull(Cache::get('test:scope:undo-log'));
    }

    public function testExpiredNativeAttemptCannotPublishVerifiedCompensation(): void
    {
        [$scope] = $this->completedOrigin();
        Cache::put('test:scope:expire-native', true);
        app(ScopeRecovery::class)->request($scope, 'cancel-1', 'operator-a', 'order-cancelled');
        $snapshot = (new ScopeRepository())->inspect($scope);
        $this->assertSame('requested', $snapshot['status']);
        $this->assertNotContains('verified', array_column($snapshot['obligations'], 'compensation_status'));
    }

    public function testPossibleLateForwardEffectCannotBecomeVerifiedAfterRetryExhaustion(): void
    {
        [$scope] = $this->completedOrigin();
        Cache::put('test:scope:late-forward-possible', true);
        $recovery = app(ScopeRecovery::class)->request($scope, 'cancel-1', 'operator-a', 'order-cancelled');
        $this->driveNativeRetries($recovery['runId']);
        $snapshot = (new ScopeRepository())->inspect($scope);
        $this->assertSame('requested', $snapshot['status']);
        $this->assertNotContains('verified', array_column($snapshot['obligations'], 'compensation_status'));
        $this->assertSame('failed', WorkflowRun::query()->findOrFail($recovery['runId'])->status->value);
    }

    public function testRecordedAuthorizationIncludesNativeAttemptAndPolicyVersion(): void
    {
        [$scope] = $this->completedOrigin();
        app(ScopeRecovery::class)->request($scope, 'cancel-1', 'operator-a', 'order-cancelled');
        $events = array_values(
            array_filter((new ScopeRepository())->journal(
                $scope
            ), static fn (array $row): bool => $row['kind'] === 'authorized')
        );
        $this->assertNotEmpty($events);
        foreach ($events as $row) {
            $record = json_decode($row['data'], true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('test-policy-1', $record['policyVersion']);
            $this->assertNotEmpty($record['attemptId']);
            $this->assertNotEmpty($record['runId']);
        }
    }

    public function testCompletedChildScopeIsVerifiedBeforeEarlierParentCompensation(): void
    {
        WorkflowStub::fake();
        $child = new ScopeIdentity('shop', 'client-a', 'shipment-42', '1');
        $childOrigin = WorkflowStub::make(TestRetainedScopeWorkflow::class, 'child-origin', 'client-a');
        $childOrigin->start($child->toArray(), 'complete');
        $this->assertTrue($childOrigin->refresh()->completed());
        $parent = $this->identity();
        $parentOrigin = WorkflowStub::make(TestRetainedScopeWorkflow::class, 'parent-origin', 'client-a');
        $parentOrigin->start($parent->toArray(), 'with-child', $child->toArray());
        $this->assertTrue($parentOrigin->refresh()->completed());
        app(ScopeRecovery::class)->request($parent, 'cancel-1', 'operator-a', 'order-cancelled');
        $this->assertSame('verified', (new ScopeRepository())->inspect($parent)['status']);
        $this->assertSame('verified', (new ScopeRepository())->inspect($child)['status']);
        $this->assertSame(
            ['order-42/second', 'shipment-42/second', 'shipment-42/first', 'order-42/first'],
            Cache::get('test:scope:undo-scoped-log')
        );
    }

    public function testFailedChildNeedsAnExplicitFreshRequestBeforeParentRecoveryCanAdvance(): void
    {
        WorkflowStub::fake();
        $child = new ScopeIdentity('shop', 'client-a', 'failed-child', '1');
        WorkflowStub::make(TestRetainedScopeWorkflow::class, 'failed-child-origin', 'client-a')
            ->start($child->toArray(), 'complete');
        $parent = new ScopeIdentity('shop', 'client-a', 'waiting-parent', '1');
        WorkflowStub::make(TestRetainedScopeWorkflow::class, 'waiting-parent-origin', 'client-a')
            ->start($parent->toArray(), 'with-child', $child->toArray());
        Cache::put('test:scope:unknown-first', true);
        $parentRecovery = app(ScopeRecovery::class)->request($parent, 'parent-1', 'operator-a', 'cancel');
        $childRun = WorkflowRun::query()->where('workflow_instance_id', $child->recoveryInstanceId())->sole();
        $this->driveNativeRetries($childRun->id);
        $this->assertSame('failed', $childRun->refresh()->status->value);
        $this->driveNativeRetries($parentRecovery['runId']);
        $this->assertSame('failed', WorkflowRun::query()->findOrFail($parentRecovery['runId'])->status->value);
        $this->assertSame(
            1,
            WorkflowRun::query()->where('workflow_instance_id', $child->recoveryInstanceId())->count()
        );
        $this->assertNotContains('waiting-parent/first', Cache::get('test:scope:undo-scoped-log'));
        Cache::forget('test:scope:unknown-first');
        $retained = app(ScopeRecovery::class)->request($parent, 'parent-1', 'operator-a', 'cancel');
        $this->assertSame($parentRecovery['runId'], $retained['runId']);
        $this->assertSame('submit_fresh_authorized_request', $retained['nextAction']);
        $this->assertSame(
            1,
            WorkflowRun::query()->where('workflow_instance_id', $child->recoveryInstanceId())->count()
        );

        app(ScopeRecovery::class)->request($child, 'explicit-child-2', 'operator-a', 'child-recovery');
        $this->assertSame('verified', (new ScopeRepository())->inspect($child)['status']);
        $this->assertNotContains('waiting-parent/first', Cache::get('test:scope:undo-scoped-log'));
        $resumed = app(ScopeRecovery::class)->request($parent, 'parent-2', 'operator-a', 'cancel');
        $this->assertTrue(WorkflowStub::loadRun($resumed['runId'])->completed());
        $this->assertSame('verified', (new ScopeRepository())->inspect($parent)['status']);
        $log = Cache::get('test:scope:undo-scoped-log');
        $parentFirst = array_search('waiting-parent/first', $log, true);
        $childFirst = array_keys($log, 'failed-child/first', true);
        $this->assertGreaterThan(max($childFirst), $parentFirst);
        foreach (['waiting-parent/second', 'failed-child/second', 'waiting-parent/first'] as $operation) {
            $this->assertSame(1, count(array_keys($log, $operation, true)));
        }
    }

    public function testChildParticipationRecordsItsOwnAuthorizationPolicyVersion(): void
    {
        WorkflowStub::fake();
        $this->app->instance(ScopeAuthorization::class, new class() implements ScopeAuthorization {
            public function assertAllowed(ScopeIdentity $scope, string $principal, string $operation): string
            {
                return $operation === 'participate' ? 'child-policy-7' : 'parent-policy-2';
            }
        });
        $parent = $this->identity();
        $child = new ScopeIdentity('shop', 'client-a', 'shipment-42', '1');
        $origin = WorkflowStub::make(TestRetainedScopeWorkflow::class, 'parent-origin', 'client-a');
        $origin->start($parent->toArray(), 'with-child', $child->toArray());
        $this->assertTrue($origin->refresh()->completed());
        $records = array_values(array_filter(
            (new ScopeRepository())->journal($parent),
            static fn (array $row): bool => $row['kind'] === 'child_authorized'
        ));
        $this->assertCount(1, $records);
        $record = json_decode($records[0]['data'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('child-policy-7', $record['policyVersion']);
        $this->assertSame($child->id(), $record['childScopeId']);
        $this->assertSame('operator-a', $record['principal']);
        $this->assertNotEmpty($record['attemptId']);
    }

    public function testInvalidChildParticipationPolicyCannotRegisterAChild(): void
    {
        WorkflowStub::fake();
        $this->app->instance(ScopeAuthorization::class, new class() implements ScopeAuthorization {
            public function assertAllowed(ScopeIdentity $scope, string $principal, string $operation): string
            {
                return $operation === 'participate' ? '' : 'parent-policy-2';
            }
        });
        $parent = $this->identity();
        $child = new ScopeIdentity('shop', 'client-a', 'shipment-42', '1');
        $origin = WorkflowStub::make(TestRetainedScopeWorkflow::class, 'parent-origin', 'client-a');
        $origin->start($parent->toArray(), 'with-child', $child->toArray());
        $this->assertCount(1, (new ScopeRepository())->inspect($parent)['obligations']);
        $this->assertSame(0, \Workflow\V2\Support\ConfiguredV2Models::connection()
            ->table('workflow_compensation_children')
            ->count());
        $this->assertSame(1, (int) Cache::get('test:scope:effect-count'));
    }

    public function testUnavailableChildCannotAdvanceEarlierParentCompensation(): void
    {
        WorkflowStub::fake();
        $parent = $this->identity();
        $child = new ScopeIdentity('shop', 'client-a', 'missing-shipment', '1');
        $origin = WorkflowStub::make(TestRetainedScopeWorkflow::class, 'parent-origin', 'client-a');
        $origin->start($parent->toArray(), 'with-child', $child->toArray());
        $this->assertTrue($origin->refresh()->completed());
        $result = app(ScopeRecovery::class)->request($parent, 'cancel-1', 'operator-a', 'order-cancelled');
        $this->driveNativeRetries($result['runId']);
        $this->assertSame('requested', (new ScopeRepository())->inspect($parent)['status']);
        $this->assertSame(['second'], Cache::get('test:scope:undo-log'));
    }

    public function testOrdinaryRunPruningPreservesRetainedScopeRecovery(): void
    {
        [$scope, $origin] = $this->completedOrigin();
        \Workflow\V2\Support\WorkflowRunRetentionCleanup::pruneRun($origin->runId());
        $this->assertSame(0, WorkflowHistoryEvent::query()->where('workflow_run_id', $origin->runId())->count());
        app(ScopeRecovery::class)->request($scope, 'cancel-1', 'operator-a', 'order-cancelled');
        $this->assertSame('verified', (new ScopeRepository())->inspect($scope)['status']);
        $this->assertSame(['second', 'first'], Cache::get('test:scope:undo-log'));
    }

    public static function retainedRecoveryStates(): array
    {
        return [['failed'], ['cancelled'], ['terminated'], ['completed']];
    }

    #[DataProvider('retainedRecoveryStates')]
    public function testCanonicalRecoveryDetailsRemainPinnedUntilExplicitRelease(string $terminal): void
    {
        [$scope] = $this->completedOrigin();
        if ($terminal !== 'completed') {
            Cache::put('test:scope:unknown-first', true);
        }
        $first = app(ScopeRecovery::class)->request($scope, 'recovery-1', 'operator-a', 'cancel');
        $firstStub = WorkflowStub::loadRun($first['runId']);
        match ($terminal) {
            'cancelled' => $firstStub->cancel('operator-stop'),
            'terminated' => $firstStub->terminate('operator-stop'),
            'failed' => $this->driveNativeRetries($first['runId']),
            default => null,
        };
        $this->assertSame($terminal, $firstStub->refresh()->status());
        $this->assertRecoveryPruningHeld($first['runId']);
        $again = app(ScopeRecovery::class)->request($scope, 'recovery-1', 'operator-a', 'cancel');
        $this->assertSame($first['runId'], $again['runId']);
        Cache::forget('test:scope:unknown-first');
        $last = $terminal === 'completed' ? $first
            : app(ScopeRecovery::class)->request($scope, 'recovery-2', 'operator-a', 'cancel');
        $this->driveNativeRetries($last['runId']);
        $this->assertSame('verified', (new ScopeRepository())->inspect($scope)['status']);
        foreach (array_unique([$first['runId'], $last['runId']]) as $runId) {
            $this->assertRecoveryPruningHeld($runId);
        }
        $again = app(ScopeRecovery::class)->request($scope, 'recovery-1', 'operator-a', 'cancel');
        $this->assertSame($first['runId'], $again['runId']);
        app(ScopeRetention::class)->release($scope, 'operator-a');
        $commandsDeleted = 0;
        foreach (array_unique([$first['runId'], $last['runId']]) as $runId) {
            $this->assertNull(WorkflowRunRetentionCleanup::retentionHoldReason($runId));
            $report = WorkflowRunRetentionCleanup::pruneRun($runId);
            $commandsDeleted += $report['commands_deleted'];
            $this->assertGreaterThan(0, $report['history_events_deleted']);
            $this->assertNotNull(WorkflowRun::query()->findOrFail($runId)->details_pruned_at);
        }
        $this->assertGreaterThan(0, $commandsDeleted);
    }

    public static function invalidReleaseContexts(): array
    {
        return [['caller-transaction'], ['replay'], ['authorization-transaction']];
    }

    #[DataProvider('invalidReleaseContexts')]
    public function testReleaseRequiresUnlockedHostAuthorization(string $context): void
    {
        [$scope] = $this->completedOrigin();
        app(ScopeRecovery::class)->request($scope, 'recovery-1', 'operator-a', 'cancel');
        $before = (new ScopeRepository())->inspect($scope);
        $calls = 0;
        $this->app->instance(ScopeAuthorization::class, new class($calls, $context) implements ScopeAuthorization {
            public function __construct(
                private int &$calls,
                private string $context
            ) {
            }

            public function assertAllowed(ScopeIdentity $scope, string $principal, string $operation): string
            {
                ++$this->calls;
                if ($this->context === 'authorization-transaction') {
                    ConfiguredV2Models::connection()->beginTransaction();
                }
                return 'release-policy-1';
            }
        });
        $release = function () use ($scope): void {
            try {
                app(ScopeRetention::class)->release($scope, 'operator-a');
                $this->fail('Release crossed the unlocked host boundary.');
            } catch (\LogicException $error) {
                $this->assertStringContainsString('unlocked host context', $error->getMessage());
            }
        };
        $database = ConfiguredV2Models::connection();
        try {
            if ($context === 'caller-transaction') {
                $database->beginTransaction();
            }
            if ($context === 'replay') {
                (new \Fiber(static function () use ($release): void {
                    WorkflowFiberContext::enter();
                    try {
                        $release();
                    } finally {
                        WorkflowFiberContext::leave();
                    }
                }))->start();
            } else {
                $release();
            }
            $this->assertSame($context === 'authorization-transaction' ? 1 : 0, $calls);
            $this->assertSame($before, (new ScopeRepository())->inspect($scope));
        } finally {
            while ($database->transactionLevel() > 0) {
                $database->rollBack();
            }
        }
        $this->assertSame($before, (new ScopeRepository())->inspect($scope));
    }

    public function testTamperedRetainedDescriptorIsRefusedBeforeProviderCall(): void
    {
        [$scope] = $this->completedOrigin();
        $row = (new ScopeRepository())->inspect($scope)['obligations'][1];
        $descriptor = $row['descriptor'];
        $descriptor['input']['changed'] = true;
        \Workflow\V2\Support\ConfiguredV2Models::connection()->table('workflow_compensation_obligations')->where(
            'id',
            $row['id']
        )->update([
            'descriptor' => json_encode($descriptor, JSON_THROW_ON_ERROR),
        ]);
        app(ScopeRecovery::class)->request($scope, 'cancel-1', 'operator-a', 'order-cancelled');
        $this->assertSame('requested', (new ScopeRepository())->inspect($scope)['status']);
        $this->assertNull(Cache::get('test:scope:undo-log'));
    }

    public function testTamperedRegistrationCannotReachTheForwardProvider(): void
    {
        WorkflowStub::fake();
        $scope = $this->identity();
        $tampered = false;
        \Workflow\V2\Support\ConfiguredV2Models::connection()->listen(
            static function (QueryExecuted $query) use ($scope, &$tampered): void {
                if ($tampered || ! str_starts_with($query->sql, 'insert')
                    || ! str_contains($query->sql, 'workflow_compensation_obligations')) {
                    return;
                }
                $tampered = true;
                $row = $query->connection->table('workflow_compensation_obligations')
                    ->where('scope_id', $scope->id())
                    ->first();
                $descriptor = json_decode($row->descriptor, true, flags: JSON_THROW_ON_ERROR);
                $descriptor['input']['changed'] = true;
                $query->connection->table('workflow_compensation_obligations')
                    ->where('id', $row->id)
                    ->update([
                        'descriptor' => json_encode($descriptor, JSON_THROW_ON_ERROR),
                    ]);
            }
        );
        $origin = WorkflowStub::make(TestRetainedScopeWorkflow::class, 'tampered-forward-origin', 'client-a');
        $origin->start($scope->toArray(), 'complete');
        $this->assertTrue($tampered);
        $this->assertNull(Cache::get('test:scope:effect-count'));
        $this->assertNotContains(
            'observed',
            array_column((new ScopeRepository())->inspect($scope)['obligations'], 'forward_status')
        );
    }

    public function testRejectedForwardFailsTheWorkflowAndCompensatesWithoutAProviderCall(): void
    {
        WorkflowStub::fake();
        $scope = $this->identity();
        $workflow = WorkflowStub::make(TestRetainedScopeWorkflow::class, 'rejected-origin', 'client-a');
        $workflow->start($scope->toArray(), 'rejected');
        // The workflow caught the non-retryable rejection, compensated and verified the scope.
        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame('verified', $workflow->output()['status']);
        $ledger = (new ScopeRepository())->inspect($scope);
        $this->assertSame('rejected', $ledger['obligations'][0]['forward_status']);
        $this->assertSame('verified', $ledger['obligations'][0]['compensation_status']);
        $this->assertNull(Cache::get('test:scope:undo-log'));
        $this->assertNull(Cache::get('test:scope:effect-count'));
        $failure = \Workflow\V2\Models\WorkflowFailure::query()->where('workflow_run_id', $workflow->runId())->sole();
        $this->assertSame('scope_forward_rejected', $failure->message);
    }

    public function testFailedCompensationStopsRetriesAndStaysVisible(): void
    {
        [$scope] = $this->completedOrigin();
        Cache::put('test:scope:failed-first', true);
        $result = app(ScopeRecovery::class)->request($scope, 'cancel-1', 'operator-a', 'order-cancelled');
        $this->driveNativeRetries($result['runId']);
        $this->assertSame('failed', WorkflowRun::query()->findOrFail($result['runId'])->status->value);
        // The definite failure was not retried.
        $this->assertSame(['second', 'first'], Cache::get('test:scope:undo-log'));
        $view = app(ScopeRecovery::class)->describe($scope, 'operator-a');
        $this->assertSame('requested', $view['scopeStatus']);
        $this->assertSame('submit_fresh_authorized_request', $view['nextAction']);
        $this->assertSame([[
            'key' => 'first',
            'status' => 'failed',
            'reason' => 'provider-refused-undo',
        ]], $view['unresolved']);
        // A fresh authorized request retries the failed obligation once the provider allows it.
        Cache::forget('test:scope:failed-first');
        $retry = app(ScopeRecovery::class)->request($scope, 'cancel-2', 'operator-a', 'order-cancelled');
        $this->driveNativeRetries($retry['runId']);
        $this->assertSame('verified', (new ScopeRepository())->inspect($scope)['status']);
    }

    public function testOversizedForwardValueIsAHandlerContractViolationNotAnUnknownOutcome(): void
    {
        WorkflowStub::fake();
        $scope = $this->identity();
        $workflow = WorkflowStub::make(TestRetainedScopeWorkflow::class, 'oversized-origin', 'client-a');
        $workflow->start($scope->toArray(), 'oversized');
        // The workflow caught the non-retryable violation, compensated and verified the scope.
        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame('verified', $workflow->output()['status']);
        $failure = \Workflow\V2\Models\WorkflowFailure::query()->where('workflow_run_id', $workflow->runId())->sole();
        $this->assertSame(
            'scope_handler_contract_violation: Compensation forward value exceeds 11264 bytes.',
            $failure->message
        );
        $row = (new ScopeRepository())->inspect($scope)['obligations'][0];
        $this->assertSame('unknown', $row['forward_status']);
        $this->assertSame('handler-contract-violation', json_decode(
            $row['forward_result'],
            true,
            flags: JSON_THROW_ON_ERROR
        )['reason']);
        // The provider was called once; compensation still reconciled the possible effect.
        $this->assertSame(1, Cache::get('test:scope:effect-count'));
        $this->assertSame(['first'], Cache::get('test:scope:undo-log'));
    }

    public function testRunsRecordedBeforeTheRegistrationChecksPatchKeepTheEnvelopeValueLimit(): void
    {
        WorkflowStub::fake();
        $scope = $this->identity();
        $workflow = WorkflowStub::make(TestPrePatchForwardWorkflow::class, 'pre-patch-origin', 'client-a');
        $workflow->start($scope->toArray(), 'oversized');
        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame([
            'receiptBytes' => 12_000,
        ], $workflow->output());
        $this->assertSame('observed', (new ScopeRepository())->inspect($scope)['obligations'][0]['forward_status']);
    }

    public function testDeprecatedForwardSettledFlagStillVerifies(): void
    {
        [$scope] = $this->completedOrigin();
        Cache::put('test:scope:legacy-verified', true);
        $deprecations = [];
        set_error_handler(static function (int $level, string $message) use (&$deprecations): bool {
            $deprecations[] = $message;
            return true;
        }, E_USER_DEPRECATED);
        try {
            app(ScopeRecovery::class)->request($scope, 'cancel-1', 'operator-a', 'order-cancelled');
        } finally {
            restore_error_handler();
        }
        $this->assertSame('verified', (new ScopeRepository())->inspect($scope)['status']);
        $this->assertSame(['second', 'first'], Cache::get('test:scope:undo-log'));
        $this->assertCount(2, $deprecations);
        $this->assertStringContainsString(
            'Passing forwardSettled to Resolution::verified() is deprecated',
            $deprecations[0]
        );
    }

    public function testInvalidResolutionIsAHandlerContractViolationAndIsNotRetried(): void
    {
        [$scope] = $this->completedOrigin();
        Cache::put('test:scope:invalid-resolution', true);
        $result = app(ScopeRecovery::class)->request($scope, 'cancel-1', 'operator-a', 'order-cancelled');
        $this->driveNativeRetries($result['runId']);
        $this->assertSame('failed', WorkflowRun::query()->findOrFail($result['runId'])->status->value);
        // One call: a contract violation is not retried as an unknown provider outcome.
        $this->assertSame(['second', 'first'], Cache::get('test:scope:undo-log'));
        $this->assertSame(
            'scope_handler_contract_violation: Verified compensation requires authoritative evidence.',
            \Workflow\V2\Models\WorkflowFailure::query()->where('workflow_run_id', $result['runId'])
                ->where('message', 'like', 'scope_handler_contract_violation%')
                ->sole()
                ->message
        );
        $this->assertSame([[
            'key' => 'first',
            'status' => 'unresolved',
            'reason' => 'handler-contract-violation',
        ]], app(ScopeRecovery::class)->describe($scope, 'operator-a')['unresolved']);
    }

    public function testRefusedChildCompensationIsPermanentUntilAFreshRequest(): void
    {
        WorkflowStub::fake();
        $this->app->instance(ScopeAuthorization::class, new class() implements ScopeAuthorization {
            public function assertAllowed(ScopeIdentity $scope, string $principal, string $operation): string
            {
                if ($scope->key === 'refused-child' && $operation === 'compensate'
                    && Cache::get('test:scope:refuse-child', false)) {
                    throw new ScopeAuthorizationRefused('Child compensation refused.');
                }
                return 'test-policy-1';
            }
        });
        $child = new ScopeIdentity('shop', 'client-a', 'refused-child', '1');
        WorkflowStub::make(TestRetainedScopeWorkflow::class, 'refused-child-origin', 'client-a')
            ->start($child->toArray(), 'complete');
        $parent = new ScopeIdentity('shop', 'client-a', 'refusing-parent', '1');
        WorkflowStub::make(TestRetainedScopeWorkflow::class, 'refusing-parent-origin', 'client-a')
            ->start($parent->toArray(), 'with-child', $child->toArray());
        Cache::put('test:scope:refuse-child', true);
        $recovery = app(ScopeRecovery::class)->request($parent, 'parent-1', 'operator-a', 'cancel');
        $this->driveNativeRetries($recovery['runId']);
        $this->assertSame('failed', WorkflowRun::query()->findOrFail($recovery['runId'])->status->value);
        $this->assertSame(
            1,
            \Workflow\V2\Models\WorkflowFailure::query()->where('workflow_run_id', $recovery['runId'])
                ->where('message', 'scope_authorization_refused')
                ->count()
        );
        $this->assertSame(
            0,
            WorkflowRun::query()->where('workflow_instance_id', $child->recoveryInstanceId())->count()
        );
        $view = app(ScopeRecovery::class)->describe($parent, 'operator-a');
        $this->assertSame([[
            'key' => 'delivery',
            'status' => 'failed',
            'reason' => 'authorization-refused',
        ]], $view['unresolved']);
        $this->assertSame('submit_fresh_authorized_request', $view['nextAction']);

        Cache::forget('test:scope:refuse-child');
        $retry = app(ScopeRecovery::class)->request($parent, 'parent-2', 'operator-a', 'cancel');
        $this->driveNativeRetries($retry['runId']);
        $this->assertSame('verified', (new ScopeRepository())->inspect($parent)['status']);
        $this->assertSame('verified', (new ScopeRepository())->inspect($child)['status']);
    }

    public function testRequestAndDescribeReturnExactlyTheirDocumentedKeys(): void
    {
        $requestKeys = ['route', 'runId', 'commandId', 'recoveryStatus', 'scopeStatus', 'nextAction'];
        $describeKeys = [
            'scopeStatus', 'originStatus', 'recoveryRunId', 'recoveryStatus', 'automaticRecovery', 'refusal',
            'nextAction', 'unresolved',
        ];
        [$scope] = $this->completedOrigin();
        $recovery = app(ScopeRecovery::class)->request($scope, 'cancel-1', 'operator-a', 'order-cancelled');
        $this->assertSame($requestKeys, array_keys($recovery));
        $this->assertSame('recovery', $recovery['route']);
        $verified = app(ScopeRecovery::class)->request($scope, 'cancel-2', 'operator-a', 'order-cancelled');
        $this->assertSame($requestKeys, array_keys($verified));
        $this->assertSame([
            'route' => 'verified',
            'runId' => null,
            'commandId' => null,
            'recoveryStatus' => null,
            'scopeStatus' => 'verified',
            'nextAction' => 'none',
        ], $verified);
        $view = app(ScopeRecovery::class)->describe($scope, 'operator-a');
        $this->assertSame($describeKeys, array_keys($view));
        $this->assertContains(
            $view['scopeStatus'],
            array_column(\Workflow\V2\Compensation\Recovery\ScopeStatus::cases(), 'value')
        );
        $this->assertContains(
            $view['nextAction'],
            array_column(\Workflow\V2\Compensation\Recovery\NextAction::cases(), 'value')
        );
        $this->assertContains(
            $view['automaticRecovery'],
            array_column(\Workflow\V2\Compensation\Recovery\AutomaticRecovery::cases(), 'value')
        );
    }

    public function testRequestRoutedToAnOpenOriginReturnsTheSameKeys(): void
    {
        [$scope] = $this->waitingClosureRaceOrigin();
        $routed = app(ScopeRecovery::class)->request($scope, 'cancel-open', 'operator-a', 'order-cancelled');
        $this->assertSame(
            ['route', 'runId', 'commandId', 'recoveryStatus', 'scopeStatus', 'nextAction'],
            array_keys($routed)
        );
        $this->assertSame('origin', $routed['route']);
        $this->assertSame('requested', $routed['scopeStatus']);
        $this->assertSame('observe_origin', $routed['nextAction']);
        $this->assertNull($routed['recoveryStatus']);
        $this->assertIsString($routed['commandId']);
    }

    public function testReleaseRefusesUnresolvedWorkThenRetainsAnUnreusableTombstone(): void
    {
        [$scope] = $this->completedOrigin();
        try {
            app(\Workflow\V2\Compensation\Recovery\ScopeRetention::class)->release($scope, 'operator-a');
            $this->fail('Unresolved obligations were released.');
        } catch (\LogicException) {
            $this->assertSame('open', (new ScopeRepository())->inspect($scope)['status']);
        }
        app(ScopeRecovery::class)->request($scope, 'cancel-1', 'operator-a', 'order-cancelled');
        $before = (new ScopeRepository())->inspect($scope);
        app(\Workflow\V2\Compensation\Recovery\ScopeRetention::class)->release($scope, 'operator-a');
        $after = (new ScopeRepository())->inspect($scope);
        $this->assertSame('released', $after['status']);
        $this->assertSame(
            array_column($before['obligations'], 'fingerprint'),
            array_column($after['obligations'], 'fingerprint')
        );
        $this->assertArrayNotHasKey('input', $after['obligations'][0]['descriptor']);
        $this->expectException(\LogicException::class);
        app(ScopeRecovery::class)->request($scope, 'cancel-2', 'operator-a', 'order-cancelled');
    }

    public function testSchemaRollbackCannotDiscardUnresolvedRetainedObligations(): void
    {
        [$scope] = $this->completedOrigin();
        $before = (new ScopeRepository())->inspect($scope);
        try {
            $this->compensationMigration()
                ->down();
            $this->fail('A schema rollback discarded unresolved retained obligations.');
        } catch (\LogicException $error) {
            $this->assertStringContainsString('cannot be discarded by a schema rollback', $error->getMessage());
        }
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('workflow_compensation_journal'));
        $this->assertSame($before, (new ScopeRepository())->inspect($scope));

        app(ScopeRecovery::class)->request($scope, 'cancel-1', 'operator-a', 'order-cancelled');
        $this->assertSame('verified', (new ScopeRepository())->inspect($scope)['status']);
        $this->assertSame(['second', 'first'], Cache::get('test:scope:undo-log'));
    }

    public function testSchemaRollbackCannotDiscardReleasedScopeTombstones(): void
    {
        [$scope] = $this->completedOrigin();
        app(ScopeRecovery::class)->request($scope, 'cancel-1', 'operator-a', 'order-cancelled');
        app(ScopeRetention::class)->release($scope, 'operator-a');
        try {
            $this->compensationMigration()
                ->down();
            $this->fail('A schema rollback discarded released scope tombstones.');
        } catch (\LogicException $error) {
            $this->assertStringContainsString('cannot be discarded by a schema rollback', $error->getMessage());
        }
        $this->assertSame('released', (new ScopeRepository())->inspect($scope)['status']);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('has been released');
        app(ScopeRecovery::class)->request($scope, 'cancel-2', 'operator-a', 'order-cancelled');
    }

    public function testEmptyCompensationLedgerCanRollBackAndMigrateAgain(): void
    {
        $migration = $this->compensationMigration();
        try {
            $migration->down();
            $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('workflow_compensation_scopes'));
            $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('workflow_compensation_journal'));
            $migration->down();
        } finally {
            if (! \Illuminate\Support\Facades\Schema::hasTable('workflow_compensation_scopes')) {
                $migration->up();
            }
        }
        [$scope] = $this->completedOrigin();
        $this->assertSame('open', (new ScopeRepository())->inspect($scope)['status']);
    }

    public function testDefaultEmbeddedNamespaceIsPreservedWithoutInventingAnAlias(): void
    {
        config([
            'workflows.v2.namespaces' => null,
            'workflows.v2.namespace' => null,
        ]);
        $this->app->instance(ScopeAuthorization::class, new class() implements ScopeAuthorization {
            public function assertAllowed(ScopeIdentity $scope, string $principal, string $operation): string
            {
                if ($scope->namespace !== null || $principal !== 'operator-a') {
                    throw new \LogicException('Default namespace access refused.');
                }
                return 'default-policy-1';
            }
        });
        WorkflowStub::fake();
        $scope = new ScopeIdentity('shop', null, 'order-42', '1');
        $origin = WorkflowStub::make(TestRetainedScopeWorkflow::class, 'default-origin');
        $origin->start($scope->toArray(), 'complete');
        $this->assertTrue($origin->refresh()->completed());
        $result = app(ScopeRecovery::class)->request($scope, 'cancel-1', 'operator-a', 'cancel');
        $this->assertSame('verified', (new ScopeRepository())->inspect($scope)['status']);
        $this->assertNull(WorkflowRun::query()->findOrFail($result['runId'])->namespace);
    }

    public function testRetainedRecoveryRunsInAFreshPhpProcess(): void
    {
        [$scope] = $this->completedOrigin();
        $input = tempnam(sys_get_temp_dir(), 'scope-recovery-input-');
        $report = tempnam(sys_get_temp_dir(), 'scope-recovery-report-');
        chmod($input, 0600);
        try {
            file_put_contents($input, json_encode([
                'scope' => $scope->toArray(),
                'database' => config('database.connections.' . config('database.default')),
                'redis' => config('database.redis'),
                'cache' => config('cache.stores.redis'),
                'cache_prefix' => config('cache.prefix'),
                'report' => $report,
            ], JSON_THROW_ON_ERROR));
            $process = new \Symfony\Component\Process\Process([
                PHP_BINARY, 'vendor/bin/phpunit', 'tests/Support/CompensationScopeRecoveryProcessTest.php',
            ], dirname(__DIR__, 3), [
                'DWF_SCOPE_RECOVERY_INPUT' => $input,
            ], timeout: 30);
            $process->mustRun();
            $result = json_decode(file_get_contents($report), true, flags: JSON_THROW_ON_ERROR);
            $this->assertNotSame(getmypid(), $result['pid']);
            $this->assertSame('verified', $result['status']);
            $this->assertSame('verified', (new ScopeRepository())->inspect($scope)['status']);
            $this->assertSame(['second', 'first'], Cache::get('test:scope:undo-log'));
        } finally {
            unlink($input);
            unlink($report);
        }
    }

    public function testWorkerKilledAfterEffectCommitRecoversWithoutAForwardAcknowledgement(): void
    {
        if (! function_exists('posix_kill')) {
            $this->markTestSkipped('The worker-loss control requires POSIX signals.');
        }
        self::stopWorkers();
        \Illuminate\Support\Facades\Queue::fake();
        config([
            'workflows.v2.task_dispatch_mode' => 'queue',
            'queue.default' => 'redis',
        ]);
        $scope = $this->identity();
        $origin = WorkflowStub::make(
            \Tests\Fixtures\V2\TestForwardAdmissionWorkflow::class,
            'crashed-forward-origin',
            'client-a'
        );
        $origin->start($scope->toArray(), 'worker-crash');
        $forward = null;
        for ($step = 0; $step < 20; ++$step) {
            $task = WorkflowTask::query()->where('workflow_run_id', $origin->runId())
                ->where('status', 'ready')
                ->orderBy('available_at')
                ->orderBy('id')
                ->firstOrFail();
            if ($task->task_type->value === 'activity') {
                $execution = \Workflow\V2\Models\ActivityExecution::query()
                    ->findOrFail($task->payload['activity_execution_id']);
                if ($execution->activity_class === \Workflow\V2\Compensation\Activities\ForwardOperationActivity::class) {
                    $forward = $task;
                    break;
                }
                $this->app->call([new \Workflow\V2\Jobs\RunActivityTask($task->id), 'handle']);
            } else {
                $this->app->call([new \Workflow\V2\Jobs\RunWorkflowTask($task->id), 'handle']);
            }
        }
        $this->assertNotNull($forward, 'Registration must commit before the worker-loss boundary.');
        $input = tempnam(sys_get_temp_dir(), 'scope-crash-input-');
        $report = tempnam(sys_get_temp_dir(), 'scope-crash-report-');
        chmod($input, 0600);
        chmod($report, 0600);
        $process = null;
        try {
            $data = [
                'scope' => $scope->toArray(),
                'database' => config('database.connections.' . config('database.default')),
                'redis' => config('database.redis'),
                'cache' => config('cache.stores.redis'),
                'cache_prefix' => config('cache.prefix'),
                'report' => $report,
                'task_id' => $forward->id,
            ];
            file_put_contents($input, json_encode($data, JSON_THROW_ON_ERROR));
            $process = new \Symfony\Component\Process\Process([
                PHP_BINARY, 'vendor/bin/phpunit', 'tests/Support/CompensationForwardCrashProcessTest.php',
            ], dirname(__DIR__, 3), [
                'DWF_SCOPE_FORWARD_CRASH_INPUT' => $input,
            ], timeout: 30);
            $process->start();
            $crashedPid = $process->getPid();
            try {
                $process->wait();
                $this->fail('The forward worker must die before returning its activity result.');
            } catch (\Symfony\Component\Process\Exception\ProcessSignaledException $error) {
                $this->assertSame(SIGKILL, $error->getProcess()->getTermSignal());
            }
            $cut = json_decode(file_get_contents($report), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($crashedPid, $cut['pid']);
            $this->assertNotSame(getmypid(), $crashedPid);
            $before = (new ScopeRepository())->inspect($scope);
            $obligation = $before['obligations'][0];
            $this->assertSame($obligation['id'], $cut['operationId']);
            $this->assertSame($obligation['forward_attempt_id'], $cut['attemptId']);
            $this->assertSame('in_flight', $obligation['forward_status']);
            $this->assertNull($obligation['forward_result']);
            $this->assertSame('first', Cache::get('test:scope:effect:' . $cut['operationId']));
            $this->assertSame(1, (int) Cache::get('test:scope:effect-count'));

            // Closing the technical run fences its attempt; it does not settle the provider effect.
            $origin->terminate('Worker died after the provider committed.');
            $this->assertSame('terminated', $origin->refresh()->status());
            $this->assertSame('first', Cache::get('test:scope:effect:' . $cut['operationId']));
            $history = WorkflowHistoryEvent::query()->where('workflow_run_id', $origin->runId())
                ->orderBy('id')
                ->get()
                ->toArray();

            file_put_contents($report, '');
            $process = new \Symfony\Component\Process\Process([
                PHP_BINARY, 'vendor/bin/phpunit', 'tests/Support/CompensationScopeRecoveryProcessTest.php',
            ], dirname(__DIR__, 3), [
                'DWF_SCOPE_RECOVERY_INPUT' => $input,
            ], timeout: 30);
            $process->mustRun();
            $recovered = json_decode(file_get_contents($report), true, flags: JSON_THROW_ON_ERROR);
            $this->assertNotSame(getmypid(), $recovered['pid']);
            $this->assertNotSame($crashedPid, $recovered['pid']);
            $this->assertSame('verified', $recovered['status']);
            $after = (new ScopeRepository())->inspect($scope);
            $this->assertSame('verified', $after['status']);
            $this->assertSame($origin->runId(), $after['original_run_id']);
            $this->assertSame($cut['operationId'], $after['obligations'][0]['id']);
            $this->assertSame($cut['attemptId'], $after['obligations'][0]['forward_attempt_id']);
            $this->assertSame('terminated', $origin->refresh()->status());
            $this->assertNull(Cache::get('test:scope:effect:' . $cut['operationId']));
            $this->assertSame(['first'], Cache::get('test:scope:undo-log'));
            $this->assertSame(1, (int) Cache::get('test:scope:effect-count'));
            $this->assertSame($history, WorkflowHistoryEvent::query()->where('workflow_run_id', $origin->runId())
                ->orderBy('id')
                ->get()
                ->toArray());
        } finally {
            if ($process !== null && $process->isRunning()) {
                $process->stop(0, SIGKILL);
            }
            unlink($input);
            unlink($report);
        }
    }

    public function testCancellationWhileChildWaitsResolvesItsSagaBeforeEarlierParentWork(): void
    {
        WorkflowStub::fake();
        $child = new ScopeIdentity('shop', 'client-a', 'shipment-42', '1');
        $childOrigin = WorkflowStub::make(
            \Tests\Fixtures\V2\TestWaitingScopeWorkflow::class,
            'waiting-child',
            'client-a'
        );
        $childOrigin->start($child->toArray());
        $this->assertSame('waiting', $childOrigin->refresh()->status());
        $parent = $this->identity();
        $parentOrigin = WorkflowStub::make(TestRetainedScopeWorkflow::class, 'parent-origin', 'client-a');
        $parentOrigin->start($parent->toArray(), 'with-child', $child->toArray());
        app(ScopeRecovery::class)->request($parent, 'cancel-1', 'operator-a', 'order-cancelled');
        $this->assertSame('verified', (new ScopeRepository())->inspect($child)['status']);
        $this->assertSame('verified', (new ScopeRepository())->inspect($parent)['status']);
        $this->assertSame(
            ['order-42/second', 'shipment-42/second', 'shipment-42/first', 'order-42/first'],
            Cache::get('test:scope:undo-scoped-log')
        );
    }

    // This suite uses sync execution or its own explicit cold-process task drivers.
    protected function startQueueWorkersAutomatically(): bool
    {
        return false;
    }

    private function assertRecoveryPruningHeld(string $runId): void
    {
        $snapshot = static fn (): array => [
            WorkflowCommand::query()->orderBy('id')->get()->toArray(),
            WorkflowHistoryEvent::query()->orderBy('id')->get()->toArray(),
            WorkflowLink::query()->orderBy('id')->get()->toArray(),
            WorkflowTask::query()->orderBy('id')->get()->toArray(),
            WorkflowRun::query()->findOrFail($runId)->toArray(),
        ];
        $before = $snapshot();
        $this->assertNotEmpty($before[0]);
        $this->assertNotEmpty($before[1]);
        try {
            $report = WorkflowRunRetentionCleanup::pruneRun($runId);
            $this->fail('Pruned retained recovery receipts: ' . json_encode($report, JSON_THROW_ON_ERROR));
        } catch (\LogicException $error) {
            $this->assertStringContainsString('retained_compensation_scope', $error->getMessage());
        }
        $this->assertSame($before, $snapshot());
        $this->assertSame('retained_compensation_scope', WorkflowRunRetentionCleanup::retentionHoldReason($runId));
    }

    private function driveNativeRetries(string $runId): void
    {
        for ($step = 0; $step < 20; $step++) {
            $run = WorkflowRun::query()->findOrFail($runId);
            if ($run->status->isTerminal()) {
                return;
            }
            $task = \Workflow\V2\Models\WorkflowTask::query()->where('workflow_run_id', $runId)->where(
                'status',
                'ready'
            )->orderBy('available_at')
                ->first();
            $this->assertNotNull($task, 'A nonterminal recovery must retain a native retry task.');
            if ($task->available_at !== null && $task->available_at->isFuture()) {
                \Illuminate\Support\Carbon::setTestNow($task->available_at->copy()->addMicrosecond());
            }
            if ($task->task_type->value === 'activity') {
                (new \Workflow\V2\Jobs\RunActivityTask($task->id))->handle();
            } else {
                (new \Workflow\V2\Jobs\RunWorkflowTask($task->id))->handle();
            }
        }
        $this->fail('Native retry budget did not terminate.');
    }

    private function waitingClosureRaceOrigin(): array
    {
        self::stopWorkers();
        \Illuminate\Support\Facades\Queue::fake();
        config([
            'workflows.v2.task_dispatch_mode' => 'queue',
            'queue.default' => 'redis',
        ]);
        $scope = $this->identity();
        $origin = WorkflowStub::make(
            \Tests\Fixtures\V2\TestWaitingScopeWorkflow::class,
            'closure-race-origin',
            'client-a'
        );
        $origin->start($scope->toArray());
        $this->drainClosureRaceTasks();
        $this->assertSame('waiting', $origin->refresh()->status());
        $this->assertSame(2, (int) Cache::get('test:scope:effect-count'));
        return [$scope, $origin];
    }

    private function drainClosureRaceTasks(): void
    {
        for ($step = 0; $step < 40; ++$step) {
            $task = WorkflowTask::query()->where('status', 'ready')->orderBy('available_at')->orderBy('id')->first();
            if ($task === null || ($task->available_at !== null && $task->available_at->isFuture())) {
                return;
            }
            $job = match ($task->task_type->value) {
                'workflow' => new \Workflow\V2\Jobs\RunWorkflowTask($task->id),
                'activity' => new \Workflow\V2\Jobs\RunActivityTask($task->id),
                'timer' => new \Workflow\V2\Jobs\RunTimerTask($task->id),
            };
            $this->app->call([$job, 'handle']);
        }
        $this->fail('The bounded request/closure control did not settle its native tasks.');
    }

    private function assertClosureRaceResolved(
        ScopeIdentity $scope,
        WorkflowStub $origin,
        string $recoveryRun,
        array $history,
    ): void {
        $again = app(ScopeRecovery::class)->request($scope, 'closure-race', 'operator-a', 'cancel-order');
        $this->assertSame($recoveryRun, $again['runId']);
        $this->assertSame('verified', $again['scopeStatus']);
        $this->assertSame('none', $again['nextAction']);
        $this->assertSame('verified', (new ScopeRepository())->inspect($scope)['status']);
        $this->assertSame(['second', 'first'], Cache::get('test:scope:undo-log'));
        $this->assertSame(2, (int) Cache::get('test:scope:effect-count'));
        $this->assertSame(
            1,
            WorkflowRun::query()->where('workflow_instance_id', $scope->recoveryInstanceId())->count()
        );
        $this->assertSame(1, ConfiguredV2Models::connection()->table('workflow_compensation_requests')->count());
        $this->assertSame($history, WorkflowHistoryEvent::query()->where('workflow_run_id', $origin->runId())
            ->orderBy('id')
            ->get()
            ->toArray());
    }

    private function completedOrigin(): array
    {
        WorkflowStub::fake();
        $scope = $this->identity();
        $workflow = WorkflowStub::make(TestRetainedScopeWorkflow::class, 'retained-origin', 'client-a');
        $workflow->start($scope->toArray(), 'complete');
        $this->assertTrue($workflow->refresh()->completed());
        return [$scope, $workflow];
    }

    private function compensationMigration(): \Workflow\Support\WorkflowMigration
    {
        return require __DIR__ . '/../../../src/migrations/2026_11_11_000100_create_compensation_scopes.php';
    }

    private function identity(): ScopeIdentity
    {
        return new ScopeIdentity('shop', 'client-a', 'order-42', '1');
    }
}
