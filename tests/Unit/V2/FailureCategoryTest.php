<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Database\QueryException;
use Illuminate\Queue\MaxAttemptsExceededException;
use PDOException;
use RuntimeException;
use Tests\TestCase;
use Workflow\Exceptions\NonRetryableException;
use Workflow\Exceptions\NonRetryableExceptionContract;
use Workflow\V2\Enums\FailureCategory;
use Workflow\V2\Enums\StructuralLimitKind;
use Workflow\V2\Exceptions\StraightLineWorkflowRequiredException;
use Workflow\V2\Exceptions\StructuralLimitExceededException;
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
            'structural_limit',
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

    // ---------------------------------------------------------------
    //  FailureFactory::classifyFromStrings() — string-based routing
    // ---------------------------------------------------------------

    public function testClassifyFromStringsActivityPropagation(): void
    {
        $category = FailureFactory::classifyFromStrings('activity', 'activity_execution', null, null);

        $this->assertSame(FailureCategory::Activity, $category);
    }

    public function testClassifyFromStringsChildPropagation(): void
    {
        $category = FailureFactory::classifyFromStrings('child', 'child_workflow_run', null, null);

        $this->assertSame(FailureCategory::ChildWorkflow, $category);
    }

    public function testClassifyFromStringsCancelledPropagation(): void
    {
        $category = FailureFactory::classifyFromStrings('cancelled', 'workflow_run', null, null);

        $this->assertSame(FailureCategory::Cancelled, $category);
    }

    public function testClassifyFromStringsTerminatedPropagation(): void
    {
        $category = FailureFactory::classifyFromStrings('terminated', 'workflow_run', null, null);

        $this->assertSame(FailureCategory::Terminated, $category);
    }

    public function testClassifyFromStringsUnsupportedYieldAsTaskFailure(): void
    {
        $category = FailureFactory::classifyFromStrings(
            'terminal',
            'workflow_run',
            UnsupportedWorkflowYieldException::class,
            'Unsupported yield type',
        );

        $this->assertSame(FailureCategory::TaskFailure, $category);
    }

    public function testClassifyFromStringsStraightLineRequiredAsTaskFailure(): void
    {
        $category = FailureFactory::classifyFromStrings(
            'terminal',
            'workflow_run',
            StraightLineWorkflowRequiredException::class,
            'Straight-line workflow required',
        );

        $this->assertSame(FailureCategory::TaskFailure, $category);
    }

    public function testClassifyFromStringsQueryExceptionAsInternal(): void
    {
        $category = FailureFactory::classifyFromStrings(
            'terminal',
            'workflow_run',
            QueryException::class,
            'Connection lost',
        );

        $this->assertSame(FailureCategory::Internal, $category);
    }

    public function testClassifyFromStringsPdoExceptionAsInternal(): void
    {
        $category = FailureFactory::classifyFromStrings(
            'terminal',
            'workflow_run',
            PDOException::class,
            'SQLSTATE[HY000]: General error',
        );

        $this->assertSame(FailureCategory::Internal, $category);
    }

    public function testClassifyFromStringsMaxAttemptsAsInternal(): void
    {
        $category = FailureFactory::classifyFromStrings(
            'terminal',
            'workflow_run',
            MaxAttemptsExceededException::class,
            'Job exceeded max attempts',
        );

        $this->assertSame(FailureCategory::Internal, $category);
    }

    public function testClassifyFromStringsTimeoutMessage(): void
    {
        $category = FailureFactory::classifyFromStrings(
            'terminal',
            'workflow_run',
            RuntimeException::class,
            'Workflow execution timed out',
        );

        $this->assertSame(FailureCategory::Timeout, $category);
    }

    public function testClassifyFromStringsExecutionDeadlineMessage(): void
    {
        $category = FailureFactory::classifyFromStrings(
            'terminal',
            'workflow_run',
            RuntimeException::class,
            'Workflow execution deadline reached',
        );

        $this->assertSame(FailureCategory::Timeout, $category);
    }

    public function testClassifyFromStringsRunDeadlineMessage(): void
    {
        $category = FailureFactory::classifyFromStrings(
            'terminal',
            'workflow_run',
            RuntimeException::class,
            'Run deadline exceeded',
        );

        $this->assertSame(FailureCategory::Timeout, $category);
    }

    public function testClassifyFromStringsApplicationFallback(): void
    {
        $category = FailureFactory::classifyFromStrings(
            'terminal',
            'workflow_run',
            RuntimeException::class,
            'Something went wrong',
        );

        $this->assertSame(FailureCategory::Application, $category);
    }

    public function testClassifyFromStringsNullClassAndMessageDefaultsToApplication(): void
    {
        $category = FailureFactory::classifyFromStrings('terminal', 'workflow_run', null, null);

        $this->assertSame(FailureCategory::Application, $category);
    }

    public function testClassifyFromStringsTimeoutMessageWithNullClass(): void
    {
        $category = FailureFactory::classifyFromStrings(
            'terminal',
            'workflow_run',
            null,
            'Workflow execution timed out',
        );

        $this->assertSame(FailureCategory::Timeout, $category);
    }

    public function testClassifyFromStringsUnknownPropagationDefaultsToApplication(): void
    {
        $category = FailureFactory::classifyFromStrings('unknown', 'unknown', null, null);

        $this->assertSame(FailureCategory::Application, $category);
    }

    // ---------------------------------------------------------------
    //  FailureFactory::classify() — structural limit classification
    // ---------------------------------------------------------------

    public function testStructuralLimitExceptionClassifiesAsStructuralLimit(): void
    {
        $throwable = StructuralLimitExceededException::pendingActivityCount(2000, 2000);

        $category = FailureFactory::classify('terminal', 'workflow_run', $throwable);

        $this->assertSame(FailureCategory::StructuralLimit, $category);
    }

    public function testStructuralLimitExceptionTakesPriorityOverMessagePatterns(): void
    {
        $throwable = StructuralLimitExceededException::payloadSize(3000000, 2097152);

        $category = FailureFactory::classify('terminal', 'workflow_run', $throwable);

        $this->assertSame(FailureCategory::StructuralLimit, $category);
    }

    public function testStructuralLimitMessageClassifiesAsStructuralLimitViaFallback(): void
    {
        $throwable = new RuntimeException('Structural limit exceeded: 2000 pending activities (limit 2000).');

        $category = FailureFactory::classify('terminal', 'workflow_run', $throwable);

        $this->assertSame(FailureCategory::StructuralLimit, $category);
    }

    public function testClassifyFromStringsStructuralLimitException(): void
    {
        $category = FailureFactory::classifyFromStrings(
            'terminal',
            'workflow_run',
            StructuralLimitExceededException::class,
            'Structural limit exceeded: 2000 pending activities (limit 2000).',
        );

        $this->assertSame(FailureCategory::StructuralLimit, $category);
    }

    public function testClassifyFromStringsStructuralLimitMessageFallback(): void
    {
        $category = FailureFactory::classifyFromStrings(
            'terminal',
            'workflow_run',
            RuntimeException::class,
            'Structural limit exceeded: payload size 3000000 bytes (limit 2097152 bytes).',
        );

        $this->assertSame(FailureCategory::StructuralLimit, $category);
    }

    // ---------------------------------------------------------------
    //  StructuralLimitExceededException — factory methods
    // ---------------------------------------------------------------

    public function testStructuralLimitExceptionCarriesMetadata(): void
    {
        $exception = StructuralLimitExceededException::pendingActivityCount(50, 25);

        $this->assertSame(StructuralLimitKind::PendingActivityCount, $exception->limitKind);
        $this->assertSame(50, $exception->currentValue);
        $this->assertSame(25, $exception->configuredLimit);
        $this->assertStringContainsString('50 pending activities', $exception->getMessage());
    }

    public function testStructuralLimitExceptionPayloadSizeFactory(): void
    {
        $exception = StructuralLimitExceededException::payloadSize(3000000, 2097152);

        $this->assertSame(StructuralLimitKind::PayloadSize, $exception->limitKind);
        $this->assertSame(3000000, $exception->currentValue);
        $this->assertSame(2097152, $exception->configuredLimit);
    }

    public function testStructuralLimitExceptionCommandBatchSizeFactory(): void
    {
        $exception = StructuralLimitExceededException::commandBatchSize(1500, 1000);

        $this->assertSame(StructuralLimitKind::CommandBatchSize, $exception->limitKind);
        $this->assertSame(1500, $exception->currentValue);
        $this->assertSame(1000, $exception->configuredLimit);
    }

    // ---------------------------------------------------------------
    //  FailureFactory::isNonRetryable() — throwable-based detection
    // ---------------------------------------------------------------

    public function testNonRetryableExceptionContractIsDetected(): void
    {
        $throwable = new NonRetryableException('Payment permanently declined');

        $this->assertTrue(FailureFactory::isNonRetryable($throwable));
    }

    public function testRegularExceptionIsNotNonRetryable(): void
    {
        $throwable = new RuntimeException('Temporary network error');

        $this->assertFalse(FailureFactory::isNonRetryable($throwable));
    }

    public function testNullThrowableIsNotNonRetryable(): void
    {
        $this->assertFalse(FailureFactory::isNonRetryable(null));
    }

    public function testCustomNonRetryableExceptionIsDetected(): void
    {
        $throwable = new class('Custom non-retryable') extends RuntimeException implements NonRetryableExceptionContract {};

        $this->assertTrue(FailureFactory::isNonRetryable($throwable));
    }

    // ---------------------------------------------------------------
    //  FailureFactory::isNonRetryableFromStrings() — string detection
    // ---------------------------------------------------------------

    public function testNonRetryableFromStringsDetectsKnownClass(): void
    {
        $this->assertTrue(FailureFactory::isNonRetryableFromStrings(NonRetryableException::class));
    }

    public function testNonRetryableFromStringsReturnsFalseForRegularException(): void
    {
        $this->assertFalse(FailureFactory::isNonRetryableFromStrings(RuntimeException::class));
    }

    public function testNonRetryableFromStringsReturnsFalseForNull(): void
    {
        $this->assertFalse(FailureFactory::isNonRetryableFromStrings(null));
    }

    public function testNonRetryableFromStringsReturnsFalseForEmptyString(): void
    {
        $this->assertFalse(FailureFactory::isNonRetryableFromStrings(''));
    }

    public function testNonRetryableFromStringsReturnsFalseForUnresolvableClass(): void
    {
        $this->assertFalse(FailureFactory::isNonRetryableFromStrings('App\\Exceptions\\DeletedExceptionClass'));
    }
}
