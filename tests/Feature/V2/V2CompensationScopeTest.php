<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Cache;
use Tests\Fixtures\V2\TestRetainedScopeWorkflow;
use Tests\Fixtures\V2\TestScopedOperation;
use Tests\TestCase;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\Execution\HandlerRegistry;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\Compensation\Recovery\RecoveryWorkflow;
use Workflow\V2\Compensation\Recovery\ScopeRecovery;
use Workflow\V2\Compensation\Security\ScopeAuthorization;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\StartOptions;
use Workflow\V2\Support\WorkflowReplayer;
use Workflow\V2\WorkflowStub;

final class V2CompensationScopeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
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

    public function testOneRetainedRequestCanRedriveTwoFailuresWithoutRepeatingVerifiedWork(): void
    {
        [$scope] = $this->completedOrigin();
        Cache::put('test:scope:unknown-first', true);
        $first = app(ScopeRecovery::class)->request($scope, 'cancel', 'operator-a', 'cancel');
        $this->driveNativeRetries($first['runId']);
        $second = app(ScopeRecovery::class)->request($scope, 'cancel', 'operator-a', 'cancel');
        $this->assertNotSame($first['runId'], $second['runId']);
        $duplicate = app(ScopeRecovery::class)->request($scope, 'cancel', 'operator-a', 'cancel');
        $this->assertSame($second['runId'], $duplicate['runId']);
        $this->driveNativeRetries($second['runId']);
        $this->assertSame('failed', WorkflowRun::query()->findOrFail($second['runId'])->status->value);
        Cache::forget('test:scope:unknown-first');
        $third = app(ScopeRecovery::class)->request($scope, 'cancel', 'operator-a', 'cancel');
        $this->assertNotSame($second['runId'], $third['runId']);
        $this->assertSame('verified', (new ScopeRepository())->inspect($scope)['status']);
        $this->assertSame(
            3,
            WorkflowRun::query()->where('workflow_instance_id', $scope->recoveryInstanceId())->count()
        );
        $this->assertSame(1, \Workflow\V2\Support\ConfiguredV2Models::connection()
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

    public function testDefaultEmbeddedNamespaceIsPreservedWithoutInventingAnAlias(): void
    {
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

    private function completedOrigin(): array
    {
        WorkflowStub::fake();
        $scope = $this->identity();
        $workflow = WorkflowStub::make(TestRetainedScopeWorkflow::class, 'retained-origin', 'client-a');
        $workflow->start($scope->toArray(), 'complete');
        $this->assertTrue($workflow->refresh()->completed());
        return [$scope, $workflow];
    }

    private function identity(): ScopeIdentity
    {
        return new ScopeIdentity('shop', 'client-a', 'order-42', '1');
    }
}
