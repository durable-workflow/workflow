<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Facades\DB;
use LogicException;
use ReflectionMethod;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\StateMachine\Binding\BindingAuthorization;
use Workflow\V2\StateMachine\Binding\BindingIdentity;
use Workflow\V2\StateMachine\Binding\ChildBinding;
use Workflow\V2\StateMachine\Binding\ChildReservation;
use Workflow\V2\StateMachine\Binding\ChildReservationStore;
use Workflow\V2\StateMachine\Binding\NeverAdmittedChild;
use Workflow\V2\StateMachine\Execution\ExecutionReference;
use Workflow\V2\Support\ChildStartHistory;
use Workflow\V2\Support\ChildWorkflowCall;
use Workflow\V2\Support\ChildWorkflowOptions;
use Workflow\V2\Support\TypeRegistry;
use Workflow\V2\Support\WorkflowExecutor;
use Workflow\V2\WorkflowStub;

final class V2ChildReservationTest extends TestCase
{
    public function testLostReserveAcknowledgementReusesOriginalGenerationAfterParentCloses(): void
    {
        $parent = $this->parent();
        $reservation = $this->reserve($parent);
        $parent->forceFill([
            'status' => RunStatus::Completed,
        ])->save();
        $again = $this->reserve($parent);
        $this->assertSame($reservation->toArray(), $again->toArray());
        $this->assertSame(2, WorkflowInstance::query()->count());
        $this->assertSame(1, WorkflowRun::query()->count());
    }

