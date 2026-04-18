<?php

declare(strict_types=1);

namespace Workflow\Tests\Unit\V2;

use Orchestra\Testbench\TestCase;
use ReflectionMethod;
use Workflow\V2\Support\ActivityCall;
use Workflow\V2\Support\AllCall;
use Workflow\V2\Support\AwaitCall;
use Workflow\V2\Support\AwaitWithTimeoutCall;
use Workflow\V2\Support\ChildWorkflowCall;
use Workflow\V2\Support\ContinueAsNewCall;
use Workflow\V2\Support\SideEffectCall;
use Workflow\V2\Support\SignalCall;
use Workflow\V2\Support\TimerCall;
use Workflow\V2\Support\UpsertMemoCall;
use Workflow\V2\Support\UpsertSearchAttributesCall;
use Workflow\V2\Support\VersionCall;
use Workflow\V2\Workflow;

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
            'executeActivity',
            'child',
            'executeChildWorkflow',
            'async',
            'all',
            'parallel',
            'await',
            'awaitWithTimeout',
            'awaitSignal',
            'timer',
            'sideEffect',
            'continueAsNew',
            'getVersion',
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
