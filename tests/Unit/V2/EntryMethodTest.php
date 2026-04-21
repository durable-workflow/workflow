<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use LogicException;
use Tests\TestCase;
use Workflow\V2\Activity;
use Workflow\V2\Support\EntryMethod;
use Workflow\V2\Workflow;

final class EntryMethodTest extends TestCase
{
    public function testHandleIsRequiredEntryMethod(): void
    {
        $this->assertSame('handle', EntryMethod::forWorkflow(HandleEntryWorkflow::class)->getName());
        $this->assertSame('handle', EntryMethod::forActivity(HandleEntryActivity::class)->getName());
        $this->assertSame('canonical', EntryMethod::describeWorkflow(HandleEntryWorkflow::class)['mode']);
        $this->assertSame(
            HandleEntryWorkflow::class,
            EntryMethod::describeWorkflow(HandleEntryWorkflow::class)['declared_on']
        );
    }

    public function testExecuteIsRejectedForWorkflow(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('execute() is not supported as a v2 entry method');

        EntryMethod::forWorkflow(ExecuteEntryWorkflow::class);
    }

    public function testExecuteIsRejectedForActivity(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('execute() is not supported as a v2 entry method');

        EntryMethod::forActivity(ExecuteEntryActivity::class);
    }

    public function testEntryMethodCanBeInheritedFromParentWorkflow(): void
    {
        $this->assertSame('handle', EntryMethod::forWorkflow(InheritedHandleWorkflow::class)->getName());
        $this->assertSame(
            HandleEntryWorkflow::class,
            EntryMethod::describeWorkflow(InheritedHandleWorkflow::class)['declared_on']
        );
    }

    public function testDeclaringBothEntryMethodsRaisesAnError(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('execute() is not supported as a v2 entry method');

        EntryMethod::forWorkflow(DualEntryWorkflow::class);
    }

    public function testMissingEntryMethodRaisesAnError(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('must declare a public handle() method');

        EntryMethod::forWorkflow(MissingEntryWorkflow::class);
    }

    public function testMixedWorkflowHierarchyRaisesAnError(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('execute() is not supported as a v2 entry method');

        EntryMethod::forWorkflow(MixedWorkflowChild::class);
    }

    public function testMixedActivityHierarchyRaisesAnError(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('execute() is not supported as a v2 entry method');

        EntryMethod::forActivity(MixedActivityChild::class);
    }
}

abstract class HandleEntryWorkflow extends Workflow
{
    public function handle(): mixed
    {
        return null;
    }
}

final class InheritedHandleWorkflow extends HandleEntryWorkflow
{
}

final class ExecuteEntryWorkflow extends Workflow
{
    public function execute(): mixed
    {
        return null;
    }
}

final class DualEntryWorkflow extends Workflow
{
    public function handle(): mixed
    {
        return null;
    }

    public function execute(): mixed
    {
        return null;
    }
}

final class MissingEntryWorkflow extends Workflow
{
}

final class HandleEntryActivity extends Activity
{
    public function handle(): mixed
    {
        return null;
    }
}

final class ExecuteEntryActivity extends Activity
{
    public function execute(): mixed
    {
        return null;
    }
}

abstract class MixedWorkflowParent extends Workflow
{
    public function handle(): mixed
    {
        return null;
    }
}

final class MixedWorkflowChild extends MixedWorkflowParent
{
    public function execute(): mixed
    {
        return null;
    }
}

abstract class MixedActivityParent extends Activity
{
    public function handle(): mixed
    {
        return null;
    }
}

final class MixedActivityChild extends MixedActivityParent
{
    public function execute(): mixed
    {
        return null;
    }
}