    public function testCloseBeforeAdmissionPersistsTheSameFenceAndRefusesLateNativeWork(): void
    {
        $parent = $this->parent();
        $reservation = $this->reserve($parent);
        $closed = $this->store()
            ->close($reservation, 'trusted-owner');
        $this->assertInstanceOf(NeverAdmittedChild::class, $closed);
        $again = $this->store()
            ->close(ChildReservation::fromArray($reservation->toArray()), 'trusted-owner');
        $this->assertInstanceOf(NeverAdmittedChild::class, $again);
        $this->assertSame($closed->closureId, $again->closureId);
        try {
            DB::transaction(static fn () => ChildReservationStore::lockForAdmission($parent, new ChildWorkflowCall(
                $reservation->workflowClass,
                ['Taylor'],
                $reservation->identity->childOptions,
                $reservation,
            )));
            $this->fail('The closed reservation admitted late work.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('closed', $error->getMessage());
        }
        $this->assertSame(
            0,
            WorkflowRun::query()->where('workflow_instance_id', $reservation->childInstanceId())->count()
        );
        $this->assertSame(0, WorkflowHistoryEvent::query()->count());
    }

    public function testAdmissionBeforeCloseReturnsExactNativeReceiptAndHonorsPinnedRouting(): void
    {
        $parent = $this->parent();
        $reservation = $this->reserve($parent);
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $parent->id,
            'namespace' => $parent->namespace,
            'task_type' => TaskType::Workflow,
            'status' => TaskStatus::Ready,
            'available_at' => now(),
            'payload' => [],
        ]);
        DB::transaction(static function () use ($parent, $reservation, $task): void {
            (new ReflectionMethod(WorkflowExecutor::class, 'scheduleChildWorkflow'))->invoke(
                new WorkflowExecutor(),
                $parent,
                $task,
                1,
                new ChildWorkflowCall($reservation->workflowClass, [
                    'Taylor',
                ], $reservation->identity->childOptions, $reservation),
                null,
                false,
                true,
            );
        });
        $binding = $this->store()
            ->close($reservation, 'trusted-owner');
        $this->assertInstanceOf(ChildBinding::class, $binding);
        $this->assertSame($reservation->childInstanceId(), $binding->admission->childInstanceId);
        $parent->load('historyEvents');
        $this->assertSame(ChildStartHistory::receipt($parent, 1)->toArray(), $binding->admission->toArray());
        $this->assertSame($binding->toArray(), $this->store()->close($reservation, 'trusted-owner')->toArray());
        $childRun = WorkflowRun::query()->findOrFail($binding->admission->childRunId);
        $this->assertSame('database', $childRun->connection);
        $this->assertSame('bound-child', $childRun->queue);
        $this->assertSame(
            1,
            WorkflowRun::query()->where('workflow_instance_id', $reservation->childInstanceId())->count()
        );
        $this->assertSame(1, $parent->historyEvents->where('event_type', HistoryEventType::ChildRunStarted)->count());
    }

    public function testForgedReservationCannotSelectAnAutoloadedDefinition(): void
    {
        $parent = $this->parent();
        $reservation = $this->reserve($parent);
        $untrustedClass = 'Tests\\Fixtures\\V2\\UntrustedReservationDefinition';
        $forged = new ChildReservation(
            $reservation->identity,
            $untrustedClass,
            $reservation->definitionFingerprint,
            $reservation->authorizationVersion,
        );
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $parent->id,
            'namespace' => $parent->namespace,
            'task_type' => TaskType::Workflow,
            'status' => TaskStatus::Ready,
            'available_at' => now(),
            'payload' => [],
        ]);
        $autoloaded = [];
        $loader = static function (string $class) use (&$autoloaded): void {
            $autoloaded[] = $class;
        };
        spl_autoload_register($loader);
        $failure = null;
        try {
            DB::transaction(static function () use ($parent, $task, $forged): void {
                (new ReflectionMethod(WorkflowExecutor::class, 'scheduleChildWorkflow'))->invoke(
                    new WorkflowExecutor(),
                    $parent,
                    $task,
                    1,
                    new ChildWorkflowCall($forged->workflowClass, ['Taylor'], $forged->identity->childOptions, $forged),
                    null,
                    false,
                    true,
                );
            });
        } catch (\Throwable $error) {
            $failure = $error;
        } finally {
            spl_autoload_unregister($loader);
        }
        $this->assertInstanceOf(LogicException::class, $failure);
        $this->assertStringContainsString('stored native child reservation does not match', $failure->getMessage());
        $this->assertNotContains($untrustedClass, $autoloaded);
        $this->assertSame(
            0,
            WorkflowRun::query()->where('workflow_instance_id', $reservation->childInstanceId())->count()
        );
    }

    public function testForeignInstanceOwnershipIsRefusedBeforeDecodingItsReservation(): void
    {
        $parent = $this->parent();
        $reservation = $this->reserve($parent);
        WorkflowInstance::query()->whereKey($reservation->childInstanceId())->toBase()->update([
            'namespace' => 'foreign-tenant',
            'native_child_reservation' => json_encode([
                'protected' => 'not-a-reservation',
            ], JSON_THROW_ON_ERROR),
        ]);
        foreach (['reserve', 'load', 'close'] as $operation) {
            try {
                if ($operation === 'reserve') {
                    $this->reserve($parent);
                } elseif ($operation === 'load') {
                    $this->store()
                        ->load($reservation->identity, 'trusted-owner');
                } else {
                    $this->store()
                        ->close($reservation, 'trusted-owner');
                }
                $this->fail('A foreign native instance reached reservation decoding.');
            } catch (LogicException $error) {
                $this->assertStringContainsString(
                    'stored native child reservation does not match',
                    $error->getMessage()
                );
            }
        }
    }

    public function testChangedImmutableDescriptorCannotReuseItsOriginalLookupKey(): void
    {
        $parent = $this->parent();
        $reservation = $this->reserve($parent);
        $changed = $reservation->identity->toArray();
        $changed['requiredOutcome'] = 'other-outcome';
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('different immutable descriptor');
        $this->store()
            ->reserve(BindingIdentity::fromArray($changed), TestGreetingWorkflow::class, 'trusted-owner');
    }

    public function testAuthorizationRefusalOccursBeforeAnyProtectedDatabaseRead(): void
    {
        $parent = $this->parent();
        $reservation = $this->reserve($parent);
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        try {
            $this->store(false)
                ->close($reservation, 'untrusted');
            $this->fail('An unauthorized caller reached binding storage.');
        } catch (LogicException $error) {
            $this->assertSame('binding_access_refused', $error->getMessage());
        }
        $this->assertSame([], $queries);
        try {
            $this->store(false)
                ->load($reservation->identity, 'untrusted');
            $this->fail('An unauthorized lookup reached binding storage.');
        } catch (LogicException $error) {
            $this->assertSame('binding_access_refused', $error->getMessage());
        }
        $this->assertSame([], $queries);
    }

    public function testCallerTransactionsCannotExposeUncommittedClosureOrRunHostAuthorizationUnderLocks(): void
    {
        $parent = $this->parent();
        $reservation = $this->reserve($parent);
        $authorization = new class() implements BindingAuthorization {
            public int $calls = 0;

            public function assertAllowed(BindingIdentity $identity, string $principal, string $operation): string
            {
                ++$this->calls;
                return 'policy-v1';
            }
        };
        $store = new ChildReservationStore($authorization);
        foreach (['reserve', 'load', 'close'] as $operation) {
            try {
                DB::transaction(static function () use ($operation, $store, $reservation): void {
                    if ($operation === 'reserve') {
                        $store->reserve($reservation->identity, $reservation->workflowClass, 'owner');
                    } elseif ($operation === 'load') {
                        $store->load($reservation->identity, 'owner');
                    } else {
                        $store->close($reservation, 'owner');
                    }
                });
                $this->fail('A binding outcome escaped an uncommitted caller transaction.');
            } catch (LogicException $error) {
                $this->assertStringContainsString('own recorded activity transaction boundary', $error->getMessage());
            }
        }
        $this->assertSame(0, $authorization->calls);
        $this->assertInstanceOf(NeverAdmittedChild::class, $store->close($reservation, 'owner'));
    }

    public function testAuthorizationCannotLeaveAnUncommittedNativeTransaction(): void
    {
        $reservation = $this->reserve($this->parent());
        $authorization = new class() implements BindingAuthorization {
            public function assertAllowed(BindingIdentity $identity, string $principal, string $operation): string
            {
                DB::beginTransaction();
                return 'policy-v1';
            }
        };
        try {
            (new ChildReservationStore($authorization))->close($reservation, 'owner');
            $this->fail('Authorization left an uncommitted native closure boundary.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('transaction boundary closed', $error->getMessage());
        } finally {
            if (DB::transactionLevel() !== 0) {
                DB::rollBack();
            }
        }
        $this->assertInstanceOf(NeverAdmittedChild::class, $this->store()->close($reservation, 'trusted-owner'));
    }

    public function testOrdinaryStartCannotBypassAClosedNativeReservation(): void
    {
        $reservation = $this->reserve($this->parent());
        $this->store()
            ->close($reservation, 'trusted-owner');
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('original parent command');
        WorkflowStub::load($reservation->childInstanceId(), 'tenant-a')->attemptStart('Taylor');
    }

    public function testSignalWithStartCannotBypassAClosedNativeReservation(): void
    {
        $reservation = $this->reserve($this->parent());
        $this->store()
            ->close($reservation, 'trusted-owner');
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('original parent command');
        WorkflowStub::load($reservation->childInstanceId(), 'tenant-a')->attemptSignalWithStart(
            'finish',
            [],
            ['Taylor']
        );
    }

    public function testOrdinaryMakeCannotReplaceTheReservedDefinition(): void
    {
        $reservation = $this->reserve($this->parent());
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('original parent command');
        WorkflowStub::make($reservation->workflowClass, $reservation->childInstanceId(), 'tenant-a');
    }

    public function testReplayCannotAddOrRemoveAReservation(): void
    {
        $reservation = $this->reserve($this->parent());
        ChildReservationStore::assertRecordedCommand($reservation->toArray(), $reservation);
        ChildReservationStore::assertRecordedCommand(array_reverse($reservation->toArray(), true), $reservation);
        foreach ([[$reservation->toArray(), null], [null, $reservation]] as [$recorded, $current]) {
            try {
                ChildReservationStore::assertRecordedCommand($recorded, $current);
                $this->fail('A changed reservation replayed as the same native command.');
            } catch (LogicException $error) {
                $this->assertStringContainsString('differs', $error->getMessage());
            }
        }
    }

    public function testChangedInputAndForeignParentCannotReachTheReservedChild(): void
    {
        $parent = $this->parent();
        $reservation = $this->reserve($parent);
        $foreign = $this->parent();
        foreach ([[$parent, ['changed']], [$foreign, ['Taylor']]] as [$executing, $arguments]) {
            try {
                DB::transaction(
                    static fn () => ChildReservationStore::lockForAdmission($executing, new ChildWorkflowCall(
                        $reservation->workflowClass,
                        $arguments,
                        $reservation->identity->childOptions,
                        $reservation,
                    ))
                );
                $this->fail('A changed native command reached the reservation.');
            } catch (LogicException $error) {
                $this->assertStringContainsString('reserved owner, definition or input', $error->getMessage());
            }
        }
        $this->assertSame(
            0,
            WorkflowRun::query()->where('workflow_instance_id', $reservation->childInstanceId())->count()
        );
    }

    public function testAnUncertainNativePointerCannotBecomeNeverAdmitted(): void
    {
        $reservation = $this->reserve($this->parent());
        WorkflowInstance::query()->whereKey($reservation->childInstanceId())->update([
            'run_count' => 1,
        ]);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('uncertain child');
        $this->store()
            ->close($reservation, 'trusted-owner');
    }

    public function testAClosedReservationWithAnUnexpectedRunCannotCertifyAbsence(): void
    {
        $parent = $this->parent();
        $reservation = $this->reserve($parent);
        $this->store()
            ->close($reservation, 'trusted-owner');
        WorkflowRun::query()->create([
            'workflow_instance_id' => $reservation->childInstanceId(),
            'run_number' => 1,
            'workflow_class' => $reservation->workflowClass,
            'workflow_type' => $reservation->identity->childWorkflowType,
            'namespace' => $reservation->identity->namespace,
            'status' => RunStatus::Pending,
            'started_at' => now(),
            'last_history_sequence' => 0,
        ]);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('uncertain child');
        $this->store()
            ->close($reservation, 'trusted-owner');
    }

    public function testAReservationTombstoneSurvivesItsParentRunClosing(): void
    {
        $parent = $this->parent();
        $reservation = $this->reserve($parent);
        $closed = $this->store()
            ->close($reservation, 'trusted-owner');
        $parent->forceFill([
            'status' => RunStatus::Cancelled,
        ])->save();
        $this->assertSame($closed->closureId, $this->store()->close($reservation, 'trusted-owner')->closureId);
        $this->assertSame($reservation->toArray(), $this->reserve($parent)->toArray());
    }

    public function testCancellationPreventsCreatingANewReservation(): void
    {
        $parent = $this->parent();
        $parent->forceFill([
            'cancellation_requested_at' => now(),
        ])->save();
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('active original parent');
        $this->reserve($parent);
    }

    private function store(bool $allowed = true): ChildReservationStore
    {
        return new ChildReservationStore(new class($allowed) implements BindingAuthorization {
            public function __construct(
                private readonly bool $allowed
            ) {
            }

            public function assertAllowed(BindingIdentity $identity, string $principal, string $operation): string
            {
                if (! $this->allowed || $principal !== 'trusted-owner' || $identity->namespace !== 'tenant-a') {
                    throw new LogicException('binding_access_refused');
                }
                return 'host-policy-v1';
            }
        });
    }

    private function parent(): WorkflowRun
    {
        $instance = WorkflowInstance::query()->create([
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => TypeRegistry::for(TestGreetingWorkflow::class),
            'namespace' => 'tenant-a',
            'run_count' => 1,
            'reserved_at' => now(),
        ]);
        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => $instance->workflow_type,
            'namespace' => 'tenant-a',
            'status' => RunStatus::Running,
            'last_history_sequence' => 0,
            'started_at' => now(),
        ]);
        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();
        return $run;
    }

    private function identity(WorkflowRun $parent): BindingIdentity
    {
        return new BindingIdentity(
            new ExecutionReference('app', 'business-1', 'execution-1', str_repeat('a', 64), 'ready', 'revision-1'),
            'tenant-a',
            $parent->workflow_instance_id,
            $parent->id,
            'shipment',
            'entry-1',
            'delivering',
            TypeRegistry::for(TestGreetingWorkflow::class),
            str_repeat('b', 64),
            'delivered',
            ChildReservation::inputFingerprint(['Taylor']),
            new ChildWorkflowOptions(connection: 'database', queue: 'bound-child'),
        );
    }

    private function reserve(WorkflowRun $parent): ChildReservation
    {
        return $this->store()
            ->reserve($this->identity($parent), TestGreetingWorkflow::class, 'trusted-owner');
    }
}
