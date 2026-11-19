<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\TestCase;
use Workflow\QueryMethod;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Compensation\Activities\ReserveChildScopeActivity;
use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\DurableScope;
use Workflow\V2\Compensation\Persistence\ChildScopes;
use Workflow\V2\Compensation\Persistence\ScopeProgress;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\Compensation\Recovery\RecoveryWorkflow;
use Workflow\V2\Compensation\Recovery\ReservedChildResolution;
use Workflow\V2\Compensation\Recovery\ScopeRecovery;
use Workflow\V2\Compensation\Security\ScopeAuthorization;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\StateMachine\Binding\BindingAuthorization;
use Workflow\V2\StateMachine\Binding\BindingIdentity;
use Workflow\V2\StateMachine\Binding\ChildBinding;
use Workflow\V2\StateMachine\Binding\ChildDefinitionRegistry;
use Workflow\V2\StateMachine\Binding\ChildReservation;
use Workflow\V2\StateMachine\Binding\ChildReservationStore;
use Workflow\V2\StateMachine\Binding\ChildScopeBindingAuthorization;
use Workflow\V2\StateMachine\Binding\ChildScopeParticipation;
use Workflow\V2\StateMachine\Binding\NeverAdmittedChild;
use Workflow\V2\StateMachine\Execution\ExecutionReference;
use Workflow\V2\Support\ChildWorkflowCall;
use Workflow\V2\Support\QueryStateReplayer;
use Workflow\V2\Support\TypeRegistry;
use Workflow\V2\Support\WorkflowExecutor;
use Workflow\V2\Workflow;
use Workflow\V2\WorkflowStub;

