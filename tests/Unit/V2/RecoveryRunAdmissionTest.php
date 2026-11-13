<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use LogicException;
use Orchestra\Testbench\TestCase;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\V2\TestSignalWorkflow;
use Workflow\Exceptions\NonRetryableException;
use Workflow\Providers\WorkflowServiceProvider;
use Workflow\V2\Compensation\Activities\PrepareRecoveryActivity;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\Recovery\RecoveryRunAdmission;
use Workflow\V2\Compensation\Recovery\RecoveryWorkflow;
use Workflow\V2\Contracts\HistoryProjectionRole;
use Workflow\V2\Contracts\WorkflowControlPlane;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\StartOptions;
use Workflow\V2\Support\WorkflowExecutor;
use Workflow\V2\WorkflowStub;

final class RecoveryRunAdmissionTest extends TestCase
{
    private string $databasePath;

    protected function setUp(): void
    {
        parent::setUp();
        config()
            ->set('queue.default', 'database');
        Queue::fake();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (['', '-wal', '-shm'] as $suffix) {
            if (isset($this->databasePath) && is_file($this->databasePath . $suffix)) {
                unlink($this->databasePath . $suffix);
            }
        }
    }

    #[DataProvider('stops')]
    public function testRestartAndLostAcknowledgementPreserveReceiptsHistoryAndBudgets(string $stop): void
    {
        [$scope, $origin, $stub] = $this->scope();
        $profile = (new StartOptions())->withExecutionTimeout(600)
            ->withRunTimeout(300);
        $initial = new RecoveryRunAdmission($scope, $origin, 'initial', null, $profile);
        $first = $stub->attemptRecoveryStart($initial);
        $firstRun = WorkflowRun::query()->findOrFail($first->runId());
        $oldTask = WorkflowTask::query()->where('workflow_run_id', $firstRun->id)->firstOrFail();
        $this->stop($stub, $stop);
        $oldHistory = $this->history($firstRun->id);
        $logicalStart = WorkflowInstance::query()->findOrFail($stub->id())->started_at;
        $this->travel(30)
            ->seconds();

        $request = new RecoveryRunAdmission($scope, $origin, 'restart', $firstRun->id, $profile);
        $successor = $stub->attemptRecoveryStart($request);
        $next = WorkflowRun::query()->findOrFail($successor->runId());
        self::assertNotSame($firstRun->id, $next->id);
        self::assertSame($firstRun->workflow_instance_id, $next->workflow_instance_id);
        self::assertSame($firstRun->execution_deadline_at->format('c'), $next->execution_deadline_at->format('c'));
        self::assertSame($firstRun->run_deadline_at->format('c'), $next->run_deadline_at->format('c'));
        self::assertSame($logicalStart, WorkflowInstance::query()->findOrFail($stub->id())->started_at);
        self::assertSame($oldHistory, $this->history($firstRun->id));
        self::assertSame($origin, WorkflowCommand::query()->findOrFail($successor->commandId())
            ->context['compensation_recovery']['intent']['binding']['original_run_id']);
        self::assertSame(1, WorkflowLink::query()->where('link_type', 'compensation_recovery_restart')->count());
        self::assertSame(1, WorkflowTask::query()->where('workflow_run_id', $next->id)->count());
        $snapshot = $this->snapshot();
        self::assertSame($successor->commandId(), $stub->attemptRecoveryStart($request)->commandId());
        self::assertSame($snapshot, $this->snapshot());

        // A late dispatch from the stopped run cannot make new progress.
        (new RunWorkflowTask($oldTask->id))->handle(app(WorkflowExecutor::class));
        self::assertSame($oldHistory, $this->history($firstRun->id));
        $this->stop($stub, $stop);
        $snapshot = $this->snapshot();
        self::assertSame($firstRun->id, $stub->attemptRecoveryStart($initial)->runId());
        self::assertSame($next->id, $stub->attemptRecoveryStart($request)->runId());
        self::assertSame($snapshot, $this->snapshot());
        $this->assertRefusesWithoutWrites(static fn () => $stub->attemptRecoveryStart(
            new RecoveryRunAdmission($scope, $origin, 'restart', $next->id, $profile)
        ));
        self::assertNotSame($next->id, $stub->attemptRecoveryStart(
            new RecoveryRunAdmission($scope, $origin, 'new-authorization', $next->id, $profile)
        )->runId());
    }

