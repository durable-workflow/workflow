<?php

declare(strict_types=1);

namespace Workflow\Tests\Unit\V2;

use LogicException;
use Orchestra\Testbench\TestCase;
use ReflectionMethod;
use function Workflow\V2\activity;
use function Workflow\V2\localActivity;
use function Workflow\V2\parallel;
use function Workflow\V2\select;
use Workflow\V2\Support\ActivityCall;
use Workflow\V2\Support\ActivityOptions;
use Workflow\V2\Support\AllCall;
use Workflow\V2\Support\AwaitCall;
use Workflow\V2\Support\AwaitWithTimeoutCall;
use Workflow\V2\Support\ChildWorkflowCall;
use Workflow\V2\Support\ContinueAsNewCall;
use Workflow\V2\Support\LocalActivityCall;
use Workflow\V2\Support\LocalActivityOptions;
use Workflow\V2\Support\SelectCall;
use Workflow\V2\Support\SelectionResult;
use Workflow\V2\Support\SideEffectCall;
use Workflow\V2\Support\SignalCall;
use Workflow\V2\Support\TimerCall;
use Workflow\V2\Support\UpsertMemoCall;
use Workflow\V2\Support\UpsertSearchAttributesCall;
use Workflow\V2\Support\VersionCall;
use Workflow\V2\Support\WorkerSession;
use Workflow\V2\Support\WorkerSessionOptions;
use function Workflow\V2\workerSession;
use Workflow\V2\Workflow;
use Workflow\V2\WorkflowStub;

/**
 * The static facade on Workflow\V2\Workflow is a thin delegate to the
 * namespaced helpers in src/V2/functions.php. These tests verify that
 * the delegates produce the same Call value objects the helpers do
 * when invoked outside an active fiber (where the helpers are
 * documented to return the call synchronously).
 */
class WorkflowFacadeTest extends TestCase
{
    public function testActivityReturnsAnActivityCall(): void
    {
        $call = Workflow::activity('App\\Activities\\Example', 'a', 'b');

        $this->assertInstanceOf(ActivityCall::class, $call);
    }

    public function testExecuteActivityAliasesActivity(): void
    {
        $call = Workflow::executeActivity('App\\Activities\\Example');

        $this->assertInstanceOf(ActivityCall::class, $call);
    }

    public function testLocalActivityReturnsALocalActivityCall(): void
    {
        $call = Workflow::localActivity('App\\Activities\\Example', 'a', 'b');

        $this->assertInstanceOf(LocalActivityCall::class, $call);
        $this->assertSame(['a', 'b'], $call->arguments);
    }

    public function testExecuteLocalActivityAliasesLocalActivity(): void
    {
        $call = Workflow::executeLocalActivity('App\\Activities\\Example');

        $this->assertInstanceOf(LocalActivityCall::class, $call);
    }

    public function testLocalActivityHelperAcceptsLocalActivityOptions(): void
    {
        $options = new LocalActivityOptions(maxAttempts: 3, heartbeatTimeout: 10);

        $call = localActivity('App\\Activities\\Example', $options, 'payload');

        $this->assertInstanceOf(LocalActivityCall::class, $call);
        $this->assertSame($options, $call->options);
        $this->assertSame(['payload'], $call->arguments);
    }

    public function testLocalActivityRejectsQueuedRoutingOptions(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Local activities do not accept connection, queue, worker session, or schedule-to-start routing options.'
        );

