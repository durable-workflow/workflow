<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use RuntimeException;
use Tests\TestCase;
use Workflow\V2\Enums\FailureCategory;
use Workflow\V2\Support\FailureFactory;

final class FailureCategoryTest extends TestCase
{
    // ---------------------------------------------------------------
    //  FailureCategory enum values
    // ---------------------------------------------------------------

    public function testEnumContainsAllCanonicalCategories(): void
    {
        $expected = [
            'application',
            'cancelled',
            'terminated',
            'timeout',
            'activity',
            'child_workflow',
            'task_failure',
            'internal',
        ];

        $actual = array_map(
            static fn (FailureCategory $case): string => $case->value,
            FailureCategory::cases(),
        );

        foreach ($expected as $value) {
            $this->assertContains($value, $actual, "Missing canonical category: {$value}");
        }
    }

    public function testEnumCasesAreBackedByStringValues(): void
    {
        foreach (FailureCategory::cases() as $case) {
            $this->assertSame($case->value, FailureCategory::from($case->value)->value);
        }
    }

    // ---------------------------------------------------------------
    //  FailureFactory::classify()
    // ---------------------------------------------------------------

    public function testActivityPropagationClassifiesAsActivity(): void
    {
        $category = FailureFactory::classify('activity', 'activity_execution', new RuntimeException('failed'));

        $this->assertSame(FailureCategory::Activity, $category);
    }

    public function testChildPropagationClassifiesAsChildWorkflow(): void
    {
        $category = FailureFactory::classify('child', 'child_workflow_run', new RuntimeException('child failed'));

        $this->assertSame(FailureCategory::ChildWorkflow, $category);
    }

    public function testTerminalPropagationClassifiesAsApplication(): void
    {
        $category = FailureFactory::classify('terminal', 'workflow_run', new RuntimeException('workflow failed'));

        $this->assertSame(FailureCategory::Application, $category);
    }

    public function testUpdatePropagationClassifiesAsApplication(): void
    {
        $category = FailureFactory::classify('update', 'workflow_command', new RuntimeException('update failed'));

        $this->assertSame(FailureCategory::Application, $category);
    }

    public function testUnknownPropagationDefaultsToApplication(): void
    {
        $category = FailureFactory::classify('unknown', 'unknown', new RuntimeException('unknown'));

        $this->assertSame(FailureCategory::Application, $category);
    }

    public function testClassifyWorksWithoutThrowable(): void
    {
        $category = FailureFactory::classify('activity', 'activity_execution');

        $this->assertSame(FailureCategory::Activity, $category);
    }
}