    public function testCompetingRequestsAdoptOnlyTheSameContentBoundSuccessor(): void
    {
        [$scope, $origin, $stub] = $this->scope();
        $first = $stub->attemptRecoveryStart(new RecoveryRunAdmission($scope, $origin, 'initial', null));
        $initialAdoption = new RecoveryRunAdmission($scope, $origin, 'initial-adopter', null);
        self::assertSame($first->runId(), $stub->attemptRecoveryStart($initialAdoption)->runId());
        $this->stop($stub, 'terminate');
        $next = $stub->attemptRecoveryStart(new RecoveryRunAdmission($scope, $origin, 'restart-a', $first->runId()));
        $other = new RecoveryRunAdmission($scope, $origin, 'restart-b', $first->runId());
        $adopted = WorkflowStub::load($scope->recoveryInstanceId(), $scope->namespace)->attemptRecoveryStart($other);
        self::assertTrue($adopted->returnedExistingActive());
        self::assertSame($next->runId(), $adopted->runId());
        self::assertSame(2, WorkflowRun::query()->where('workflow_instance_id', $scope->recoveryInstanceId())->count());
        self::assertSame(1, WorkflowTask::query()->where('workflow_run_id', $next->runId())->count());
        $this->assertRefusesWithoutWrites(static fn () => $stub->attemptRecoveryStart(
            new RecoveryRunAdmission($scope, $origin, 'wrong-predecessor', null)
        ));
        $this->stop($stub, 'cancel');
        $snapshot = $this->snapshot();
        self::assertSame($next->runId(), $stub->attemptRecoveryStart($other)->runId());
        self::assertSame($first->runId(), $stub->attemptRecoveryStart($initialAdoption)->runId());
        self::assertSame($snapshot, $this->snapshot());
    }

    public function testChangedOwnerProfileScopeOrMissingLineageIsRefused(): void
    {
        [$scope, $origin, $stub] = $this->scope();
        $first = $stub->attemptRecoveryStart(new RecoveryRunAdmission($scope, $origin, 'initial', null));
        $otherOrigin = WorkflowStub::make(TestSignalWorkflow::class, 'other-origin');
        $otherOrigin->start();
        $otherOrigin->terminate();
        $this->assertRefusesWithoutWrites(static fn () => $stub->attemptRecoveryStart(
            new RecoveryRunAdmission($scope, $otherOrigin->runId(), 'initial', null)
        ));
        $this->assertRefusesWithoutWrites(static fn () => $stub->attemptRecoveryStart(
            new RecoveryRunAdmission($scope, $origin, 'initial', null, (new StartOptions())->withRunTimeout(60))
        ));
        $wrongScope = new ScopeIdentity($scope->application, $scope->namespace, $scope->key, 'next-generation');
        $this->assertRefusesWithoutWrites(static fn () => $stub->attemptRecoveryStart(
            new RecoveryRunAdmission($wrongScope, $origin, 'initial', null)
        ));
        $this->stop($stub, 'terminate');
        $next = $stub->attemptRecoveryStart(new RecoveryRunAdmission($scope, $origin, 'restart', $first->runId()));
        WorkflowLink::query()->where('child_workflow_run_id', $next->runId())->delete();
        $this->assertRefusesWithoutWrites(static fn () => $stub->attemptRecoveryStart(
            new RecoveryRunAdmission($scope, $origin, 'adopt', $first->runId())
        ));
    }

    public function testRestartCannotRenewAnExpiredRecoveryBudget(): void
    {
        [$scope, $origin, $stub] = $this->scope();
        $profile = (new StartOptions())->withExecutionTimeout(20)
            ->withRunTimeout(10);
        $first = $stub->attemptRecoveryStart(new RecoveryRunAdmission($scope, $origin, 'initial', null, $profile));
        $this->stop($stub, 'terminate');
        $this->travel(11)
            ->seconds();
        $this->assertRefusesWithoutWrites(static fn () => $stub->attemptRecoveryStart(
            new RecoveryRunAdmission($scope, $origin, 'restart', $first->runId(), $profile)
        ));
    }

    public function testAnOrdinaryStartCannotBeAdoptedAsARecoveryReceipt(): void
    {
        [$scope, $origin, $stub] = $this->scope();
        $ordinary = $stub->start($scope->toArray());
        $this->stop($stub, 'terminate');
        $this->assertRefusesWithoutWrites(static fn () => $stub->attemptRecoveryStart(
            new RecoveryRunAdmission($scope, $origin, 'restart', $ordinary->runId())
        ));
        self::assertTrue(
            $stub->attemptStart(StartOptions::returnExistingActive(), $scope->toArray())->rejectedDuplicate()
        );
    }

