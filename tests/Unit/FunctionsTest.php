<?php

declare(strict_types=1);

namespace Tests\Unit;

use React\Promise\PromiseInterface;
use Tests\Fixtures\TestActivity;
use Tests\Fixtures\TestChildWorkflow;
use Tests\TestCase;
use Workflow\SignalMethod;
use Workflow\Workflow;
use Workflow\WorkflowStub;
use function Workflow\{activity, all, async, await, awaitWithTimeout, child, continueAsNew, days, getVersion, hours, minutes, months, now, seconds, sideEffect, timer, weeks, years};

final class FunctionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WorkflowStub::fake();
    }

    public function testAwaitFunction(): void
    {
        $workflow = WorkflowStub::make(TestAwaitWorkflow::class);
        $workflow->start();

        $workflow->approve();

        $this->assertSame('approved', $workflow->output());
    }

    public function testTimerFunction(): void
    {
        WorkflowStub::mock(TestActivity::class, 'result');

        $workflow = WorkflowStub::make(TestTimerWorkflow::class);
        $workflow->start();

        $this->assertNull($workflow->output());

        $this->travel(2)
            ->seconds();
        $workflow->resume();

        $this->assertSame('result', $workflow->output());
    }

    public function testActivityFunction(): void
    {
        WorkflowStub::mock(TestActivity::class, 'activity_result');

        $workflow = WorkflowStub::make(TestActivityWorkflow::class);
        $workflow->start();

        WorkflowStub::assertDispatched(TestActivity::class);
        $this->assertSame('activity_result', $workflow->output());
    }

    public function testChildFunction(): void
    {
        WorkflowStub::mock(TestChildWorkflow::class, 'child_result');

        $workflow = WorkflowStub::make(TestChildFunctionWorkflow::class);
        $workflow->start();

        WorkflowStub::assertDispatched(TestChildWorkflow::class);
        $this->assertSame('child_result', $workflow->output());
    }

    public function testAllFunction(): void
    {
        WorkflowStub::mock(TestActivity::class, 'result1');

        $workflow = WorkflowStub::make(TestAllWorkflow::class);
        $workflow->start();

        $this->assertIsArray($workflow->output());
    }

    public function testAsyncFunction(): void
    {
        $promise = async(static fn () => 'test');
        $this->assertInstanceOf(PromiseInterface::class, $promise);
    }

    public function testNowFunction(): void
    {
        $this->travelTo(\now()->startOfSecond());

        $workflow = WorkflowStub::make(TestNowWorkflow::class);
        $workflow->start();

        $this->assertNull($workflow->output());

        $this->travel(1)
            ->seconds();
        $workflow->resume();

        $output = $workflow->output();
        $this->assertIsNumeric($output);
        $this->assertSame(1.0, (float) $output);
    }

    public function testSecondsFunction(): void
    {
        WorkflowStub::mock(TestActivity::class, 'result');

        $workflow = WorkflowStub::make(TestSecondsWorkflow::class);
        $workflow->start();

        $this->assertNull($workflow->output());

        $this->travel(30)
            ->seconds();
        $workflow->resume();

        $this->assertSame('result', $workflow->output());
    }

    public function testMinutesFunction(): void
    {
        WorkflowStub::mock(TestActivity::class, 'result');

        $workflow = WorkflowStub::make(TestMinutesWorkflow::class);
        $workflow->start();

        $this->assertNull($workflow->output());

        $this->travel(5)
            ->minutes();
        $workflow->resume();

        $this->assertSame('result', $workflow->output());
    }

    public function testHoursFunction(): void
    {
        WorkflowStub::mock(TestActivity::class, 'result');

        $workflow = WorkflowStub::make(TestHoursWorkflow::class);
        $workflow->start();

        $this->assertNull($workflow->output());

        $this->travel(2)
            ->hours();
        $workflow->resume();

        $this->assertSame('result', $workflow->output());
    }

    public function testDaysFunction(): void
    {
        WorkflowStub::mock(TestActivity::class, 'result');

        $workflow = WorkflowStub::make(TestDaysWorkflow::class);
        $workflow->start();

        $this->assertNull($workflow->output());

        $this->travel(1)
            ->days();
        $workflow->resume();

        $this->assertSame('result', $workflow->output());
    }

    public function testWeeksFunction(): void
    {
        WorkflowStub::mock(TestActivity::class, 'result');

        $workflow = WorkflowStub::make(TestWeeksWorkflow::class);
        $workflow->start();

        $this->assertNull($workflow->output());

        $this->travel(1)
            ->weeks();
        $workflow->resume();

        $this->assertSame('result', $workflow->output());
    }

    public function testMonthsFunction(): void
    {
        WorkflowStub::mock(TestActivity::class, 'result');

        $workflow = WorkflowStub::make(TestMonthsWorkflow::class);
        $workflow->start();

        $this->assertNull($workflow->output());

        $this->travel(1)
            ->months();
        $workflow->resume();

        $this->assertSame('result', $workflow->output());
    }

    public function testYearsFunction(): void
    {
        WorkflowStub::mock(TestActivity::class, 'result');

        $workflow = WorkflowStub::make(TestYearsWorkflow::class);
        $workflow->start();

        $this->assertNull($workflow->output());

        $this->travel(1)
            ->years();
        $workflow->resume();

        $this->assertSame('result', $workflow->output());
    }

    public function testAwaitWithTimeoutFunction(): void
    {
        $workflow = WorkflowStub::make(TestAwaitWithTimeoutWorkflow::class);
        $workflow->start();

        $workflow->approve();

        $this->assertSame('approved', $workflow->output());
    }

    public function testSideEffectFunction(): void
    {
        $workflow = WorkflowStub::make(TestSideEffectWorkflow::class);
        $workflow->start();

        $this->assertSame('side_effect_result', $workflow->output());
    }

    public function testContinueAsNewFunction(): void
    {
        $promise = continueAsNew();
        $this->assertInstanceOf(PromiseInterface::class, $promise);
    }

    public function testGetVersionFunction(): void
    {
        $workflow = WorkflowStub::make(TestGetVersionWorkflow::class);
        $workflow->start();

        $this->assertSame('completed', $workflow->output());
    }
}

