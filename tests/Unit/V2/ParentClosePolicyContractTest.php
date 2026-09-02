<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Workflow\V2\Enums\ParentClosePolicy;
use Workflow\V2\Support\ChildWorkflowOptions;
use Workflow\V2\Support\ParentClosePolicyEnforcer;
use Workflow\V2\Support\WorkflowExecutor;
use Workflow\V2\Workflow;
use Workflow\V2\WorkflowStub;

/**
 * Pins the parent-disposition matrix documented in the
 * "Child Parent-Close Policy Contract" section of
 * docs/workflow/plan.md. Every documented terminal disposition must
 * route through ParentClosePolicyEnforcer::enforce, except the
 * continue-as-new disposition which transfers child links instead.
 */
final class ParentClosePolicyContractTest extends TestCase
{
    public function testDefaultPolicyIsAbandonOnSourceCompatibleOptions(): void
    {
        $options = new ChildWorkflowOptions();

        $this->assertSame(
            ParentClosePolicy::Abandon,
            $options->parentClosePolicy,
            'ChildWorkflowOptions must default to ParentClosePolicy::Abandon.',
        );
    }

    public function testWorkflowFacadeDocblockNamesAbandonDefault(): void
    {
        $reflection = new ReflectionClass(Workflow::class);

        foreach (['child', 'executeChildWorkflow'] as $method) {
            $docComment = $reflection->getMethod($method)
                ->getDocComment();

            $this->assertIsString(
                $docComment,
                sprintf('Workflow::%s must carry a docblock that names the default policy.', $method),
            );
            $this->assertStringContainsString(
                'ParentClosePolicy::Abandon',
                $docComment,
                sprintf(
                    'Workflow::%s docblock must name ParentClosePolicy::Abandon as the default for source-compatible callers.',
                    $method,
                ),
            );
        }
    }

    public function testCompletionFailureAndTimeoutPathsCallEnforcer(): void
    {
        $source = $this->classSource(WorkflowExecutor::class);

        foreach (['completeRun', 'failRun', 'timeoutRun'] as $method) {
            $body = $this->methodBody($source, $method);

            $this->assertStringContainsString(
                'ParentClosePolicyEnforcer::enforce',
                $body,
                sprintf(
                    'Disposition matrix: WorkflowExecutor::%s must call ParentClosePolicyEnforcer::enforce.',
                    $method,
                ),
            );
        }
    }

    public function testContinueAsNewDoesNotCallEnforcer(): void
    {
        $source = $this->classSource(WorkflowExecutor::class);
        $body = $this->methodBody($source, 'continueAsNew');

        $this->assertStringNotContainsString(
            'ParentClosePolicyEnforcer::enforce',
            $body,
            'Disposition matrix: continueAsNew must transfer child links instead of enforcing parent-close policy.',
        );
        $this->assertStringContainsString(
            "'parent_close_policy' => \$parentChildLink->parent_close_policy",
            $body,
            'continueAsNew must copy the per-child parent_close_policy snapshot onto the rewritten child link.',
        );
    }

    public function testCancellationAndTerminationPathCallsEnforcer(): void
    {
        $source = $this->classSource(WorkflowStub::class);
        $body = $this->methodBody($source, 'attemptTerminalCommand');

        $this->assertStringContainsString(
            'ParentClosePolicyEnforcer::enforce',
            $body,
            'Disposition matrix: WorkflowStub::attemptTerminalCommand must enforce policy for cancel and terminate.',
        );
    }

    public function testEnforcerEmitsAppliedAndFailedHistoryEvents(): void
    {
        $source = $this->classSource(ParentClosePolicyEnforcer::class);

        $this->assertStringContainsString(
            'HistoryEventType::ParentClosePolicyApplied',
            $source,
            'Enforcer must record ParentClosePolicyApplied history events on success.',
        );
        $this->assertStringContainsString(
            'HistoryEventType::ParentClosePolicyFailed',
            $source,
            'Enforcer must record ParentClosePolicyFailed history events on rejection.',
        );
    }

    /**
     * @param class-string $class
     */
    private function classSource(string $class): string
    {
        $reflection = new ReflectionClass($class);
        $path = $reflection->getFileName();

        $this->assertNotFalse($path, sprintf('Could not resolve source path for %s.', $class));

        $contents = file_get_contents($path);

        $this->assertIsString($contents, sprintf('Could not read source for %s.', $class));

        return $contents;
    }

    private function methodBody(string $source, string $method): string
    {
        $pattern = sprintf('/function\s+%s\b[^{]*(\{(?:[^{}]++|(?1))*+\})/s', preg_quote($method, '/'));

        if (preg_match($pattern, $source, $matches) !== 1) {
            $this->fail(sprintf('Could not locate method body for %s.', $method));
        }

        return $matches[1];
    }
}
