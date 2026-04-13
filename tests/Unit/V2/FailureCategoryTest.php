<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Database\QueryException;
use Illuminate\Queue\MaxAttemptsExceededException;
use PDOException;
use RuntimeException;
use Tests\TestCase;
use Workflow\V2\Enums\FailureCategory;
use Workflow\V2\Exceptions\StraightLineWorkflowRequiredException;
use Workflow\V2\Exceptions\UnsupportedWorkflowYieldException;
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
    //  FailureFactory::classify() — propagation-kind routing
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

    public function testCancelledPropagationClassifiesAsCancelled(): void
    {
        $category = FailureFactory::classify('cancelled', 'workflow_run', new RuntimeException('cancelled'));

        $this->assertSame(FailureCategory::Cancelled, $category);
    }

    public function testTerminatedPropagationClassifiesAsTerminated(): void
    {
        $category = FailureFactory::classify('terminated', 'workflow_run', new RuntimeException('terminated'));

        $this->assertSame(FailureCategory::Terminated, $category);
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

    // ---------------------------------------------------------------
    //  FailureFactory::classify() — throwable-based refinement
    // ---------------------------------------------------------------

    public function testUnsupportedYieldClassifiesAsTaskFailure(): void
    {
        $throwable = new UnsupportedWorkflowYieldException('Unsupported yield type');

        $category = FailureFactory::classify('terminal', 'workflow_run', $throwable);

        $this->assertSame(FailureCategory::TaskFailure, $category);
    }

    public function testStraightLineRequiredClassifiesAsTaskFailure(): void
    {
        $throwable = new StraightLineWorkflowRequiredException('Straight-line workflow required');

        $category = FailureFactory::classify('terminal', 'workflow_run', $throwable);

        $this->assertSame(FailureCategory::TaskFailure, $category);
    }

    public function testQueryExceptionClassifiesAsInternal(): void
    {
        $throwable = new QueryException('mysql', 'SELECT 1', [], new \Exception('Connection lost'));

        $category = FailureFactory::classify('terminal', 'workflow_run', $throwable);

        $this->assertSame(FailureCategory::Internal, $category);
    }

    public function testPdoExceptionClassifiesAsInternal(): void
    {
        $throwable = new PDOException('SQLSTATE[HY000]: General error');

        $category = FailureFactory::classify('terminal', 'workflow_run', $throwable);

        $this->assertSame(FailureCategory::Internal, $category);
    }

    public function testMaxAttemptsExceededClassifiesAsInternal(): void
    {
        $throwable = new MaxAttemptsExceededException('Job exceeded max attempts');

        $category = FailureFactory::classify('terminal', 'workflow_run', $throwable);

        $this->assertSame(FailureCategory::Internal, $category);
    }

    public function testTimeoutMessageClassifiesAsTimeout(): void
    {
        $throwable = new RuntimeException('Workflow execution timed out');

        $category = FailureFactory::classify('terminal', 'workflow_run', $throwable);

        $this->assertSame(FailureCategory::Timeout, $category);
    }

    public function testTimeoutExceededMessageClassifiesAsTimeout(): void
    {
        $throwable = new RuntimeException('Activity timeout exceeded');

        $category = FailureFactory::classify('terminal', 'workflow_run', $throwable);

        $this->assertSame(FailureCategory::Timeout, $category);
    }

    public function testExecutionDeadlineMessageClassifiesAsTimeout(): void
    {
        $throwable = new RuntimeException('Workflow execution deadline reached');

        $category = FailureFactory::classify('terminal', 'workflow_run', $throwable);

        $this->assertSame(FailureCategory::Timeout, $category);
    }

    public function testRunDeadlineMessageClassifiesAsTimeout(): void
    {
        $throwable = new RuntimeException('Run deadline exceeded');

        $category = FailureFactory::classify('terminal', 'workflow_run', $throwable);

        $this->assertSame(FailureCategory::Timeout, $category);
    }

    public function testTaskFailureClassificationAlsoWorksForUpdatePropagation(): void
    {
        $throwable = new UnsupportedWorkflowYieldException('Unsupported yield in update handler');

        $category = FailureFactory::classify('update', 'workflow_command', $throwable);

        $this->assertSame(FailureCategory::TaskFailure, $category);
    }

    public function testInternalClassificationAlsoWorksForUpdatePropagation(): void
    {
        $throwable = new PDOException('Connection reset');

        $category = FailureFactory::classify('update', 'workflow_command', $throwable);

        $this->assertSame(FailureCategory::Internal, $category);
    }

    public function testTerminalWithNullThrowableDefaultsToApplication(): void
    {
        $category = FailureFactory::classify('terminal', 'workflow_run');

        $this->assertSame(FailureCategory::Application, $category);
    }

    public function testCancelledPropagationIgnoresThrowableType(): void
    {
        $category = FailureFactory::classify('cancelled', 'workflow_run', new PDOException('irrelevant'));

        $this->assertSame(FailureCategory::Cancelled, $category);
    }

    public function testTerminatedPropagationIgnoresThrowableType(): void
    {
        $category = FailureFactory::classify('terminated', 'workflow_run', new PDOException('irrelevant'));

        $this->assertSame(FailureCategory::Terminated, $category);
    }
}