        localActivity('App\\Activities\\Example', new ActivityOptions(queue: 'imports'));
    }

    public function testLocalActivityRejectsWorkerSessionOptions(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Local activities do not accept connection, queue, worker session, or schedule-to-start routing options.'
        );

        localActivity(
            'App\\Activities\\Example',
            new ActivityOptions(workerSession: WorkerSessionOptions::named('gpu-render')),
        );
    }

    public function testWorkerSessionReturnsWorkerSessionHandle(): void
    {
        $session = Workflow::workerSession(
            'gpu-render',
            new WorkerSessionOptions(queue: 'gpu-activities', requirements: ['gpu:nvidia-l4']),
        );

        $this->assertInstanceOf(WorkerSession::class, $session);
        $this->assertSame('gpu-render', $session->options->sessionId);
        $this->assertSame('gpu-activities', $session->options->queue);
        $this->assertSame(['gpu:nvidia-l4'], $session->options->requirements);
    }

    public function testWorkerSessionHelperReturnsWorkerSessionHandle(): void
    {
        $session = workerSession('gpu-render');

        $this->assertInstanceOf(WorkerSession::class, $session);
        $this->assertSame('gpu-render', $session->options->sessionId);
    }

    public function testChildReturnsAChildWorkflowCall(): void
    {
        $call = Workflow::child('App\\Workflows\\Example');

        $this->assertInstanceOf(ChildWorkflowCall::class, $call);
    }

    public function testExecuteChildWorkflowAliasesChild(): void
    {
        $call = Workflow::executeChildWorkflow('App\\Workflows\\Example');

        $this->assertInstanceOf(ChildWorkflowCall::class, $call);
    }

    public function testTimerReturnsATimerCall(): void
    {
        $call = Workflow::timer(42);

        $this->assertInstanceOf(TimerCall::class, $call);
    }

    public function testTimerSugarMethodsReturnTimerCalls(): void
    {
        $this->assertInstanceOf(TimerCall::class, Workflow::seconds(5));
        $this->assertInstanceOf(TimerCall::class, Workflow::minutes(2));
        $this->assertInstanceOf(TimerCall::class, Workflow::hours(1));
        $this->assertInstanceOf(TimerCall::class, Workflow::days(1));
        $this->assertInstanceOf(TimerCall::class, Workflow::weeks(1));
        $this->assertInstanceOf(TimerCall::class, Workflow::months(1));
        $this->assertInstanceOf(TimerCall::class, Workflow::years(1));
    }

    public function testAwaitWithSignalNameReturnsSignalCall(): void
    {
        $call = Workflow::await('some-signal');

        $this->assertInstanceOf(SignalCall::class, $call);
    }

    public function testAwaitSignalIsEquivalentToAwaitByName(): void
    {
        $this->assertInstanceOf(SignalCall::class, Workflow::awaitSignal('some-signal'));
    }

    public function testAwaitWithConditionReturnsAwaitCall(): void
    {
        $call = Workflow::await(static fn (): bool => true);

        $this->assertInstanceOf(AwaitCall::class, $call);
    }

    public function testAwaitWithTimeoutReturnsAwaitWithTimeoutCall(): void
    {
        $call = Workflow::awaitWithTimeout(5, static fn (): bool => true);

        $this->assertInstanceOf(AwaitWithTimeoutCall::class, $call);
    }

    public function testSideEffectReturnsASideEffectCall(): void
    {
        $call = Workflow::sideEffect(static fn (): int => 7);

        $this->assertInstanceOf(SideEffectCall::class, $call);
    }

    public function testUuidHelpersReturnSideEffectCalls(): void
    {
        $this->assertInstanceOf(SideEffectCall::class, Workflow::uuid4());
        $this->assertInstanceOf(SideEffectCall::class, Workflow::uuid7());
    }

    public function testContinueAsNewReturnsAContinueAsNewCall(): void
    {
        $call = Workflow::continueAsNew('arg1', 'arg2');

        $this->assertInstanceOf(ContinueAsNewCall::class, $call);
    }

    public function testGetVersionReturnsAVersionCall(): void
    {
        $call = Workflow::getVersion('change-one');

        $this->assertInstanceOf(VersionCall::class, $call);
    }

    public function testPatchedReturnsAVersionCallWithBooleanResultKind(): void
    {
        $call = Workflow::patched('change-one');

        $this->assertInstanceOf(VersionCall::class, $call);
        $this->assertSame('change-one', $call->changeId);
        $this->assertSame(WorkflowStub::DEFAULT_VERSION, $call->minSupported);
        $this->assertSame(1, $call->maxSupported);
        $this->assertTrue($call->resolveValue(1));
        $this->assertFalse($call->resolveValue(WorkflowStub::DEFAULT_VERSION));
    }

    public function testDeprecatePatchReturnsAVersionCallWithNullResultKind(): void
    {
        $call = Workflow::deprecatePatch('change-one');

        $this->assertInstanceOf(VersionCall::class, $call);
        $this->assertSame('change-one', $call->changeId);
        $this->assertNull($call->resolveValue(1));
    }

    public function testAllReturnsAnAllCall(): void
    {
        $call = Workflow::all([
            Workflow::activity('App\\Activities\\A'),
            Workflow::activity('App\\Activities\\B'),
        ]);

        $this->assertInstanceOf(AllCall::class, $call);
    }

    public function testParallelAliasesAll(): void
    {
        $call = Workflow::parallel([Workflow::activity('App\\Activities\\A')]);

        $this->assertInstanceOf(AllCall::class, $call);
    }

    public function testParallelHelperAliasesAll(): void
    {
        $call = parallel([activity('App\\Activities\\A')]);

        $this->assertInstanceOf(AllCall::class, $call);
    }

    public function testSelectPreservesStableMemberKeysAndSupportsEveryDurableWaitKind(): void
    {
        $call = select([
            'activity' => activity('App\\Activities\\A'),
            'deadline' => Workflow::timer(30),
            'signal' => Workflow::awaitSignal('resolved'),
            'condition' => Workflow::await(static fn (): bool => false),
            'child' => Workflow::child('App\\Workflows\\Child'),
        ]);

        $this->assertInstanceOf(SelectCall::class, $call);
        $this->assertSame(['activity', 'deadline', 'signal', 'condition', 'child'], $call->keys);

        $descriptors = $call->leafDescriptors(7);
        $this->assertCount(5, $descriptors);
        $this->assertSame([0, 1, 2, 3, 4], array_column($descriptors, 'offset'));

        foreach ($descriptors as $index => $descriptor) {
            $outer = $descriptor['group_path'][0];
            $this->assertSame('select-calls:7:5', $outer['parallel_group_id']);
            $this->assertSame('select', $outer['parallel_group_mode']);
            $this->assertSame($call->keys[$index], $outer['selection_member_key']);
            $this->assertSame($index, $outer['selection_member_index']);
            $this->assertSame(7 + $index, $outer['selection_member_base_sequence']);
            $this->assertSame(1, $outer['selection_member_size']);
        }
    }

    public function testSelectRejectsAnEmptyOperationSet(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('requires at least one durable operation');

        Workflow::select([]);
    }

    public function testSelectRejectsDuplicateGeneratorKeys(): void
    {
        $calls = (static function (): \Generator {
            yield 'duplicate' => Workflow::activity('App\\Activities\\A');
            yield 'duplicate' => Workflow::activity('App\\Activities\\B');
        })();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('member key [duplicate] is duplicated');

        Workflow::select($calls);
    }

    public function testSelectRejectsEmptyStringAndNegativeIntegerKeys(): void
    {
        foreach (['', -1] as $key) {
            try {
                Workflow::select([
                    $key => Workflow::activity('App\\Activities\\A'),
                ]);
            } catch (\LogicException $exception) {
                $this->assertStringContainsString(
                    'must be a non-empty string or non-negative integer',
                    $exception->getMessage(),
                );

                continue;
            }

            $this->fail(sprintf('Selection accepted the invalid member key [%s].', (string) $key));
        }
    }

    public function testSelectPreservesValidNamedAndNumericKeys(): void
    {
        $call = Workflow::select([
            0 => Workflow::activity('App\\Activities\\A'),
            'named' => Workflow::timer(1),
        ]);

        $this->assertSame([0, 'named'], $call->keys);
    }

    public function testSelectRejectsNonScalarIterableKeys(): void
    {
        $calls = new class(Workflow::activity('App\\Activities\\A')) implements \Iterator {
            private bool $valid = true;

            public function __construct(
                private readonly ActivityCall $call
            ) {
            }

            public function current(): ActivityCall
            {
                return $this->call;
            }

            public function key(): object
            {
                return new \stdClass();
            }

            public function next(): void
            {
                $this->valid = false;
            }

            public function rewind(): void
            {
                $this->valid = true;
            }

            public function valid(): bool
            {
                return $this->valid;
            }
        };

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('member keys must be integers or strings');

        Workflow::select($calls);
    }

    public function testSelectTreatsANestedAllCallAsOneDurableMember(): void
    {
        $call = Workflow::select([
            'lookup' => Workflow::all([
                Workflow::activity('App\\Activities\\A'),
                Workflow::activity('App\\Activities\\B'),
            ]),
            'deadline' => Workflow::timer(5),
        ]);

        $descriptors = $call->leafDescriptors(11);

        $this->assertSame([0, 1, 2], array_column($descriptors, 'offset'));
        $this->assertSame([2, 2, 1], array_map(
            static fn (array $descriptor): int => $descriptor['group_path'][0]['selection_member_size'],
            $descriptors,
        ));
        $this->assertSame(['lookup', 'lookup', 'deadline'], array_map(
            static fn (array $descriptor): int|string => $descriptor['group_path'][0]['selection_member_key'],
            $descriptors,
        ));
        $this->assertSame('parallel-activities:11:2', $descriptors[0]['group_path'][1]['parallel_group_id']);
    }

    public function testResolvedSelectionCarriesWinnerAndAddressableLosers(): void
    {
        $call = Workflow::select([
            'work' => Workflow::activity('App\\Activities\\A'),
            'deadline' => Workflow::timer(5),
        ]);

        $result = $call->resolved(3, [
            'member_index' => 1,
            'operation_identity' => 'timer-42',
        ], [
            1 => true,
        ], [], [
            3 => 'activity-41',
        ]);

        $this->assertInstanceOf(SelectionResult::class, $result);
        $this->assertSame('deadline', $result->key);
        $this->assertSame('timer', $result->kind);
        $this->assertSame('timer-42', $result->identity);
        $this->assertTrue($result->result());
        $this->assertSame(['work'], array_keys($result->remaining()));
        $this->assertSame(3, $result->handles['work']->baseSequence);
        $result->handles['work']->cancel();
        $this->assertTrue(true);
    }

    public function testResolvedSelectionRejectsMissingDurableDirectMemberIdentity(): void
    {
        $call = Workflow::select([
            'work' => Workflow::activity('App\\Activities\\A'),
            'deadline' => Workflow::timer(5),
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('missing its durable scheduled or open operation identity');

        $call->resolved(3, [
            'member_index' => 1,
            'operation_identity' => 'timer-42',
        ], [
            1 => true,
        ]);
    }

    public function testUpsertMemoSuspendsWithAnUpsertMemoCall(): void
    {
        // Outside a fiber, suspend returns the call instance; upsertMemo is
        // typed void, so we can only assert it does not error.
        Workflow::upsertMemo([
            'stage' => 'validated',
        ]);
        $this->assertTrue(true);

        // And that a raw call construction matches the suspend-returned type.
        $this->assertInstanceOf(UpsertMemoCall::class, new UpsertMemoCall([
            'a' => 1,
        ]));
    }

    public function testUpsertSearchAttributesSuspendsWithTheRightCall(): void
    {
        Workflow::upsertSearchAttributes([
            'region' => 'us',
        ]);
        $this->assertTrue(true);

        $this->assertInstanceOf(UpsertSearchAttributesCall::class, new UpsertSearchAttributesCall([
            'a' => 1,
        ]),);
    }

    public function testEveryFacadeMethodIsStatic(): void
    {
        $facadeMethods = [
            'activity',
            'now',
            'executeActivity',
            'localActivity',
            'executeLocalActivity',
            'workerSession',
            'child',
            'executeChildWorkflow',
            'async',
            'all',
            'parallel',
            'select',
            'await',
            'awaitWithTimeout',
            'awaitSignal',
            'timer',
            'sideEffect',
            'uuid4',
            'uuid7',
            'continueAsNew',
            'getVersion',
            'patched',
            'deprecatePatch',
            'upsertMemo',
            'upsertSearchAttributes',
            'seconds',
            'minutes',
            'hours',
            'days',
            'weeks',
            'months',
            'years',
        ];

        foreach ($facadeMethods as $method) {
            $reflection = new ReflectionMethod(Workflow::class, $method);
            $this->assertTrue(
                $reflection->isStatic(),
                "Workflow::{$method}() must be static to be usable from workflow code without a call site.",
            );
            $this->assertTrue($reflection->isPublic(), "Workflow::{$method}() must be public.");
        }
    }
}
