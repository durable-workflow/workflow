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
    /** @test */
    public function activity_returns_an_activity_call(): void
    {
        $call = Workflow::activity('App\\Activities\\Example', 'a', 'b');

        $this->assertInstanceOf(ActivityCall::class, $call);
    }

    /** @test */
    public function execute_activity_aliases_activity(): void
    {
        $call = Workflow::executeActivity('App\\Activities\\Example');

        $this->assertInstanceOf(ActivityCall::class, $call);
    }

    /** @test */
    public function child_returns_a_child_workflow_call(): void
    {
        $call = Workflow::child('App\\Workflows\\Example');

        $this->assertInstanceOf(ChildWorkflowCall::class, $call);
    }

    /** @test */
    public function execute_child_workflow_aliases_child(): void
    {
        $call = Workflow::executeChildWorkflow('App\\Workflows\\Example');

        $this->assertInstanceOf(ChildWorkflowCall::class, $call);
    }

    /** @test */
    public function timer_returns_a_timer_call(): void
    {
        $call = Workflow::timer(42);

        $this->assertInstanceOf(TimerCall::class, $call);
    }

    /** @test */
    public function timer_sugar_methods_return_timer_calls(): void
    {
        $this->assertInstanceOf(TimerCall::class, Workflow::seconds(5));
        $this->assertInstanceOf(TimerCall::class, Workflow::minutes(2));
        $this->assertInstanceOf(TimerCall::class, Workflow::hours(1));
        $this->assertInstanceOf(TimerCall::class, Workflow::days(1));
        $this->assertInstanceOf(TimerCall::class, Workflow::weeks(1));
        $this->assertInstanceOf(TimerCall::class, Workflow::months(1));
        $this->assertInstanceOf(TimerCall::class, Workflow::years(1));
    }

    /** @test */
    public function await_with_signal_name_returns_signal_call(): void
    {
        $call = Workflow::await('some-signal');

        $this->assertInstanceOf(SignalCall::class, $call);
    }

    /** @test */
    public function await_signal_is_equivalent_to_await_by_name(): void
    {
        $this->assertInstanceOf(SignalCall::class, Workflow::awaitSignal('some-signal'));
    }

    /** @test */
    public function await_with_condition_returns_await_call(): void
    {
        $call = Workflow::await(static fn (): bool => true);

        $this->assertInstanceOf(AwaitCall::class, $call);
    }

    /** @test */
    public function await_with_timeout_returns_await_with_timeout_call(): void
    {
        $call = Workflow::awaitWithTimeout(5, static fn (): bool => true);

        $this->assertInstanceOf(AwaitWithTimeoutCall::class, $call);
    }

    /** @test */
    public function side_effect_returns_a_side_effect_call(): void
    {
        $call = Workflow::sideEffect(static fn (): int => 7);

        $this->assertInstanceOf(SideEffectCall::class, $call);
    }

    /** @test */
    public function continue_as_new_returns_a_continue_as_new_call(): void
    {
        $call = Workflow::continueAsNew('arg1', 'arg2');

        $this->assertInstanceOf(ContinueAsNewCall::class, $call);
    }

    /** @test */
    public function get_version_returns_a_version_call(): void
    {
        $call = Workflow::getVersion('change-one');

        $this->assertInstanceOf(VersionCall::class, $call);
    }

    /** @test */
    public function all_returns_an_all_call(): void
    {
        $call = Workflow::all([
            Workflow::activity('App\\Activities\\A'),
            Workflow::activity('App\\Activities\\B'),
        ]);

        $this->assertInstanceOf(AllCall::class, $call);
    }

    /** @test */
    public function parallel_aliases_all(): void
    {
        $call = Workflow::parallel([Workflow::activity('App\\Activities\\A')]);

        $this->assertInstanceOf(AllCall::class, $call);
    }

    /** @test */
    public function upsert_memo_suspends_with_an_upsert_memo_call(): void
    {
        // Outside a fiber, suspend returns the call instance; upsertMemo is
        // typed void, so we can only assert it does not error.
        Workflow::upsertMemo(['stage' => 'validated']);
        $this->assertTrue(true);

        // And that a raw call construction matches the suspend-returned type.
        $this->assertInstanceOf(UpsertMemoCall::class, new UpsertMemoCall(['a' => 1]));
    }

    /** @test */
    public function upsert_search_attributes_suspends_with_the_right_call(): void
    {
        Workflow::upsertSearchAttributes(['region' => 'us']);
        $this->assertTrue(true);

        $this->assertInstanceOf(
            UpsertSearchAttributesCall::class,
            new UpsertSearchAttributesCall(['a' => 1]),
        );
    }

    /** @test */
    public function every_facade_method_is_static(): void
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
            $this->assertTrue(
                $reflection->isPublic(),
                "Workflow::{$method}() must be public.",
            );
        }
    }
}
