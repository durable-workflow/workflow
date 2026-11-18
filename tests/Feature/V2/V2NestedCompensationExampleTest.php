<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use DurableWorkflow\Examples\BusinessStateMachines\ExampleOwnershipPolicy;
use DurableWorkflow\Examples\BusinessStateMachines\NestedCompensationExample;
use DurableWorkflow\Examples\BusinessStateMachines\OrderWorkflow;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\Compensation\Recovery\ScopeRecovery;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\ActivityLease;
use Workflow\V2\Support\WorkflowReplayer;
use Workflow\V2\TaskWatchdog;
use Workflow\V2\WorkflowStub;

require_once __DIR__ . '/../../../examples/business-state-machines/bootstrap.php';

final class V2NestedCompensationExampleTest extends TestCase
{
    private const SUBJECT = 'item-aaaaaaaaaaaaaaaa';

    protected function setUp(): void
    {
        parent::setUp();
        self::stopWorkers();
        Queue::fake();
        config([
            'workflows.v2.namespaces' => [ExampleOwnershipPolicy::NAMESPACE],
            'workflows.v2.task_dispatch_mode' => 'queue',
            'queue.default' => 'redis',
        ]);
        foreach (['schema.php', 'provider-schema.php', 'application-schema.php'] as $path) {
            $schema = require __DIR__ . '/../../../examples/business-state-machines/' . $path;
            $schema(DB::connection()->getSchemaBuilder());
        }
        NestedCompensationExample::configure($this->app, DB::connection());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        foreach (['example_machine_bindings', 'example_machine_compensation_log',
            'example_machine_effects', 'example_machine_publications', 'example_machine_decisions',
            'example_machine_subjects'] as $table) {
            DB::connection()->getSchemaBuilder()->dropIfExists($table);
        }
        parent::tearDown();
    }

    public static function integrations(): array
    {
        return [
            'carrier preparation' => ['prepare', ['deliver', 'prepare', 'reserve']],
            'imported fulfilment' => ['imported', ['deliver', 'import']],
        ];
    }

    #[DataProvider('integrations')]
    public function testClosedOrderAndShipmentRecoverOriginalEffectsInChildFirstOrder(
        string $integration,
        array $undoOrder,
    ): void {
        $stub = $this->start($integration);
        $origin = WorkflowRun::query()->findOrFail($stub->runId());
        $child = WorkflowRun::query()->where('id', '!=', $origin->id)->sole();
        $this->assertSame(RunStatus::Waiting, $origin->status);
        $this->assertSame(RunStatus::Completed, $child->status, $this->nativeProgress());
        $this->assertFalse((bool) DB::table('example_machine_bindings')->value('business_outcome_verified'));
        $stub->signal('finish');
        $this->drain();
        $this->assertSame(RunStatus::Completed, $origin->refresh()->status, $this->nativeProgress());
        $this->assertTrue((bool) DB::table('example_machine_bindings')->value('business_outcome_verified'));
        $reservation = json_decode(
            DB::table('example_machine_bindings')->value('reservation'),
            true,
            flags: JSON_THROW_ON_ERROR
        );
        app(NestedCompensationExample::class)->recordAdmission($reservation, $origin->id);
        $this->assertSame(
            'completed',
            DB::table('example_machine_subjects')->whereNull('parent_subject_id')->value('state')
        );
        $this->assertSame(1, DB::table('example_machine_bindings')->count());
        $this->assertSame(2, WorkflowRun::query()->count());
        $originalParentHistory = $this->history($origin->id);
        $originalChildHistory = $this->history($child->id);
        $effectCount = DB::table('example_machine_effects')->count();
        $replayWrites = [];
        DB::listen(static function (\Illuminate\Database\Events\QueryExecuted $query) use (&$replayWrites): void {
            if (str_contains($query->sql, 'example_machine_') && preg_match(
                '/\b(insert|update|delete|replace)\b/i',
                $query->sql
            )) {
                $replayWrites[] = $query->sql;
            }
        });
        app(WorkflowReplayer::class)->replay($origin->fresh());
        $this->assertSame([], $replayWrites);
        $this->assertSame($effectCount, DB::table('example_machine_effects')->count());

        $route = app(NestedCompensationExample::class)->requestCancellation(self::SUBJECT, $origin->id, 'late-cancel');
        $this->assertSame('recovery', $route['route']);
        $this->drain();
        $this->assertVerified($origin->id);
        $this->assertSame(0, DB::table('example_machine_effects')->where('status', 'active')->count());
        $this->assertSame(
            $undoOrder,
            DB::table('example_machine_compensation_log')->orderBy('sequence')->pluck('action')->all()
        );
        $this->assertSame($originalParentHistory, $this->history($origin->id));
        $this->assertSame($originalChildHistory, $this->history($child->id));
        $this->assertSame($effectCount, DB::table('example_machine_effects')->count());
    }