final class RetainedChildScopeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::stopWorkers();
        Queue::fake();
        config([
            'queue.default' => 'redis',
            'workflows.v2.task_dispatch_mode' => 'queue',
            'workflows.v2.namespaces' => ['tenant-a'],
        ]);
        app()
            ->instance(ScopeAuthorization::class, new class() implements ScopeAuthorization {
                public function assertAllowed(ScopeIdentity $scope, string $principal, string $operation): string
                {
                    if ($scope->application !== 'app' || $scope->namespace !== 'tenant-a' || $principal !== 'owner') {
                        throw new LogicException('scope_access_refused');
                    }
                    return 'scope-policy-v1';
                }
            });
        app()
            ->instance(ChildReservationStore::class, $this->store());
        app()
            ->instance(ChildDefinitionRegistry::class, new ChildDefinitionRegistry([
                TypeRegistry::for(RetainedScopeChildFixture::class) => [
                    str_repeat('b', 64) => RetainedScopeChildFixture::class,
                ],
            ]));
    }

    public function testVersionOneStaysUnchangedAndRetainedParticipationRoundTrips(): void
    {
        $parent = $this->parent();
        $plain = $this->store()
            ->reserve($this->identity($parent), TestGreetingWorkflow::class, 'owner');
        $this->assertSame(1, $plain->toArray()['version']);
        $this->assertSame(
            ['version', 'identity', 'workflowClass', 'definitionFingerprint', 'authorizationVersion'],
            array_keys($plain->toArray())
        );
        $this->assertSame($plain->toArray(), ChildReservation::fromArray($plain->toArray())->toArray());

        $retained = $this->reserve($this->parent());
        $this->assertSame(2, $retained->toArray()['version']);
        $this->assertSame($retained->toArray(), ChildReservation::fromArray($retained->toArray())->toArray());
        $malformed = $retained->toArray();
        $malformed['extra'] = 'foreign';
        $this->expectException(InvalidArgumentException::class);
        ChildReservation::fromArray($malformed);
    }

    public function testChangedParticipationCannotReuseTheSameReservationGeneration(): void
    {
        $parent = $this->parent();
        $reservation = $this->reserve($parent);
        $changed = new ChildScopeParticipation($reservation->participation->parent, new ScopeIdentity(
            'app',
            'tenant-a',
            'other-child',
            'generation-1'
        ), 'child');
        $this->expectException(LogicException::class);
        $this->store()
            ->reserve($reservation->identity, TestGreetingWorkflow::class, 'owner', $changed);
    }

    public function testJointAuthorizationCannotLeaveAnUncommittedNativeTransaction(): void
    {
        $parent = $this->parent();
        $identity = $this->identity($parent);
        $participation = $this->participation($parent);
        $count = WorkflowInstance::query()->count();
        $store = new ChildReservationStore(new class() implements ChildScopeBindingAuthorization {
            public function assertAllowed(BindingIdentity $identity, string $principal, string $operation): string
            {
                return 'binding-v1';
            }

            public function assertParticipationAllowed(
                BindingIdentity $identity,
                ChildScopeParticipation $participation,
                string $principal,
                string $operation,
                ?string $pinnedPolicyVersion = null,
            ): string {
                DB::beginTransaction();
                return 'joint-v1';
            }
        });
        try {
            $store->reserve($identity, TestGreetingWorkflow::class, 'owner', $participation);
            $this->fail('An authorization callback cannot hide the native reservation in its transaction.');
        } catch (LogicException $error) {
            $this->assertSame(
                'Joint binding authorization must leave the native transaction closed.',
                $error->getMessage()
            );
        } finally {
            if (DB::connection()->transactionLevel() > 0) {
                DB::rollBack();
            }
        }
        $this->assertSame($count, WorkflowInstance::query()->count());
    }

    public function testScopeAuthorizationCannotLeaveAnUncommittedNativeTransaction(): void
    {
        $parent = $this->parent();
        $identity = $this->identity($parent);
        $participation = $this->participation($parent);
        $count = WorkflowInstance::query()->count();
        app()
            ->instance(ScopeAuthorization::class, new class() implements ScopeAuthorization {
                public function assertAllowed(ScopeIdentity $scope, string $principal, string $operation): string
                {
                    DB::beginTransaction();
                    return 'scope-v1';
                }
            });
        try {
            $this->store()
                ->reserve($identity, TestGreetingWorkflow::class, 'owner', $participation);
            $this->fail('Scope authorization cannot keep a transaction open across native reservation.');
        } catch (LogicException $error) {
            $this->assertSame(
                'Retained participation authorization must leave the native transaction closed.',
                $error->getMessage()
            );
        } finally {
            if (DB::connection()->transactionLevel() > 0) {
                DB::rollBack();
            }
        }
        $this->assertSame($count, WorkflowInstance::query()->count());
    }

    public static function reservedDescriptorRefusals(): array
    {
        return [
            'foreign handler' => ['foreign', 'dw.child-scope', '1'],
            'foreign version' => ['foreign', ChildScopes::RESERVED_TYPE, '2'],
            'missing descriptor' => ['missing', ChildScopes::RESERVED_TYPE, '1'],
            'untyped descriptor' => ['untyped', ChildScopes::RESERVED_TYPE, '1'],
        ];
    }

    #[DataProvider('reservedDescriptorRefusals')]
    public function testIncompatibleRecoveryDescriptorsRemainUnresolvedBeforeStorage(
        string $kind,
        string $handler,
        string $version,
    ): void {
        $parent = $this->parent();
        $reservation = $this->reserve($parent);
        $input = $kind === 'missing' ? [] : [
            'reservation' => $kind === 'untyped' ? 'latest' : $reservation->toArray(),
        ];
        $obligation = new Obligation('child', $handler, $version, $input);
        $resolver = app(ReservedChildResolution::class);
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $result = $resolver->resolve($reservation->participation->parent, $obligation, 'recovery-1');
        $this->assertFalse($result->verified);
        $this->assertNotSame('verified', $result->outcome);
        $this->assertSame('reserved-child-descriptor-incompatible', $result->reason);
        $this->assertSame([], $queries);
    }

    public function testAStoredReservationCannotResolveAnotherRetainedObligation(): void
    {
        $reservation = $this->reserve($this->parent());
        $obligation = new Obligation('foreign-key', ChildScopes::RESERVED_TYPE, '1', [
            'reservation' => $reservation->toArray(),
        ]);
        $resolver = app(ReservedChildResolution::class);
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $result = $resolver->resolve($reservation->participation->parent, $obligation, 'recovery-1');
        $this->assertFalse($result->verified);
        $this->assertNotSame('verified', $result->outcome);
        $this->assertSame('reserved-child-owner-mismatch', $result->reason);
        $this->assertSame([], $queries);
    }

    public function testARecordedReservationActivityCannotUseAnotherNativeOwner(): void
    {
        $parent = $this->parent();
        $identity = $this->identity($parent);
        $participation = $this->participation($parent);
        $activity = new ReserveChildScopeActivity();
        $activity->run = $this->parent();
        $this->expectExceptionMessage('scope_child_binding_owner_mismatch');
        $activity->handle(
            $participation->parent->toArray(),
            'child',
            $participation->child->toArray(),
            $identity->toArray(),
            'owner',
        );
    }

    public function testPlainReservationsCannotBeRegisteredAsRetainedParticipants(): void
    {
        $identity = $this->identity($this->parent());
        $plain = $this->store()
            ->reserve($identity, TestGreetingWorkflow::class, 'owner');
        $children = new ChildScopes();
        $this->expectExceptionMessage('A reserved child participant needs its retained scope descriptor.');
        $children->registerReserved($plain);
    }

    public function testRetainedAdmissionRequiresItsNativeTransaction(): void
    {
        $reservation = $this->reserve($this->parent());
        $this->assertSame(0, DB::connection()->transactionLevel());
        $this->expectExceptionMessage('Retained child admission requires its native transaction.');
        ChildScopes::assertReservedAdmission($reservation);
    }

    public function testScopeAuthorizationRefusesBeforeAnyStorageOrDefinitionAutoload(): void
    {
        $parent = $this->parent();
        $participation = $this->participation($parent);
        $authorization = new class() implements ScopeAuthorization {
            public function assertAllowed(ScopeIdentity $scope, string $principal, string $operation): string
            {
                throw new LogicException('scope_access_refused');
            }
        };
        app()
            ->instance(ScopeAuthorization::class, $authorization);
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        try {
            $this->store()
                ->reserve($this->identity($parent), 'Untrusted\\StoredDefinition', 'owner', $participation);
            $this->fail('Unauthorized participation reached protected storage.');
        } catch (LogicException $error) {
            $this->assertSame('scope_access_refused', $error->getMessage());
        }
        $this->assertSame([], $queries);
    }

    public function testHostRegistrySelectsOnlyTheExactTypeAndPinnedPolicy(): void
    {
        $identity = $this->identity($this->parent());
        $registry = new ChildDefinitionRegistry([
            $identity->childWorkflowType => [
                $identity->childPolicyFingerprint => TestGreetingWorkflow::class,
            ],
        ]);
        $this->assertSame(TestGreetingWorkflow::class, $registry->forBinding($identity));
        $this->expectException(LogicException::class);
        (new ChildDefinitionRegistry([
            $identity->childWorkflowType => [
                str_repeat('c', 64) => TestGreetingWorkflow::class,
            ],
        ]))->forBinding($identity);
    }

    public function testReservationWithoutCommittedParticipantCannotStartAChild(): void
    {
        $parent = $this->parent();
        $reservation = $this->reserve($parent);
        $this->assertAdmissionRefused($parent, $reservation);
        $this->assertSame(
            0,
            WorkflowRun::query()->where('workflow_instance_id', $reservation->childInstanceId())->count()
        );
        $this->assertSame(0, WorkflowHistoryEvent::query()->count());
        $this->assertSame(0, DB::table('workflow_compensation_scopes')->count());
    }

    public function testNativeAdmissionCommitsOneReceiptAndItsGenuineChildScope(): void
    {
        $parent = $this->parent();
        $reservation = $this->reserve($parent);
        (new ChildScopes())->registerReserved($reservation);
        $binding = $this->admit($parent, $reservation);
        $repository = new ScopeRepository();
        $this->assertSame(
            $binding->admission->childRunId,
            $repository->metadata($reservation->participation->child)['original_run_id']
        );
        $this->assertSame('open', $repository->metadata($reservation->participation->child)['status']);
        $this->assertSame(
            $binding->toArray(),
            $this->store()
                ->close(ChildReservation::fromArray($reservation->toArray()), 'owner')->toArray()
        );
        $this->assertSame(
            1,
            WorkflowRun::query()->where('workflow_instance_id', $reservation->childInstanceId())->count()
        );
        $events = array_column($repository->journal($reservation->participation->parent), 'kind');
        $this->assertContains('child_admitted', $events);
    }

    public function testChangedStoredParticipantCannotAuthorizeAdmissionWithAnOldFingerprint(): void
    {
        $parent = $this->parent();
        $reservation = $this->reserve($parent);
        $id = (new ChildScopes())->registerReserved($reservation);
        DB::table('workflow_compensation_obligations')->where('id', $id)->update([
            'descriptor' => '{}',
        ]);
        $this->assertAdmissionRefused($parent, $reservation);
        $this->assertSame(
            0,
            WorkflowRun::query()->where('workflow_instance_id', $reservation->childInstanceId())->count()
        );
    }

    public function testParticipationCannotCrossTheOriginalBusinessApplication(): void
    {
        $parent = $this->parent();
        $plain = $this->store()
            ->reserve($this->identity($parent), TestGreetingWorkflow::class, 'owner');
        $foreign = new ChildScopeParticipation(new ScopeIdentity(
            'other-app',
            'tenant-a',
            'parent',
            '1'
        ), new ScopeIdentity('other-app', 'tenant-a', 'child', '1'), 'child');
        $this->expectException(InvalidArgumentException::class);
        new ChildReservation(
            $plain->identity,
            $plain->workflowClass,
            $plain->definitionFingerprint,
            $plain->authorizationVersion,
            $foreign,
            'joint-policy-v1',
        );
    }

    public function testClosingTheParentForwardGatePreventsNativeAdmission(): void
    {
        $parent = $this->parent();
        $reservation = $this->reserve($parent);
        (new ChildScopes())->registerReserved($reservation);
        (new ScopeRepository())->request(
            $reservation->participation->parent,
            'cancel',
            'owner',
            'business-cancellation'
        );
        $this->assertAdmissionRefused($parent, $reservation);
        $this->assertSame(
            0,
            WorkflowRun::query()->where('workflow_instance_id', $reservation->childInstanceId())->count()
        );
    }

    public function testIndependentPermissionsCannotSubstituteAnotherBusinessBindingsScopePair(): void
    {
        $parent = $this->parent();
        $first = $this->reserve($parent);
        $other = $this->identity($parent)
            ->toArray();
        $other['parentExecution']['subject'] = 'business-2';
        $other['parentExecution']['executionId'] = 'execution-2';
        $other['generation'] = 'entry-2';
        $identity = BindingIdentity::fromArray($other);
        $participation = new ChildScopeParticipation(
            new ScopeIdentity('app', 'tenant-a', 'parent-2', $parent->id),
            new ScopeIdentity('app', 'tenant-a', 'child-2', $parent->id),
            'child',
        );
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        try {
            $this->store()
                ->reserve($identity, TestGreetingWorkflow::class, 'owner', $first->participation);
            $this->fail('A new reservation paired another business owner with the first scope.');
        } catch (LogicException $error) {
            $this->assertSame('joint_binding_access_refused', $error->getMessage());
        }
        $this->assertSame([], $queries);
        $second = $this->store()
            ->reserve($identity, TestGreetingWorkflow::class, 'owner', $participation);
        $forged = new ChildReservation(
            $identity,
            $second->workflowClass,
            $second->definitionFingerprint,
            $second->authorizationVersion,
            $first->participation,
            $second->participationAuthorizationVersion
        );
        foreach ([
            fn () => $this->store()
                ->reserve($identity, TestGreetingWorkflow::class, 'owner', $first->participation),
            fn () => $this->store()
                ->load($identity, 'owner', $first->participation),
            fn () => $this->store()
                ->close($forged, 'owner'),
        ] as $operation) {
            $queries = [];
            try {
                $operation();
                $this->fail('Independently allowed identities bypassed joint relationship authorization.');
            } catch (LogicException $error) {
                $this->assertSame('joint_binding_access_refused', $error->getMessage());
            }
            $this->assertSame([], $queries);
        }
        $this->assertSame($first->toArray(), $this->store()->load($first->identity, 'owner')->toArray());
        $this->assertSame($second->toArray(), $this->store()->load($identity, 'owner')->toArray());
    }

    public function testSameApplicationScopeAndBindingGenerationsRequireTheirJointOwner(): void
    {
        $parent = $this->parent();
        $identity = $this->identity($parent);
        $participation = $this->participation($parent);
        $otherGeneration = $identity->toArray();
        $otherGeneration['generation'] = 'entry-other';
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        foreach ([
            [$identity, new ChildScopeParticipation(
                new ScopeIdentity('app', 'tenant-a', 'parent', 'other-generation'),
                $participation->child,
                'child'
            )],
            [$identity, new ChildScopeParticipation(
                $participation->parent,
                new ScopeIdentity('app', 'tenant-a', 'child', 'other-generation'),
                'child'
            )],
            [BindingIdentity::fromArray($otherGeneration), $participation],
        ] as [$binding, $pair]) {
            try {
                $this->store()
                    ->reserve($binding, TestGreetingWorkflow::class, 'owner', $pair);
                $this->fail('A foreign generation bypassed joint relationship authorization.');
            } catch (LogicException $error) {
                $this->assertSame('joint_binding_access_refused', $error->getMessage());
            }
            $this->assertSame([], $queries);
        }
    }

    public function testJointPolicyVersionCannotBeSilentlyReplacedDuringRecovery(): void
    {
        $parent = $this->parent();
        $authorization = new RetainedScopeBindingAuthorizationFixture();
        $store = new ChildReservationStore($authorization);
        $reservation = $store->reserve(
            $this->identity($parent),
            TestGreetingWorkflow::class,
            'owner',
            $this->participation($parent)
        );
        $this->assertSame('joint-policy-v1', $reservation->participationAuthorizationVersion);
        $authorization->version = 'joint-policy-v2';
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        try {
            $store->close($reservation, 'owner');
            $this->fail('A replacement policy closed the original reservation.');
        } catch (LogicException $error) {
            $this->assertSame('The original joint binding authorization policy is unavailable.', $error->getMessage());
        }
        $this->assertSame([], $queries);
        try {
            $store->reserve($reservation->identity, $reservation->workflowClass, 'owner', $reservation->participation);
            $this->fail('A replacement policy silently rewrote the original descriptor.');
        } catch (LogicException $error) {
            $this->assertSame(
                'An existing binding generation has a different immutable descriptor.',
                $error->getMessage()
            );
        }
        $authorization->version = 'joint-policy-v1';
        $this->assertSame($reservation->toArray(), $store->load($reservation->identity, 'owner')->toArray());
    }

    public function testPlainBindingAuthorizationCannotAuthorizeARetainedRelationship(): void
    {
        $parent = $this->parent();
        $store = new ChildReservationStore(new class() implements BindingAuthorization {
            public function assertAllowed(BindingIdentity $identity, string $principal, string $operation): string
            {
                return 'independent-binding-policy';
            }
        });
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Retained child bindings require joint host authorization.');
        $store->reserve($this->identity($parent), TestGreetingWorkflow::class, 'owner', $this->participation($parent));
    }

    public function testJournalFailureRollsBackNativeChildHistoryReceiptAndScopeTogether(): void
    {
        $parent = $this->parent();
        $reservation = $this->reserve($parent);
        (new ChildScopes())->registerReserved($reservation);
        $inject = true;
        DB::connection()->beforeExecuting(static function ($query, $bindings) use (&$inject): void {
            if ($inject && str_contains($query, 'workflow_compensation_journal') && in_array(
                'child_admitted',
                $bindings,
                true
            )) {
                throw new LogicException('injected_child_journal_failure');
            }
        });
        try {
            $this->admit($parent, $reservation);
            $this->fail('The injected journal failure was not reached.');
        } catch (LogicException $error) {
            $this->assertSame('injected_child_journal_failure', $error->getMessage());
        } finally {
            $inject = false;
        }
        $this->assertSame(
            0,
            WorkflowRun::query()->where('workflow_instance_id', $reservation->childInstanceId())->count()
        );
        $this->assertSame(0, WorkflowHistoryEvent::query()->count());
        $this->assertFalse(
            DB::table('workflow_compensation_scopes')->where('id', $reservation->participation->child->id())->exists()
        );
        $this->assertInstanceOf(NeverAdmittedChild::class, $this->store()->close($reservation, 'owner'));
    }

    public function testNeverAdmittedClosureSettlesWithoutSynthesizingAChildLedgerAndFencesLateStart(): void
    {
        $parent = $this->parent();
        $reservation = $this->reserve($parent);
        (new ChildScopes())->registerReserved($reservation);
        $repository = new ScopeRepository();
        $repository->request($reservation->participation->parent, 'cancel', 'owner', 'cancel');
        $resolution = $this->resolver()
            ->resolve($reservation->participation->parent, $this->obligation($reservation), 'operation-1');
        $this->assertTrue($resolution->verified);
        $this->assertSame('verified', $resolution->outcome);
        $this->assertStringStartsWith('native-child-never-admitted:', $resolution->evidence[0]);
        $this->assertFalse(
            DB::table('workflow_compensation_scopes')->where('id', $reservation->participation->child->id())->exists()
        );
        $this->assertAdmissionRefused($parent, $reservation);
    }

    public function testMissingAdmittedLedgerStaysUnresolvedEvenAfterTechnicalChildCompletion(): void
    {
        $parent = $this->parent();
        $reservation = $this->reserve($parent);
        (new ChildScopes())->registerReserved($reservation);
        $binding = $this->admit($parent, $reservation);
        WorkflowRun::query()->whereKey($binding->admission->childRunId)->update([
            'status' => RunStatus::Completed,
        ]);
        $repository = new ScopeRepository();
        $repository->request($reservation->participation->parent, 'cancel', 'owner', 'cancel');
        DB::table('workflow_compensation_scopes')->where('id', $reservation->participation->child->id())->delete();
        $resolution = $this->resolver()
            ->resolve($reservation->participation->parent, $this->obligation($reservation), 'operation-1');
        $this->assertFalse($resolution->verified);
        $this->assertNotSame('verified', $resolution->outcome);
        $this->assertFalse(
            DB::table('workflow_compensation_scopes')->where('id', $reservation->participation->child->id())->exists()
        );
    }

    public function testOnlyTheOriginalAdmittedRunsVerifiedLedgerCanSettleTheParticipant(): void
    {
        $parent = $this->parent();
        $reservation = $this->reserve($parent);
        (new ChildScopes())->registerReserved($reservation);
        $this->admit($parent, $reservation);
        $repository = new ScopeRepository();
        $repository->request($reservation->participation->parent, 'cancel', 'owner', 'cancel');
        $repository->request($reservation->participation->child, 'child-cancel', 'owner', 'cancel');
        (new ScopeProgress())->finish($reservation->participation->child);
        $resolution = $this->resolver()
            ->resolve($reservation->participation->parent, $this->obligation($reservation), 'operation-1');
        $this->assertTrue($resolution->verified);
        $this->assertSame('verified', $resolution->outcome);
        DB::table('workflow_compensation_scopes')->where('id', $reservation->participation->child->id())->update([
            'original_run_id' => 'foreign-run',
        ]);
        $this->assertFalse(
            $this->resolver()
                ->resolve($reservation->participation->parent, $this->obligation($reservation), 'operation-1')
->verified
        );
    }

    public function testRecordedScopeReservationReplaysWithoutAnotherParticipantOrChild(): void
    {
        [$stub, $parent, $reservation] = $this->recordedReservation();
        $this->runNext($parent, TaskType::Workflow);
        $original = (new QueryStateReplayer())->query($parent->fresh(), 'admitted');
        $repository = new ScopeRepository();
        $this->assertSame(
            $original['childRunId'],
            $repository->metadata($reservation->participation->child)['original_run_id']
        );
        $journal = $repository->journal($reservation->participation->parent);
        $attempts = ActivityAttempt::query()->count();
        WorkflowTask::query()->create([
            'workflow_run_id' => $parent->id,
            'namespace' => $parent->namespace,
            'task_type' => TaskType::Workflow,
            'status' => TaskStatus::Ready,
            'available_at' => now(),
            'payload' => [],
        ]);
        $this->runNext($parent, TaskType::Workflow);
        $this->assertSame($original, (new QueryStateReplayer())->query($parent->fresh(), 'admitted'));
        $this->assertSame($journal, $repository->journal($reservation->participation->parent));
        $this->assertSame($attempts, ActivityAttempt::query()->count());
        $this->assertSame(1, DB::table('workflow_compensation_children')->count());
        $this->assertSame(1, DB::table('workflow_compensation_obligations')->count());
        $this->assertSame(2, WorkflowRun::query()->count());
        $child = WorkflowRun::query()->findOrFail($original['childRunId']);
        $this->runNext($child, TaskType::Workflow);
        $stub->signal('finish');
        $this->runNext($parent, TaskType::Workflow);
        $this->assertSame(RunStatus::Completed, $parent->fresh()->status);
        $this->assertSame('Hello, Taylor!', $stub->refresh()->output());
    }

    public function testCancellationAfterTheRecordedReservationFencesADelayedParentStart(): void
    {
        [, $parent, $reservation] = $this->recordedReservation();
        (new ScopeRepository())->request($reservation->participation->parent, 'cancel', 'owner', 'cancel');
        $resolution = $this->resolver()
            ->resolve($reservation->participation->parent, $this->obligation($reservation), 'operation-1');
        $this->assertTrue($resolution->verified);
        $this->runNext($parent, TaskType::Workflow);
        $this->assertSame(RunStatus::Failed, $parent->fresh()->status);
        $this->assertSame(
            0,
            WorkflowRun::query()->where('workflow_instance_id', $reservation->childInstanceId())->count()
        );
        $this->assertInstanceOf(NeverAdmittedChild::class, $this->store()->close($reservation, 'owner'));
    }

    public static function terminalStops(): array
    {
        return [
            'cancel' => ['cancel'],
            'terminate' => ['terminate'],
        ];
    }

    #[DataProvider('terminalStops')]
    public function testTerminalParentRecoversItsUnusedRecordedChildReservation(string $stop): void
    {
        [$stub, $parent, $reservation] = $this->recordedReservation();
        $stopped = $stop === 'cancel' ? $stub->cancel('owner-stop') : $stub->terminate('owner-stop');
        $this->assertTrue($stopped->accepted());
        $status = $stop === 'cancel' ? RunStatus::Cancelled : RunStatus::Terminated;
        $this->assertSame($status, $parent->fresh()->status);
        $history = $parent->historyEvents()
            ->orderBy('sequence')
            ->get()
            ->toArray();
        $result = app(ScopeRecovery::class)->request(
            $reservation->participation->parent,
            'recover-parent',
            'owner',
            'cancel'
        );
        $this->assertSame('recovery', $result['route']);
        $this->drainRecoveryTasks();
        $snapshot = (new ScopeRepository())->inspect($reservation->participation->parent);
        $this->assertSame('verified', $snapshot['status']);
        $this->assertSame($parent->id, $snapshot['original_run_id']);
        $this->assertSame(RunStatus::Completed, WorkflowRun::query()->findOrFail($result['runId'])->status);
        $this->assertFalse(DB::table('workflow_compensation_scopes')
            ->where('id', $reservation->participation->child->id())
            ->exists());
        $this->assertInstanceOf(NeverAdmittedChild::class, $this->store()->close($reservation, 'owner'));
        $this->assertAdmissionRefused($parent->fresh(), $reservation);
        $this->assertSame(0, WorkflowRun::query()
            ->where('workflow_instance_id', $reservation->childInstanceId())
            ->count());
        $this->assertSame($status, $parent->fresh()->status);
        $this->assertSame($history, $parent->historyEvents()->orderBy('sequence')->get()->toArray());
        $again = app(ScopeRecovery::class)->request(
            $reservation->participation->parent,
            'recover-parent',
            'owner',
            'cancel'
        );
        $this->assertSame($result['runId'], $again['runId']);
        $this->assertSame($result['commandId'], $again['commandId']);
    }

    public function testCompletedRecordedParentAndChildRecoverTheirOriginalRetainedScopes(): void
    {
        [$stub, $parent, $reservation] = $this->recordedReservation();
        $this->runNext($parent, TaskType::Workflow);
        $binding = $this->store()
            ->close($reservation, 'owner');
        $this->assertInstanceOf(ChildBinding::class, $binding);
        $child = WorkflowRun::query()->findOrFail($binding->admission->childRunId);
        $this->runNext($child, TaskType::Workflow);
        $stub->signal('finish');
        $this->runNext($parent, TaskType::Workflow);
        $this->assertSame(RunStatus::Completed, $parent->fresh()->status);
        $this->assertSame(RunStatus::Completed, $child->fresh()->status);
        $parentHistory = $parent->historyEvents()
            ->orderBy('sequence')
            ->get()
            ->toArray();
        $childHistory = $child->historyEvents()
            ->orderBy('sequence')
            ->get()
            ->toArray();
        $result = app(ScopeRecovery::class)->request(
            $reservation->participation->parent,
            'recover-parent',
            'owner',
            'cancel'
        );
        $this->drainRecoveryTasks();
        $repository = new ScopeRepository();
        $this->assertSame('verified', $repository->metadata($reservation->participation->parent)['status']);
        $this->assertSame('verified', $repository->metadata($reservation->participation->child)['status']);
        $this->assertSame($parent->id, $repository->metadata($reservation->participation->parent)['original_run_id']);
        $this->assertSame($child->id, $repository->metadata($reservation->participation->child)['original_run_id']);
        $this->assertSame(RunStatus::Completed, WorkflowRun::query()->findOrFail($result['runId'])->status);
        $this->assertSame($binding->toArray(), $this->store()->close($reservation, 'owner')->toArray());
        $this->assertSame($parentHistory, $parent->historyEvents()->orderBy('sequence')->get()->toArray());
        $this->assertSame($childHistory, $child->historyEvents()->orderBy('sequence')->get()->toArray());
        $this->assertSame('Hello, Taylor!', $stub->refresh()->output());
        $this->assertSame(1, WorkflowRun::query()
            ->where('workflow_instance_id', $reservation->childInstanceId())
            ->count());
    }

    public function testReservationActivityRecoversItsOriginalDescriptorWithoutADeployedRegistryEntry(): void
    {
        $stub = WorkflowStub::make(RetainedScopeParentFixture::class, 'retained-parent', 'tenant-a');
        $stub->start();
        $parent = WorkflowRun::query()->findOrFail($stub->runId());
        $this->runNext($parent, TaskType::Workflow);
        $original = $this->store()
            ->reserve(
                $this->identity($parent, RetainedScopeChildFixture::class),
                RetainedScopeChildFixture::class,
                'owner',
                $this->participation($parent)
            );
        app()
            ->instance(ChildDefinitionRegistry::class, new ChildDefinitionRegistry());
        $this->runNext($parent, TaskType::Activity);
        $this->assertSame($original->toArray(), (new QueryStateReplayer())->query($parent->fresh(), 'reserved'));
        $this->assertSame(1, DB::table('workflow_compensation_obligations')->count());
        $this->runNext($parent, TaskType::Workflow);
        $this->assertSame(
            1,
            WorkflowRun::query()->where('workflow_instance_id', $original->childInstanceId())->count()
        );
    }

    private function resolver(): ReservedChildResolution
    {
        return new ReservedChildResolution(new ScopeRepository(), $this->store(), app(ScopeAuthorization::class), app(
            ScopeRecovery::class
        ));
    }

    private function drainRecoveryTasks(): void
    {
        try {
            for ($count = 0; $count < 100; ++$count) {
                $task = WorkflowTask::query()->where('status', TaskStatus::Ready)
                    ->whereIn('workflow_run_id', WorkflowRun::query()
                        ->where('workflow_class', RecoveryWorkflow::class)->select('id'))
                    ->orderBy('available_at')
                    ->orderBy('created_at')
                    ->first();
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
                app()
                    ->call([$job, 'handle']);
            }
            $this->fail('Native retained-child recovery did not settle within the bounded drain.');
        } finally {
            Carbon::setTestNow();
        }
    }

    private function obligation(ChildReservation $reservation): Obligation
    {
        return new Obligation('child', ChildScopes::RESERVED_TYPE, ChildScopes::VERSION, [
            'reservation' => $reservation->toArray(),
        ]);
    }

    private function participation(WorkflowRun $parent): ChildScopeParticipation
    {
        return new ChildScopeParticipation(new ScopeIdentity(
            'app',
            'tenant-a',
            'parent',
            $parent->id
        ), new ScopeIdentity('app', 'tenant-a', 'child', $parent->id), 'child');
    }

    private function reserve(WorkflowRun $parent): ChildReservation
    {
        return $this->store()
            ->reserve($this->identity($parent), TestGreetingWorkflow::class, 'owner', $this->participation($parent));
    }

    private function store(): ChildReservationStore
    {
        return new ChildReservationStore(new RetainedScopeBindingAuthorizationFixture());
    }

    private function recordedReservation(): array
    {
        $stub = WorkflowStub::make(RetainedScopeParentFixture::class, 'retained-parent', 'tenant-a');
        $stub->start();
        $parent = WorkflowRun::query()->findOrFail($stub->runId());
        $this->runNext($parent, TaskType::Workflow);
        $this->runNext($parent, TaskType::Activity);
        $result = (new QueryStateReplayer())->query($parent->fresh(), 'reserved');
        $this->assertNotSame([], $result, 'The recorded reserve activity did not produce its reservation.');
        return [$stub, $parent, ChildReservation::fromArray($result)];
    }

    private function runNext(WorkflowRun $run, TaskType $type): void
    {
        $task = WorkflowTask::query()->where('workflow_run_id', $run->id)->where('task_type', $type)->where(
            'status',
            TaskStatus::Ready
        )->firstOrFail();
        $job = $type === TaskType::Activity ? new RunActivityTask($task->id) : new RunWorkflowTask($task->id);
        app()
            ->call([$job, 'handle']);
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

    private function identity(WorkflowRun $parent, string $workflowClass = TestGreetingWorkflow::class): BindingIdentity
    {
        return new BindingIdentity(new ExecutionReference('app', 'business-1', 'execution-1', str_repeat(
            'a',
            64
        ), 'ready', 'revision-1'), 'tenant-a', $parent->workflow_instance_id, $parent->id, 'shipment', 'entry-1', 'delivering', TypeRegistry::for(
            $workflowClass
        ), str_repeat('b', 64), 'delivered', ChildReservation::inputFingerprint(['Taylor']));
    }

    private function assertAdmissionRefused(WorkflowRun $parent, ChildReservation $reservation): void
    {
        try {
            $this->admit($parent, $reservation);
            $this->fail('A fenced child admission succeeded.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }
    }

    private function admit(WorkflowRun $parent, ChildReservation $reservation): ChildBinding
    {
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
                true
            );
        });
        $binding = $this->store()
            ->close($reservation, 'owner');
        $this->assertInstanceOf(ChildBinding::class, $binding);
        return $binding;
    }
}

