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
    }

    public function testExecuteFallsBackForCompatibility(): void
    {
        $this->assertSame('execute', EntryMethod::forWorkflow(ExecuteEntryWorkflow::class)->getName());
        $this->assertSame('execute', EntryMethod::forActivity(ExecuteEntryActivity::class)->getName());
    }

    public function testEntryMethodCanBeInheritedFromParentWorkflow(): void
    {
        $this->assertSame('handle', EntryMethod::forWorkflow(InheritedHandleWorkflow::class)->getName());
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
        $this->expectExceptionMessage('must declare a public handle() method or, for compatibility, a public execute() method');

        EntryMethod::forWorkflow(MissingEntryWorkflow::class);
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