    public function testCancellationWhileOrderWaitsStillCompensatesTheClosedShipmentFirst(): void
    {
        $stub = $this->start('prepare');
        app(NestedCompensationExample::class)->requestCancellation(self::SUBJECT, $stub->runId(), 'cancel-waiting');
        $this->drain();
        $this->assertVerified($stub->runId());
        $this->assertSame(
            ['deliver', 'prepare', 'reserve'],
            DB::table('example_machine_compensation_log')->orderBy('sequence')->pluck('action')->all()
        );
        $this->assertFalse((bool) DB::table('example_machine_bindings')->value('business_outcome_verified'));
    }

    public function testTechnicalShipmentCompletionCannotReplaceMissingBusinessEvidence(): void
    {
        $stub = $this->start('prepare');
        DB::table('example_machine_subjects')->whereNotNull('parent_subject_id')->update([
            'state' => 'working',
        ]);
        $stub->signal('finish');
        $this->drain();
        $this->assertNotSame(RunStatus::Completed, WorkflowRun::query()->findOrFail($stub->runId())->status);
        $this->assertFalse((bool) DB::table('example_machine_bindings')->value('business_outcome_verified'));
        $this->assertGreaterThan(0, DB::table('example_machine_effects')->where('status', 'active')->count());
    }

    public function testCancellationCannotFollowANewerParentGeneration(): void
    {
        $stub = $this->start('prepare');
        try {
            app(NestedCompensationExample::class)->requestCancellation(self::SUBJECT, 'foreign-run', 'wrong-owner');
            $this->fail('The original parent generation is required.');
        } catch (LogicException $error) {
            $this->assertSame('Cancellation cannot follow a newer owner.', $error->getMessage());
            $this->assertSame(0, DB::table('example_machine_subjects')->where('cancellation_requested', true)->count());
        }
        $this->assertSame(RunStatus::Waiting, WorkflowRun::query()->findOrFail($stub->runId())->status);
    }

    public function testCancellationBeforeRootScopeInitializationRemainsUnresolvedUntilRecordedRegistration(): void
    {
        $stub = WorkflowStub::make(
            OrderWorkflow::class,
            ExampleOwnershipPolicy::instanceId(self::SUBJECT),
            ExampleOwnershipPolicy::NAMESPACE
        );
        $stub->start(self::SUBJECT, 'prepare', '1');
        $this->drain(static fn (): bool => DB::table('example_machine_subjects')->exists());
        try {
            app(NestedCompensationExample::class)->requestCancellation(self::SUBJECT, $stub->runId(), 'startup-cancel');
            $this->fail('An absent root scope cannot acknowledge business compensation.');
        } catch (LogicException $error) {
            $this->assertSame('The exact compensation scope generation is unavailable.', $error->getMessage());
        }
        $this->assertTrue((bool) DB::table('example_machine_subjects')->value('cancellation_requested'));
        $this->assertSame(0, DB::table('example_machine_effects')->count());
        $this->assertSame(1, WorkflowRun::query()->count());
        // Let the original owner perform genuine recorded registration; the SQL fence refuses its effect.
        $this->drain();
        $this->assertSame(
            'verified',
            (new ScopeRepository())->inspect(
                ExampleOwnershipPolicy::scope(self::SUBJECT, $stub->runId(), 'parent')
            )['status']
        );
        $this->assertSame(0, DB::table('example_machine_effects')->where('status', 'active')->count());
        $this->assertSame(0, DB::table('example_machine_bindings')->count());
    }