final class RetainedScopeBindingAuthorizationFixture implements ChildScopeBindingAuthorization
{
    public string $version = 'joint-policy-v1';

    public function assertAllowed(BindingIdentity $identity, string $principal, string $operation): string
    {
        if ($principal !== 'owner' || $identity->namespace !== 'tenant-a') {
            throw new LogicException('binding_access_refused');
        }
        return 'binding-policy-v1';
    }

    public function assertParticipationAllowed(
        BindingIdentity $identity,
        ChildScopeParticipation $participation,
        string $principal,
        string $operation,
        ?string $pinnedPolicyVersion = null,
    ): string {
        $pair = match ($identity->parentExecution->subject) {
            'business-1' => ['execution-1', 'entry-1', 'parent', 'child'],
            'business-2' => ['execution-2', 'entry-2', 'parent-2', 'child-2'],
            default => throw new LogicException('joint_binding_access_refused'),
        };
        if ($principal !== 'owner' || $identity->parentExecution->scope !== 'app'
            || $identity->namespace !== 'tenant-a' || DB::connection()->transactionLevel() !== 0
            || $identity->parentExecution->executionId !== $pair[0] || $identity->generation !== $pair[1]
            || $participation->parent->key !== $pair[2] || $participation->child->key !== $pair[3]
            || $participation->parent->generation !== $identity->parentRunId
            || $participation->child->generation !== $identity->parentRunId) {
            throw new LogicException('joint_binding_access_refused');
        }
        return $this->version;
    }
}

