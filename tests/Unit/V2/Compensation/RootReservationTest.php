<?php

declare(strict_types=1);

namespace Tests\Unit\V2\Compensation;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use LogicException;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\TestCase;
use Workflow\V2\Compensation\Admission\NeverAdmittedRoot;
use Workflow\V2\Compensation\Admission\RootAuthorization;
use Workflow\V2\Compensation\Admission\RootBinding;
use Workflow\V2\Compensation\Admission\RootIdentity;
use Workflow\V2\Compensation\Admission\RootReservation;
use Workflow\V2\Compensation\Admission\RootReservationStore;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\Compensation\Persistence\ScopeStorage;
use Workflow\V2\Compensation\Security\ScopeAuthorization;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\WorkflowStub;

final class RootReservationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function testReservationRetainsOneOriginalRunWithoutOpeningAnEmptyScope(): void
    {
        $identity = $this->identity();
        $reservation = $this->store()
            ->reserve($identity, 'owner');
        $again = $this->store()
            ->reserve($identity, 'owner');
        $this->assertSame($reservation->toArray(), $again->toArray());
        $this->assertSame(1, WorkflowInstance::query()->count());
        $this->assertSame(0, WorkflowRun::query()->count());
        $this->assertSame('root_reserved', DB::table(ScopeStorage::SCOPES)->value('status'));
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('unavailable');
        (new ScopeRepository())->inspect($identity->scope);
    }

    public function testClosureBeforeAdmissionIsPermanentAndCannotBecomeAVerifiedEmptyScope(): void
    {
        $reservation = $this->store()
            ->reserve($this->identity(), 'owner');
        $closed = $this->store()
            ->close($reservation, 'owner');
        $this->assertInstanceOf(NeverAdmittedRoot::class, $closed);
        $this->assertSame($closed->closureId, $this->store()->close($reservation, 'owner')->closureId);
        $this->assertSame($closed->closureId, $this->store()->start($reservation, 'owner', ['Taylor'])->closureId);
        $this->assertSame('root_never_admitted', DB::table(ScopeStorage::SCOPES)->value('status'));
        $this->assertSame(0, WorkflowRun::query()->count());
        $this->assertSame(0, WorkflowCommand::query()->count());
        $this->assertSame(0, WorkflowTask::query()->count());
        foreach (['open', 'request', 'inspect'] as $operation) {
            try {
                $repository = new ScopeRepository();
                match ($operation) {
                    'open' => $repository->open($reservation->identity->scope, $reservation->originalRunId),
                    'request' => $repository->request($reservation->identity->scope, 'cancel', 'owner', 'cancelled'),
                    'inspect' => $repository->inspect($reservation->identity->scope),
                };
                $this->fail('An unused root fence became an ordinary compensation ledger.');
            } catch (LogicException $error) {
                $this->assertStringContainsString('unavailable', $error->getMessage());
            }
        }
    }

    public function testAdmissionAtomicallyOpensTheGenuineScopeAndReturnsItsOriginalRunAfterClosure(): void
    {
        $reservation = $this->store()
            ->reserve($this->identity(), 'owner');
        $binding = $this->store()
            ->start($reservation, 'owner', ['Taylor']);
        $this->assertInstanceOf(RootBinding::class, $binding);
        $this->assertSame($binding->toArray(), $this->store()->close($reservation, 'owner')->toArray());
        $this->assertSame($binding->toArray(), $this->store()->start($reservation, 'owner', ['Taylor'])->toArray());
        $run = WorkflowRun::query()->sole();
        $this->assertSame($reservation->originalRunId, $run->id);
        $this->assertSame($reservation->identity->instanceId(), $run->workflow_instance_id);
        $this->assertSame('database', $run->connection);
        $this->assertSame('root-test', $run->queue);
        $scope = (new ScopeRepository())->metadata($reservation->identity->scope);
        $this->assertSame($run->id, $scope['original_run_id']);
        $this->assertSame('open', $scope['status']);
        $this->assertSame(1, WorkflowCommand::query()->count());
        $command = WorkflowCommand::query()->findOrFail($binding->commandId);
        $this->assertSame(
            $reservation->fingerprint(),
            $command->context['root_scope_admission']['reservationFingerprint']
        );
        $this->assertSame(1, WorkflowTask::query()->count());
        $started = WorkflowHistoryEvent::query()->where('event_type', 'WorkflowStarted')->sole();
        $this->assertSame($command->context['root_scope_admission'], $started->payload['root_scope_admission']);
        // The workflow's first ordinary register can adopt only this genuine original owner.
        (new ScopeRepository())->open($reservation->identity->scope, $run->id);
        $this->assertSame(['root_reserved', 'opened'], DB::table(ScopeStorage::JOURNAL)->orderBy('sequence')
            ->pluck('kind')
            ->all());
    }

    public function testFailureAfterScopeOpeningRollsBackRunHistoryReceiptAndInitialTaskTogether(): void
    {
        $reservation = $this->store()
            ->reserve($this->identity(), 'owner');
        $dispatcher = WorkflowTask::getEventDispatcher();
        WorkflowTask::setEventDispatcher(clone $dispatcher);
        WorkflowTask::creating(static function (): void {
            throw new LogicException('injected_initial_task_failure');
        });
        try {
            try {
                $this->store()
                    ->start($reservation, 'owner', ['Taylor']);
                $this->fail('The injected late admission failure was lost.');
            } catch (LogicException $error) {
                $this->assertSame('injected_initial_task_failure', $error->getMessage());
            }
        } finally {
            WorkflowTask::setEventDispatcher($dispatcher);
        }
        $this->assertSame(0, WorkflowRun::query()->count());
        $this->assertSame(0, WorkflowCommand::query()->count());
        $this->assertSame(0, WorkflowHistoryEvent::query()->count());
        $this->assertSame(0, WorkflowTask::query()->count());
        $this->assertSame('root_reserved', DB::table(ScopeStorage::SCOPES)->value('status'));
        $this->assertSame(['root_reserved'], DB::table(ScopeStorage::JOURNAL)->pluck('kind')->all());
        $this->assertInstanceOf(NeverAdmittedRoot::class, $this->store()->close($reservation, 'owner'));
    }

    public function testOrdinaryStartAndSignalWithStartCannotBypassAReservation(): void
    {
        $reservation = $this->store()
            ->reserve($this->identity(), 'owner');
        $stub = WorkflowStub::load($reservation->identity->instanceId(), 'tenant-a');
        foreach (['start', 'signal'] as $operation) {
            try {
                $operation === 'start' ? $stub->attemptStart('Taylor') : $stub->attemptSignalWithStart('finish');
                $this->fail('An ordinary entrypoint bypassed the original root fence.');
            } catch (LogicException $error) {
                $this->assertStringContainsString('original atomic admission', $error->getMessage());
            }
        }
        $this->assertSame(0, WorkflowRun::query()->count());
    }

    public function testWrongInputAndSubstitutedOriginalRunCannotStartOrCloseTheRoot(): void
    {
        $reservation = $this->store()
            ->reserve($this->identity(), 'owner');
        try {
            $this->store()
                ->start($reservation, 'owner', ['foreign']);
            $this->fail('Substituted root input was admitted.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('input differs', $error->getMessage());
        }
        $substituted = new RootReservation(
            $reservation->identity,
            'foreign-run',
            $reservation->authorizationVersion,
            $reservation->scopeAuthorizationVersion
        );
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('original reservation');
        $this->store()
            ->close($substituted, 'owner');
    }

    public function testHostRefusalPrecedesAllProtectedQueries(): void
    {
        $identity = $this->identity();
        $queries = [];
        DB::listen(static function ($event) use (&$queries): void {
            $queries[] = $event->sql;
        });
        try {
            $this->store()
                ->reserve($identity, 'foreign');
            $this->fail('A foreign principal obtained a root reservation.');
        } catch (LogicException $error) {
            $this->assertSame('root_access_refused', $error->getMessage());
        }
        $this->assertSame([], $queries);
    }

    public function testAuthorizationCannotRunInsideACallersTransaction(): void
    {
        $identity = $this->identity();
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('unlocked host');
        DB::transaction(fn () => $this->store()->reserve($identity, 'owner'));
    }

    public function testForeignOwnerIsCheckedBeforeProtectedJsonIsDecoded(): void
    {
        $identity = $this->identity();
        $this->store()
            ->reserve($identity, 'owner');
        WorkflowInstance::query()->whereKey($identity->instanceId())->toBase()->update([
            'namespace' => 'foreign',
            'native_root_reservation' => '{invalid-json',
        ]);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('original owner');
        $this->store()
            ->load($identity, 'owner');
    }

    public function testHistoricalDefinitionCanBeLoadedAndClosedWithoutAutoloadingIt(): void
    {
        $reservation = $this->store()
            ->reserve($this->identity(), 'owner');
        $value = $reservation->identity->toArray();
        $value['workflowClass'] = 'Tests\\UnavailableHistoricalRoot';
        $historical = new RootReservation(
            RootIdentity::fromArray($value),
            $reservation->originalRunId,
            $reservation->authorizationVersion,
            $reservation->scopeAuthorizationVersion
        );
        $root = WorkflowInstance::query()->findOrFail($value = $reservation->identity->instanceId());
        $state = json_decode($root->getRawOriginal('native_root_reservation'), true, flags: JSON_THROW_ON_ERROR);
        $state['reservation'] = $historical->toArray();
        WorkflowInstance::query()->whereKey($value)->toBase()->update([
            'workflow_class' => $historical->identity->workflowClass,
            'native_root_reservation' => json_encode($state, JSON_THROW_ON_ERROR),
        ]);
        $autoloaded = [];
        $loader = static function (string $class) use (&$autoloaded): void {
            $autoloaded[] = $class;
        };
        spl_autoload_register($loader);
        try {
            $loaded = $this->store()
                ->load($historical->identity, 'owner');
            $this->assertSame($historical->toArray(), $loaded->toArray());
            $this->assertInstanceOf(NeverAdmittedRoot::class, $this->store()->close($loaded, 'owner'));
        } finally {
            spl_autoload_unregister($loader);
        }
        $this->assertNotContains($historical->identity->workflowClass, $autoloaded);
    }

    public function testExistingOrdinaryScopeCannotBeReinterpretedAsANeverAdmittedRoot(): void
    {
        $identity = $this->identity();
        (new ScopeRepository())->open($identity->scope, 'original-ordinary-run');
        try {
            $this->store()
                ->reserve($identity, 'owner');
            $this->fail('An existing ordinary scope was reinterpreted as an unused root.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('existing scope', $error->getMessage());
        }
        $this->assertSame(0, WorkflowInstance::query()->count());
        $this->assertSame('original-ordinary-run', DB::table(ScopeStorage::SCOPES)->value('original_run_id'));
    }

    public function testLostScopeOrUncertainNativeRunNeverProducesANonAdmissionReceipt(): void
    {
        $reservation = $this->store()
            ->reserve($this->identity(), 'owner');
        DB::table(ScopeStorage::SCOPES)->delete();
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('scope fence is unavailable');
        $this->store()
            ->close($reservation, 'owner');
    }

    public function testAdmissionReceiptSurvivesOrdinaryHistoryPruningWithoutCreatingAnotherRun(): void
    {
        $reservation = $this->store()
            ->reserve($this->identity(), 'owner');
        $binding = $this->store()
            ->start($reservation, 'owner', ['Taylor']);
        $run = WorkflowRun::query()->findOrFail($reservation->originalRunId);
        $run->forceFill([
            'status' => \Workflow\V2\Enums\RunStatus::Completed,
            'closed_at' => now(),
        ])->save();
        \Workflow\V2\Support\WorkflowRunRetentionCleanup::pruneRun($run);
        $this->assertSame(0, WorkflowHistoryEvent::query()->count());
        $this->assertSame(0, WorkflowCommand::query()->count());
        $this->assertSame($binding->toArray(), $this->store()->close($reservation, 'owner')->toArray());
        $this->assertSame($binding->toArray(), $this->store()->start($reservation, 'owner', ['Taylor'])->toArray());
        $this->assertSame(1, WorkflowRun::query()->count());
        $this->assertSame(0, WorkflowTask::query()->count());
    }

    public function testUncertainNativePointersCannotBeCertifiedNeverAdmitted(): void
    {
        $reservation = $this->store()
            ->reserve($this->identity(), 'owner');
        WorkflowInstance::query()->whereKey($reservation->identity->instanceId())->update([
            'run_count' => 1,
        ]);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('uncertain root');
        $this->store()
            ->close($reservation, 'owner');
    }

    public function testChangingPinnedPolicyVersionsCannotReplaceTheOriginalAdmissionAuthority(): void
    {
        $reservation = $this->store()
            ->reserve($this->identity(), 'owner');
        $forged = new RootReservation(
            $reservation->identity,
            $reservation->originalRunId,
            'different-joint-policy',
            $reservation->scopeAuthorizationVersion
        );
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('policies must remain pinned');
        $this->store()
            ->start($forged, 'owner', ['Taylor']);
    }

    public function testScalarInputTypesAndUnknownDescriptorFieldsCannotBeNormalizedIntoAnotherIntent(): void
    {
        $scope = $this->identity()
->scope;
        $integer = RootIdentity::forWorkflow($scope, TestGreetingWorkflow::class, [1]);
        $float = RootIdentity::forWorkflow($scope, TestGreetingWorkflow::class, [1.0]);
        $this->assertNotSame($integer->inputFingerprint, $float->inputFingerprint);
        $value = $integer->toArray();
        $value['profile']['options']['unexpected'] = true;
        $this->expectException(\InvalidArgumentException::class);
        RootIdentity::fromArray($value);
    }

    public function testChangedWorkerCompatibilityCannotSilentlyRetargetAReservedRoot(): void
    {
        config([
            'workflows.v2.compatibility.current' => 'original',
        ]);
        $reservation = $this->store()
            ->reserve($this->identity(), 'owner');
        config([
            'workflows.v2.compatibility.current' => 'different',
        ]);
        try {
            $this->store()
                ->start($reservation, 'owner', ['Taylor']);
            $this->fail('A different worker generation was admitted.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('worker compatibility differs', $error->getMessage());
        }
        $this->assertSame(0, WorkflowRun::query()->count());
        $this->assertInstanceOf(NeverAdmittedRoot::class, $this->store()->close($reservation, 'owner'));
    }

    private function identity(): RootIdentity
    {
        $identity = RootIdentity::forWorkflow(
            new ScopeIdentity('app', 'tenant-a', 'order-1', 'business-generation-1'),
            TestGreetingWorkflow::class,
            ['Taylor']
        );
        $value = $identity->toArray();
        $value['profile']['connection'] = 'database';
        $value['profile']['queue'] = 'root-test';
        return RootIdentity::fromArray($value);
    }

    private function store(): RootReservationStore
    {
        return new RootReservationStore(new class() implements RootAuthorization {
            public function assertAllowed(RootIdentity $identity, string $principal, string $operation): string
            {
                if ($principal !== 'owner' || $identity->scope->application !== 'app'
                    || $identity->scope->namespace !== 'tenant-a') {
                    throw new LogicException('root_access_refused');
                }
                return 'joint-root-policy-v1';
            }
        }, new class() implements ScopeAuthorization {
            public function assertAllowed(ScopeIdentity $scope, string $principal, string $operation): string
            {
                if ($principal !== 'owner' || $scope->namespace !== 'tenant-a') {
                    throw new LogicException('scope_access_refused');
                }
                return 'scope-policy-v1';
            }
        });
    }
}