    public function testTechnicalClosureBeforeRootRegistrationCannotManufactureVerifiedCompensation(): void
    {
        $stub = WorkflowStub::make(
            OrderWorkflow::class,
            ExampleOwnershipPolicy::instanceId(self::SUBJECT),
            ExampleOwnershipPolicy::NAMESPACE
        );
        $stub->start(self::SUBJECT, 'prepare', '1');
        $this->drain(static fn (): bool => DB::table('example_machine_subjects')->exists());
        $this->assertTrue($stub->terminate('before-scope-registration')->accepted());
        $this->drain();
        try {
            app(NestedCompensationExample::class)->requestCancellation(self::SUBJECT, $stub->runId(), 'closed-startup');
            $this->fail('Technical closure is not a positive root non-admission receipt.');
        } catch (LogicException $error) {
            $this->assertSame('The exact compensation scope generation is unavailable.', $error->getMessage());
        }
        $this->assertSame(RunStatus::Terminated, WorkflowRun::query()->findOrFail($stub->runId())->status);
        $this->assertTrue((bool) DB::table('example_machine_subjects')->value('cancellation_requested'));
        $this->assertSame('received', DB::table('example_machine_subjects')->value('state'));
        $this->assertSame(0, DB::table('example_machine_effects')->count());
        $this->assertSame(0, DB::table('example_machine_bindings')->count());
    }