class TestAwaitWorkflow extends Workflow
{
    public bool $approved = false;

    #[SignalMethod]
    public function approve(): void
    {
        $this->approved = true;
    }

    public function execute()
    {
        yield await(fn () => $this->approved);
        return 'approved';
    }
}

class TestTimerWorkflow extends Workflow
{
    public function execute()
    {
        yield timer(1);
        return yield activity(TestActivity::class);
    }
}

class TestActivityWorkflow extends Workflow
{
    public function execute()
    {
        return yield activity(TestActivity::class);
    }
}

class TestChildFunctionWorkflow extends Workflow
{
    public function execute()
    {
        return yield child(TestChildWorkflow::class);
    }
}

class TestAllWorkflow extends Workflow
{
    public function execute()
    {
        return yield all([activity(TestActivity::class), activity(TestActivity::class)]);
    }
}

class TestNowWorkflow extends Workflow
{
    public function execute()
    {
        $start = yield sideEffect(static fn () => now());

        yield timer(1);

        $end = yield sideEffect(static fn () => now());

        return $start->diffInSeconds($end);
    }
}

class TestSecondsWorkflow extends Workflow
{
    public function execute()
    {
        yield seconds(30);
        return yield activity(TestActivity::class);
    }
}

class TestMinutesWorkflow extends Workflow
{
    public function execute()
    {
        yield minutes(5);
        return yield activity(TestActivity::class);
    }
}

class TestHoursWorkflow extends Workflow
{
    public function execute()
    {
        yield hours(2);
        return yield activity(TestActivity::class);
    }
}

class TestDaysWorkflow extends Workflow
{
    public function execute()
    {
        yield days(1);
        return yield activity(TestActivity::class);
    }
}

class TestWeeksWorkflow extends Workflow
{
    public function execute()
    {
        yield weeks(1);
        return yield activity(TestActivity::class);
    }
}

class TestMonthsWorkflow extends Workflow
{
    public function execute()
    {
        yield months(1);
        return yield activity(TestActivity::class);
    }
}

class TestYearsWorkflow extends Workflow
{
    public function execute()
    {
        yield years(1);
        return yield activity(TestActivity::class);
    }
}

class TestAwaitWithTimeoutWorkflow extends Workflow
{
    public bool $approved = false;

    #[SignalMethod]
    public function approve(): void
    {
        $this->approved = true;
    }

    public function execute()
    {
        yield awaitWithTimeout(60, fn () => $this->approved);
        return 'approved';
    }
}

class TestSideEffectWorkflow extends Workflow
{
    public function execute()
    {
        return yield sideEffect(static fn () => 'side_effect_result');
    }
}

class TestContinueAsNewWorkflow extends Workflow
{
    public function execute()
    {
        return yield continueAsNew();
    }
}

class TestGetVersionWorkflow extends Workflow
{
    public function execute()
    {
        yield getVersion('test_change', 1, 2);
        return 'completed';
    }
}