    public function testProjectionFailureRollsBackSuccessorReceiptLineageAndTaskTogether(): void
    {
        [$scope, $origin, $stub] = $this->scope();
        $first = $stub->attemptRecoveryStart(new RecoveryRunAdmission($scope, $origin, 'initial', null));
        $this->stop($stub, 'terminate');
        $request = new RecoveryRunAdmission($scope, $origin, 'restart', $first->runId());
        $projector = app(HistoryProjectionRole::class);
        $failedProjection = \Mockery::mock(HistoryProjectionRole::class);
        $failedProjection->shouldReceive('projectRun')
            ->once()
            ->andThrow(new LogicException('Recovery projection failure.'));
        app()
            ->instance(HistoryProjectionRole::class, $failedProjection);
        try {
            $this->assertRefusesWithoutWrites(static fn () => $stub->attemptRecoveryStart($request));
        } finally {
            app()->instance(HistoryProjectionRole::class, $projector);
        }
        $result = $stub->attemptRecoveryStart($request);
        self::assertTrue($result->startedNew());
        self::assertSame(1, WorkflowCommand::query()->where('request_id', $request->token())->count());
        self::assertSame(1, WorkflowLink::query()->where('child_workflow_run_id', $result->runId())->count());
    }

    public function testInitialRecoveryPolicyIsSeparateFromTheClosedForwardDeadline(): void
    {
        $origin = WorkflowStub::make(TestSignalWorkflow::class, 'expired-forward');
        $origin->start((new StartOptions())->withExecutionTimeout(1));
        $origin->terminate();
        $this->travel(2)
            ->seconds();
        $originRun = WorkflowRun::query()->findOrFail($origin->runId());
        self::assertTrue($originRun->execution_deadline_at->isPast());
        $scope = new ScopeIdentity('test-app', $originRun->namespace, 'order', 'generation-1');
        $stub = WorkflowStub::make(RecoveryWorkflow::class, $scope->recoveryInstanceId(), $scope->namespace);
        $admitted = $stub->attemptRecoveryStart(new RecoveryRunAdmission(
            $scope,
            $originRun->id,
            'authorized-initial-recovery',
            null,
            (new StartOptions())->withExecutionTimeout(60),
        ));
        $recovery = WorkflowRun::query()->findOrFail($admitted->runId());
        self::assertTrue($recovery->execution_deadline_at->isFuture());
        self::assertSame(
            $originRun->execution_deadline_at->format('c'),
            $originRun->fresh()
                ->execution_deadline_at->format('c')
        );
    }

    public function testAStoppedNativeRedriveSuccessorKeepsItsOriginalRecoveryBinding(): void
    {
        [$scope, $origin, $stub] = $this->scope();
        WorkflowStub::fake();
        WorkflowStub::mock(PrepareRecoveryActivity::class, static function (): never {
            throw new NonRetryableException('Synthetic preparation refusal.');
        });
        $profile = (new StartOptions())->withExecutionTimeout(600)
            ->withRunTimeout(120);
        $first = $stub->attemptRecoveryStart(new RecoveryRunAdmission($scope, $origin, 'initial', null, $profile));
        self::assertSame(RunStatus::Failed, WorkflowRun::query()->findOrFail($first->runId())->status);
        // The public activity fake executes inline. Keep that real failed
        // history, then restore queued dispatch so the redrive can be stopped.
        app()
            ->forgetInstance('workflow.v2.mocks');
        app()
            ->forgetInstance('workflow.v2.dispatched');
        self::assertFalse(WorkflowStub::faked());
        $redrive = app(WorkflowControlPlane::class)->redrive($scope->recoveryInstanceId(), $first->runId(), [
            'namespace' => $scope->namespace,
            'request_id' => 'technical-redrive-request',
        ]);
        self::assertTrue($redrive['accepted'], (string) $redrive['reason']);
        $redriven = WorkflowRun::query()->findOrFail($redrive['workflow_run_id']);
        $adoption = new RecoveryRunAdmission($scope, $origin, 'adopt-redrive', $first->runId(), $profile);
        $adopted = $stub->attemptRecoveryStart($adoption);
        self::assertSame($redriven->id, $adopted->runId());
        self::assertSame($adopted->commandId(), $stub->attemptRecoveryStart($adoption)->commandId());
        $stub->refresh()
            ->terminate();
        $history = $this->history($redriven->id);
        $this->travel(10)
            ->seconds();
        $next = $stub->attemptRecoveryStart(new RecoveryRunAdmission(
            $scope,
            $origin,
            'restart-redriven-recovery',
            $redriven->id,
            $profile,
        ));
        $successor = WorkflowRun::query()->findOrFail($next->runId());
        self::assertSame($successor->id, $stub->refresh()->runId());
        self::assertSame($redriven->execution_deadline_at->format('c'), $successor->execution_deadline_at->format('c'));
        self::assertSame($redriven->run_deadline_at->format('c'), $successor->run_deadline_at->format('c'));
        self::assertSame($history, $this->history($redriven->id));
        self::assertSame($redriven->id, $stub->attemptRecoveryStart($adoption)->runId());
    }