    public function testCompensationResumesAfterTheWorkerDiesHavingVoidedTheDelivery(): void
    {
        $database = config('database.connections.' . config('database.default'));
        if (! function_exists('posix_kill') || (($database['driver'] ?? null) === 'sqlite'
            && in_array($database['database'] ?? null, [null, '', ':memory:'], true))) {
            $this->markTestSkipped('The worker-restart control needs POSIX signals and a shared database.');
        }
        $stub = $this->start('prepare');
        $stub->signal('finish');
        $this->drain();
        $parentRun = $stub->runId();
        $childScope = ExampleOwnershipPolicy::scope(self::SUBJECT, $parentRun, 'child');
        $route = app(NestedCompensationExample::class)->requestCancellation(self::SUBJECT, $parentRun, 'late-cancel');
        $this->assertSame('recovery', $route['route']);
        $task = $this->drainUntil(
            static fn (WorkflowTask $task): bool => $task->task_type === TaskType::Activity
                && ActivityExecution::query()->whereKey($task->payload['activity_execution_id'] ?? null)
                    ->where('activity_type', 'dw.compensation.compensate-obligation.v1')
                    ->whereIn('workflow_run_id', WorkflowRun::query()->where(
                        'workflow_instance_id',
                        $childScope->recoveryInstanceId()
                    )->select('id'))
                    ->exists()
        );
        $input = tempnam(sys_get_temp_dir(), 'example-crash-input-');
        $report = tempnam(sys_get_temp_dir(), 'example-crash-report-');
        chmod($input, 0600);
        chmod($report, 0600);
        try {
            file_put_contents($input, json_encode([
                'database' => $database,
                'redis' => config('database.redis'),
                'report' => $report,
                'task_id' => $task->id,
            ], JSON_THROW_ON_ERROR));
            $process = new \Symfony\Component\Process\Process([
                PHP_BINARY, 'vendor/bin/phpunit', 'tests/Support/NestedCompensationCrashProcessTest.php',
            ], dirname(__DIR__, 3), [
                'DWF_EXAMPLE_CRASH_INPUT' => $input,
            ], timeout: 60);
            try {
                $process->run();
                $this->fail('The compensation worker must die: ' . $process->getOutput() . $process->getErrorOutput());
            } catch (\Symfony\Component\Process\Exception\ProcessSignaledException $error) {
                $this->assertSame(SIGKILL, $error->getProcess()->getTermSignal());
            }
            $cut = json_decode(file_get_contents($report), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($task->id, $cut['task']);
            $this->assertNotSame(getmypid(), $cut['pid']);
        } finally {
            unlink($input);
            unlink($report);
        }

        // The void committed, but the dead worker never recorded the outcome.
        $this->assertSame(['deliver'], $this->compensationLog());
        $this->assertSame('voided', DB::table('example_machine_effects')->where('action', 'deliver')->value('status'));
        $child = (new ScopeRepository())->inspect($childScope);
        $this->assertSame('requested', $child['status']);
        $this->assertNotSame('verified', $child['obligations'][0]['compensation_status']);

        // Run everything due while the stranded attempt holds its lease. The Order's short
        // retries for the Shipment participant fail, and its last wait outlasts the lease.
        $crashedAt = now();
        $this->drain(pauseBefore: static fn (WorkflowTask $next): bool => $next->available_at !== null
            && $next->available_at->greaterThan($crashedAt->copy()->addSeconds(ActivityLease::seconds())));
        $parentRecovery = WorkflowRun::query()->where(
            'workflow_instance_id',
            ExampleOwnershipPolicy::scope(self::SUBJECT, $parentRun, 'parent')->recoveryInstanceId()
        )->sole();
        $this->assertSame(RunStatus::Waiting, $parentRecovery->status, $this->nativeProgress());
        $this->assertSame(['deliver'], $this->compensationLog());

        // A worker loop after lease expiry reclaims the attempt and reconciles the committed void.
        Carbon::setTestNow($crashedAt->copy()->addSeconds(ActivityLease::seconds() + 1));
        TaskWatchdog::runPass(respectThrottle: false, runIds: [$task->workflow_run_id]);
        $this->drain();
        $this->assertSame(2, ActivityAttempt::query()
            ->where('activity_execution_id', $task->payload['activity_execution_id'])
            ->count());

        // The Order settles without operator action, child-first and once per effect.
        $this->assertVerified($parentRun);
        $this->assertSame(RunStatus::Completed, $parentRecovery->refresh()->status);
        $this->assertSame(['deliver', 'prepare', 'reserve'], $this->compensationLog());
        $this->assertSame(0, DB::table('example_machine_effects')->where('status', 'active')->count());
        $this->assertSame([
            'recoveryStatus' => 'completed',
            'scopeStatus' => 'verified',
            'automaticRecovery' => 'routed',
            'nextAction' => 'none',
            'unresolved' => [],
        ], app(NestedCompensationExample::class)->cancellationOutcome(self::SUBJECT, $parentRun));
    }

    public function testDispatchedShipmentFailsCompensationVisiblyUntilTheOperatorResubmits(): void
    {
        $stub = $this->start('prepare');
        $stub->signal('finish');
        $this->drain();
        $parentRun = $stub->runId();
        $example = app(NestedCompensationExample::class);
        $example->recordCarrierHandoff(self::SUBJECT);

        $route = $example->requestCancellation(self::SUBJECT, $parentRun, 'late-cancel');
        $this->assertSame('recovery', $route['route']);
        $this->drain();

        // Native retries ran out. The failure is terminal for this request and stays visible.
        $outcome = $example->cancellationOutcome(self::SUBJECT, $parentRun);
        $this->assertSame([
            'recoveryStatus' => 'failed',
            'scopeStatus' => 'requested',
            'nextAction' => 'submit_fresh_authorized_request',
        ], array_intersect_key($outcome, array_flip(['recoveryStatus', 'scopeStatus', 'nextAction'])));
        $this->assertContains([
            'role' => 'child',
            'key' => 'deliver',
            'status' => 'unknown',
            'reason' => 'effect_dispatched',
        ], $outcome['unresolved']);
        // Earlier Order work waits behind the unresolved Shipment.
        $this->assertSame([], $this->compensationLog());
        $this->assertSame(
            [
                'deliver' => 'dispatched',
                'prepare' => 'active',
                'reserve' => 'active',
            ],
            DB::table('example_machine_effects')->orderBy('action')->pluck('status', 'action')->all()
        );
        $childRecovery = WorkflowRun::query()->where(
            'workflow_instance_id',
            ExampleOwnershipPolicy::scope(self::SUBJECT, $parentRun, 'child')->recoveryInstanceId()
        )->sole();
        $this->assertSame(RunStatus::Failed, $childRecovery->status);
        $this->assertSame(6, ActivityAttempt::query()->where('workflow_run_id', $childRecovery->id)
            ->whereIn('activity_execution_id', ActivityExecution::query()
                ->where('workflow_run_id', $childRecovery->id)
                ->where('activity_type', 'dw.compensation.compensate-obligation.v1')
                ->select('id'))
            ->count());
        // Repeating the request returns the same failed receipt; it starts nothing.
        $runs = WorkflowRun::query()->count();
        $this->assertSame($outcome, $example->cancellationOutcome(self::SUBJECT, $parentRun));
        $this->assertSame($runs, WorkflowRun::query()->count());

        // The carrier returns the parcel; the operator submits fresh authorized requests.
        $example->recordCarrierReturn(self::SUBJECT);
        $example->resubmitCancellation(self::SUBJECT, $parentRun, 'late-cancel-after-return');
        $this->drain();
        $this->assertVerified($parentRun);
        $this->assertSame(['deliver', 'prepare', 'reserve'], $this->compensationLog());
        $this->assertSame([
            'recoveryStatus' => 'completed',
            'scopeStatus' => 'verified',
            'automaticRecovery' => 'routed',
            'nextAction' => 'none',
            'unresolved' => [],
        ], $example->cancellationOutcome(self::SUBJECT, $parentRun));
    }

    public function testOrderThatClosesUnsettledRecoversAutomaticallyOnTheNextWatchdogPass(): void
    {
        [$stub, $parentRun] = $this->closeOrderWithDispatchedDelivery();
        $this->assertSame('pending', $this->outcome($parentRun)['automaticRecovery']);
        $this->assertSame('await_automatic_recovery', $this->outcome($parentRun)['nextAction']);

        // The carrier returns the parcel. No operator request follows the Order's closure.
        app(NestedCompensationExample::class)->recordCarrierReturn(self::SUBJECT);
        $report = TaskWatchdog::runPass(respectThrottle: false);
        $this->assertSame(1, $report['compensation_recoveries_started'], json_encode($report));
        $this->drain();

        $this->assertVerified($parentRun);
        $this->assertSame(['deliver', 'prepare', 'reserve'], $this->compensationLog());
        $this->assertSame(1, $this->parentRecoveryRuns($parentRun));
        $this->assertSame([
            'recoveryStatus' => 'completed',
            'scopeStatus' => 'verified',
            'automaticRecovery' => 'routed',
            'nextAction' => 'none',
            'unresolved' => [],
        ], $this->outcome($parentRun));
        $this->assertSame(RunStatus::Failed, WorkflowRun::query()->findOrFail($stub->runId())->status);
    }

    public function testRefusedAutomaticRecoveryStartsNothingAndLeavesManualResubmission(): void
    {
        [, $parentRun] = $this->closeOrderWithDispatchedDelivery();
        $example = app(NestedCompensationExample::class);
        $example->recordCarrierReturn(self::SUBJECT);
        $policy = app(\Workflow\V2\Compensation\Security\ScopeAuthorization::class);
        $this->app->instance(
            \Workflow\V2\Compensation\Security\ScopeAuthorization::class,
            new class($policy) implements \Workflow\V2\Compensation\Security\ScopeAuthorization {
                public function __construct(
                    private readonly \Workflow\V2\Compensation\Security\ScopeAuthorization $policy
                ) {
                }

                public function assertAllowed(
                    \Workflow\V2\Compensation\Definition\ScopeIdentity $scope,
                    string $principal,
                    string $operation
                ): string {
                    if ($operation === 'request') {
                        throw new LogicException('The cancellation principal was revoked.');
                    }
                    return $this->policy->assertAllowed($scope, $principal, $operation);
                }
            },
        );

        foreach ([1, 2] as $pass) {
            $report = TaskWatchdog::runPass(respectThrottle: false);
            $this->assertSame(0, $report['compensation_recoveries_started']);
            $this->assertSame($pass === 1 ? 1 : 0, $report['compensation_recovery_refusals']);
        }
        $this->assertSame(0, $this->parentRecoveryRuns($parentRun));
        $outcome = $this->outcome($parentRun);
        $this->assertSame('recovery_authorization_failed', $outcome['automaticRecovery']);
        $this->assertSame('submit_fresh_authorized_request', $outcome['nextAction']);
        $described = app(ScopeRecovery::class)->describe(
            ExampleOwnershipPolicy::scope(self::SUBJECT, $parentRun, 'parent'),
            NestedCompensationExample::PRINCIPAL,
        );
        $this->assertSame('The cancellation principal was revoked.', $described['refusal']['reason']);
        $this->assertSame(1, collect((new ScopeRepository())->journal(
            ExampleOwnershipPolicy::scope(self::SUBJECT, $parentRun, 'parent')
        ))->where('kind', 'recovery_authorization_failed')
            ->count());

        // Once authority is restored, the operator resubmits manually.
        $this->app->instance(\Workflow\V2\Compensation\Security\ScopeAuthorization::class, $policy);
        $example->resubmitCancellation(self::SUBJECT, $parentRun, 'after-authorization-restored');
        $this->drain();
        $this->assertVerified($parentRun);
        $this->assertSame(['deliver', 'prepare', 'reserve'], $this->compensationLog());
        $this->assertSame(1, $this->parentRecoveryRuns($parentRun));
    }

    public function testAutomaticRecoveryRacingAManualRequestStartsOneRecoveryRun(): void
    {
        [, $parentRun] = $this->closeOrderWithDispatchedDelivery();
        $example = app(NestedCompensationExample::class);
        $example->recordCarrierReturn(self::SUBJECT);
        $armed = true;
        // The manual request lands after the pass selected its candidate and before it routes.
        DB::listen(static function (\Illuminate\Database\Events\QueryExecuted $query) use (
            &$armed,
            $example,
            $parentRun
        ): void {
            if (! $armed || ! str_contains($query->sql, 'workflow_compensation_requests')
                || ! str_contains(strtolower($query->sql), 'order by') || $query->connection->transactionLevel() > 1) {
                return;
            }
            $armed = false;
            DB::afterCommit(static fn () => $example->resubmitCancellation(self::SUBJECT, $parentRun, 'manual-race'));
        });
        $first = TaskWatchdog::runPass(respectThrottle: false);
        $this->assertFalse($armed, 'The race must interleave with the automatic pass.');
        $second = TaskWatchdog::runPass(respectThrottle: false);
        $this->assertSame(0, $second['compensation_recovery_candidates']);
        $this->assertSame([], $first['compensation_recovery_failures']);
        $this->drain();
        $this->assertVerified($parentRun);
        $this->assertSame(1, $this->parentRecoveryRuns($parentRun));
        $this->assertSame(['deliver', 'prepare', 'reserve'], $this->compensationLog());
    }

    public function testWakingAWaitingRetryRunsOneAttemptWithinTheRecordedPolicy(): void
    {
        $stub = $this->start('prepare');
        $stub->signal('finish');
        $this->drain();
        $parentRun = $stub->runId();
        $example = app(NestedCompensationExample::class);
        $example->recordCarrierHandoff(self::SUBJECT);
        $example->requestCancellation(self::SUBJECT, $parentRun, 'late-cancel');
        $childScope = ExampleOwnershipPolicy::scope(self::SUBJECT, $parentRun, 'child');
        $started = now();
        $this->drain(pauseBefore: static fn (WorkflowTask $next): bool => $next->available_at !== null
            && $next->available_at->greaterThan($started->copy()->addMinutes(2)));
        $execution = ActivityExecution::query()
            ->whereIn('workflow_run_id', WorkflowRun::query()->where(
                'workflow_instance_id',
                $childScope->recoveryInstanceId()
            )->select('id'))
            ->where('activity_type', 'dw.compensation.compensate-obligation.v1')
            ->sole();
        $this->assertSame(6, $execution->retry_policy['max_attempts']);
        $this->assertSame(5, ActivityAttempt::query()->where('activity_execution_id', $execution->id)->count());

        // Two wakes before any worker runs still release only the one waiting retry.
        \Workflow\V2\Compensation\Recovery\CompensationWake::scope($childScope);
        \Workflow\V2\Compensation\Recovery\CompensationWake::scope($childScope);
        $this->drain(pauseBefore: static fn (WorkflowTask $next): bool => $next->available_at !== null
            && $next->available_at->greaterThan(now()->addMinutes(2)));
        $this->assertSame(
            [1, 2, 3, 4, 5, 6],
            ActivityAttempt::query()->where('activity_execution_id', $execution->id)
                ->orderBy('attempt_number')
                ->pluck('attempt_number')
                ->all()
        );
        // The recorded maximum holds: a later wake finds nothing to retry.
        \Workflow\V2\Compensation\Recovery\CompensationWake::scope($childScope);
        $this->drain();
        $this->assertSame(6, ActivityAttempt::query()->where('activity_execution_id', $execution->id)->count());
        $this->assertSame(RunStatus::Failed, WorkflowRun::query()->findOrFail($execution->workflow_run_id)->status);
    }

    public static function foreignNativeOwners(): array
    {
        return [
            'foreign instance' => ['foreign-instance', ExampleOwnershipPolicy::NAMESPACE],
            'foreign namespace' => [ExampleOwnershipPolicy::instanceId(self::SUBJECT), 'other-example'],
        ];
    }

    #[DataProvider('foreignNativeOwners')]
    public function testForeignNativeOwnerRefusesBeforeApplicationWrites(string $instance, string $namespace): void
    {
        config([
            'workflows.v2.namespaces' => [ExampleOwnershipPolicy::NAMESPACE, 'other-example'],
        ]);
        $stub = WorkflowStub::make(OrderWorkflow::class, $instance, $namespace);
        $stub->start(self::SUBJECT, 'prepare', '1');
        $this->drain();
        $this->assertSame(RunStatus::Failed, WorkflowRun::query()->findOrFail($stub->runId())->status);
        foreach (['example_machine_subjects', 'example_machine_bindings', 'example_machine_effects'] as $table) {
            $this->assertSame(0, DB::table($table)->count());
        }
    }

    /**
     * Cancel while the Order still waits; its in-origin compensation fails fast because the
     * carrier holds the parcel, so the Order closes with its scope still requested.
     *
     * @return array{WorkflowStub, string}
     */
    private function closeOrderWithDispatchedDelivery(): array
    {
        $stub = $this->start('prepare');
        $parentRun = $stub->runId();
        app(NestedCompensationExample::class)->recordCarrierHandoff(self::SUBJECT);
        $route = app(NestedCompensationExample::class)->requestCancellation(self::SUBJECT, $parentRun, 'cancel-open');
        $this->assertSame('origin', $route['route']);
        $crossed = now();
        // Stop before the Shipment recovery's long wait; that wait is for a crashed attempt.
        $this->drain(pauseBefore: static fn (WorkflowTask $next): bool => $next->available_at !== null
            && $next->available_at->greaterThan($crossed->copy()->addMinutes(2)));
        $this->assertSame(
            RunStatus::Failed,
            WorkflowRun::query()->findOrFail($parentRun)->status,
            $this->nativeProgress()
        );
        $this->assertSame(
            'requested',
            (new ScopeRepository())->inspect(
                ExampleOwnershipPolicy::scope(self::SUBJECT, $parentRun, 'parent')
            )['status']
        );
        $this->assertSame([], $this->compensationLog());
        $this->assertSame(0, $this->parentRecoveryRuns($parentRun));
        return [$stub, $parentRun];
    }

    private function outcome(string $parentRun): array
    {
        return app(NestedCompensationExample::class)->cancellationOutcome(self::SUBJECT, $parentRun);
    }

    private function parentRecoveryRuns(string $parentRun): int
    {
        return WorkflowRun::query()->where(
            'workflow_instance_id',
            ExampleOwnershipPolicy::scope(self::SUBJECT, $parentRun, 'parent')->recoveryInstanceId()
        )->count();
    }

    private function start(string $integration): WorkflowStub
    {
        $stub = WorkflowStub::make(
            OrderWorkflow::class,
            ExampleOwnershipPolicy::instanceId(self::SUBJECT),
            ExampleOwnershipPolicy::NAMESPACE
        );
        $stub->start(self::SUBJECT, $integration, '1');
        $this->drain();
        $this->assertSame(RunStatus::Waiting, WorkflowRun::query()->findOrFail($stub->runId())->status, json_encode(
            \Workflow\V2\Models\WorkflowFailure::query()->where('workflow_run_id', $stub->runId())->pluck(
                'message'
            )->all(),
            JSON_THROW_ON_ERROR,
        ));
        return $stub;
    }

    private function assertVerified(string $parentRun): void
    {
        foreach (['parent', 'child'] as $role) {
            $this->assertSame(
                'verified',
                (new ScopeRepository())->inspect(
                    ExampleOwnershipPolicy::scope(self::SUBJECT, $parentRun, $role)
                )['status']
            );
        }
    }

    private function history(string $run): array
    {
        return WorkflowHistoryEvent::query()->where('workflow_run_id', $run)->orderBy('sequence')
            ->get()
            ->map(static fn (WorkflowHistoryEvent $event): array => [
                'sequence' => $event->sequence,
                'type' => $event->event_type->value,
                'payload' => $event->payload,
            ])->all();
    }

    private function nativeProgress(): string
    {
        return json_encode([
            'runs' => WorkflowRun::query()->get(['id', 'workflow_instance_id', 'status'])->toArray(),
            'tasks' => WorkflowTask::query()->get(
                ['id', 'workflow_run_id', 'task_type', 'status', 'available_at', 'last_error', 'last_claim_error']
            )->toArray(),
            'activities' => \Workflow\V2\Models\ActivityExecution::query()->get(
                ['id', 'workflow_run_id', 'status', 'attempt_count', 'current_attempt_id']
            )->toArray(),
            'recent_history' => WorkflowHistoryEvent::query()->orderByDesc('id')->limit(14)->get(
                ['workflow_run_id', 'sequence', 'event_type', 'payload']
            )->toArray(),
            'effects' => DB::table('example_machine_effects')->get(['action', 'status'])->all(),
        ], JSON_THROW_ON_ERROR);
    }

    private function compensationLog(): array
    {
        return DB::table('example_machine_compensation_log')->orderBy('sequence')->pluck('action')->all();
    }

    /**
     * Drain until the next ready task matches, and return it without running it.
     */
    private function drainUntil(callable $matches): WorkflowTask
    {
        $found = null;
        $this->drain(pauseBefore: static function (WorkflowTask $task) use ($matches, &$found): bool {
            if ($matches($task)) {
                $found = $task;
                return true;
            }
            return false;
        });
        $this->assertInstanceOf(WorkflowTask::class, $found, $this->nativeProgress());
        return $found;
    }

    private function drain(?callable $stopWhen = null, ?callable $pauseBefore = null): void
    {
        for ($i = 0; $i < 250; ++$i) {
            $task = WorkflowTask::query()->where('status', TaskStatus::Ready)->orderBy('available_at')->orderBy(
                'created_at'
            )->orderBy('id')
                ->first();
            if ($task === null) {
                return;
            }
            if ($pauseBefore !== null && $pauseBefore($task)) {
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
            if ($stopWhen !== null && $stopWhen()) {
                return;
            }
        }
        $this->fail('The bounded native task drain did not settle.');
    }
}