#[Signal('finish')]
final class RetainedScopeParentFixture extends Workflow
{
    public array $reservation = [];

    public array $admission = [];

    public function handle(): string
    {
        $parent = new ScopeIdentity('app', 'tenant-a', 'parent', $this->runId());
        $child = new ScopeIdentity('app', 'tenant-a', 'child', $this->runId());
        $identity = new BindingIdentity(new ExecutionReference('app', 'business-1', 'execution-1', str_repeat(
            'a',
            64
        ), 'ready', 'revision-1'), 'tenant-a', $this->workflowId(), $this->runId(), 'shipment', 'entry-1', 'delivering', TypeRegistry::for(
            RetainedScopeChildFixture::class
        ), str_repeat('b', 64), 'delivered', ChildReservation::inputFingerprint(['Taylor']));
        $reservation = (new DurableScope($this, $parent, 'owner'))->reserveChild('child', $child, $identity);
        $this->reservation = $reservation->toArray();
        $started = $reservation->start('Taylor');
        $this->admission = $started->toArray();
        self::awaitSignal('finish');
        return $started->await();
    }

    #[QueryMethod]
    public function reserved(): array
    {
        return $this->reservation;
    }

    #[QueryMethod]
    public function admitted(): array
    {
        return $this->admission;
    }
}

final class RetainedScopeChildFixture extends Workflow
{
    public function handle(string $name): string
    {
        return 'Hello, ' . $name . '!';
    }
}
