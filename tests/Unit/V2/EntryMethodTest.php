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
    public function testHandleIsPreferredWhenDeclared(): void
    {
        $this->assertSame('handle', EntryMethod::forWorkflow(HandleEntryWorkflow::class)->getName());
        $this->assertSame('handle', EntryMethod::forActivity(HandleEntryActivity::class)->getName());
        $this->assertSame('canonical', EntryMethod::describeWorkflow(HandleEntryWorkflow::class)['mode']);
        $this->assertSame(
            HandleEntryWorkflow::class,
            EntryMethod::describeWorkflow(HandleEntryWorkflow::class)['declared_on']
        );
    }

    public function testExecuteFallsBackForCompatibility(): void
    {
        $this->assertSame('execute', EntryMethod::forWorkflow(ExecuteEntryWorkflow::class)->getName());
        $this->assertSame('execute', EntryMethod::forActivity(ExecuteEntryActivity::class)->getName());
        $this->assertSame('compatibility', EntryMethod::describeWorkflow(ExecuteEntryWorkflow::class)['mode']);
        $this->assertSame(
            ExecuteEntryWorkflow::class,
            EntryMethod::describeWorkflow(ExecuteEntryWorkflow::class)['declared_on']
        );
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
        $this->expectExceptionMessage('must declare only one entry method');

        EntryMethod::forWorkflow(DualEntryWorkflow::class);
    }

    public function testMissingEntryMethodRaisesAnError(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'must declare a public handle() method or, for compatibility, a public execute() method'
        );

        EntryMethod::forWorkflow(MissingEntryWorkflow::class);
    }

    public function testMixedWorkflowHierarchyRaisesAnError(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('cannot mix handle() and execute() across its inheritance chain');

        EntryMethod::forWorkflow(MixedWorkflowChild::class);
    }

    public function testMixedActivityHierarchyRaisesAnError(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('cannot mix handle() and execute() across its inheritance chain');

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