    public function testCanonicalSqliteWriterIsHeldBeforeReceiptOrPredecessorReads(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('This control observes the SQLite writer lock.');
        }
        [$scope, $origin, $stub] = $this->scope();
        DB::statement('CREATE TABLE recovery_writer_probe (id INTEGER PRIMARY KEY, value INTEGER NOT NULL)');
        DB::insert('INSERT INTO recovery_writer_probe VALUES (1, 0)');
        $writer = new PDO('sqlite:' . DB::connection()->getDatabaseName());
        $writer->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $writer->exec('PRAGMA busy_timeout=0');
        $observed = 0;
        $blocked = false;
        DB::listen(static function (QueryExecuted $query) use ($writer, &$observed, &$blocked): void {
            if ($observed !== 0 || ! str_starts_with(strtolower(ltrim($query->sql)), 'select')
                || ! str_contains($query->sql, 'workflow_instances')) {
                return;
            }
            ++$observed;
            try {
                $writer->exec('UPDATE recovery_writer_probe SET value=value+1 WHERE id=1');
            } catch (PDOException $error) {
                if (! str_contains(strtolower($error->getMessage()), 'locked')) {
                    throw $error;
                }
                $blocked = true;
            }
        });
        $result = $stub->attemptRecoveryStart(new RecoveryRunAdmission($scope, $origin, 'initial', null));
        self::assertTrue($result->startedNew());
        self::assertSame(1, $observed);
        self::assertTrue($blocked);
        self::assertSame(0, (int) DB::selectOne('SELECT value FROM recovery_writer_probe WHERE id=1')->value);
        self::assertSame(1, $writer->exec('UPDATE recovery_writer_probe SET value=value+1 WHERE id=1'));
    }

    public function testAChangedCreatorBindingCannotBeAuthorizedByItsOldFingerprint(): void
    {
        [$scope, $origin, $stub] = $this->scope();
        $first = $stub->attemptRecoveryStart(new RecoveryRunAdmission($scope, $origin, 'initial', null));
        $this->stop($stub, 'terminate');
        $command = WorkflowCommand::query()->findOrFail($first->commandId());
        $context = $command->context;
        $context['compensation_recovery']['intent']['binding']['scope']['generation'] = 'different-generation';
        $command->forceFill([
            'context' => $context,
        ])->save();
        $this->assertRefusesWithoutWrites(static fn () => $stub->attemptRecoveryStart(
            new RecoveryRunAdmission($scope, $origin, 'restart', $first->runId())
        ));
    }

    public static function stops(): array
    {
        return [['cancel'], ['terminate']];
    }

    protected function getPackageProviders($app): array
    {
        return [WorkflowServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $this->databasePath = tempnam(sys_get_temp_dir(), 'recovery-admission-');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => $this->databasePath,
            'prefix' => '',
            'foreign_key_constraints' => true,
            'journal_mode' => 'WAL',
            'busy_timeout' => 20,
        ]);
        $app['config']->set('queue.default', 'database');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('workflows.v1.enabled', false);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->artisan('migrate', [
            '--database' => 'testing',
            '--force' => true,
        ])->assertExitCode(0);
    }

    private function scope(): array
    {
        $origin = WorkflowStub::make(TestSignalWorkflow::class, 'forward-origin');
        $origin->start();
        $origin->terminate();
        $scope = new ScopeIdentity('test-app', WorkflowRun::query()->findOrFail(
            $origin->runId()
        )->namespace, 'order', 'generation-1');
        return [
            $scope,
            $origin->runId(),
            WorkflowStub::make(RecoveryWorkflow::class, $scope->recoveryInstanceId(), $scope->namespace),
        ];
    }

    private function stop(WorkflowStub $stub, string $stop): void
    {
        $stop === 'cancel' ? $stub->cancel() : $stub->terminate();
    }

    private function history(string $runId): array
    {
        return WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)->orderBy('sequence')->get()->toArray();
    }

    private function snapshot(): array
    {
        return array_map(static fn (string $model): array => $model::query()->orderBy('id')->get()->toArray(), [
            WorkflowInstance::class, WorkflowRun::class, WorkflowCommand::class, WorkflowHistoryEvent::class,
            WorkflowTask::class, WorkflowLink::class, WorkflowRunSummary::class,
        ]);
    }

    private function assertRefusesWithoutWrites(callable $call): void
    {
        $before = $this->snapshot();
        try {
            $call();
            self::fail('Expected recovery admission refusal.');
        } catch (LogicException $error) {
            self::assertStringContainsString('recovery', strtolower($error->getMessage()));
        }
        self::assertSame($before, $this->snapshot());
    }
}
