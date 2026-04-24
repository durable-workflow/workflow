<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\TestCase;
use Workflow\V2\Enums\CommandOutcome;
use Workflow\V2\Enums\CommandType;
use Workflow\V2\Enums\FailureCategory;
use Workflow\V2\Enums\ParentClosePolicy;

/**
 * Pins the v2 cancellation-scope contract documented in
 * docs/architecture/cancellation-scope.md. The doc is the single
 * reference used by product docs, CLI reasoning, Waterline
 * diagnostics, SDK documentation, and test coverage for the run-level
 * scope of cancel and terminate, the cooperative heartbeat flag for
 * activities, the three ParentClosePolicy values, and the complete
 * typed history event surface. Changes to any named guarantee must
 * update this test and the documented contract in the same change so
 * drift is reviewed deliberately.
 */
final class CancellationScopeDocumentationTest extends TestCase
{
    private const DOCUMENT = 'docs/architecture/cancellation-scope.md';

    private const REQUIRED_HEADINGS = [
        '# Workflow V2 Cancellation-Scope Contract',
        '## Scope',
        '## Terminology',
        '## Command types',
        '## Run-level scope',
        '## Propagation to open work',
        '### Open tasks',
        '### Open activity executions',
        '### Open timers',
        '### Open child links',
        '## Cooperative cancellation for activities',
        '## Parent-close policy enforcement',
        '## History event surface',
        '### On the parent run',
        '### On the child run',
        '## Consumers bound by this contract',
        '## Non-goals',
        '## Test strategy alignment',
        '## Changing this contract',
    ];

    private const REQUIRED_TERMS = [
        'Cancel',
        'Terminate',
        'Run-level scope',
        'Cooperative cancellation',
        'Parent-close policy',
    ];

    private const REQUIRED_COMMAND_TYPES = [
        "`CommandType::Cancel` (`'cancel'`)",
        "`CommandType::Terminate` (`'terminate'`)",
    ];

    private const REQUIRED_PARENT_CLOSE_POLICIES = [
        "`ParentClosePolicy::Abandon` (`'abandon'`)",
        "`ParentClosePolicy::RequestCancel` (`'request_cancel'`)",
        "`ParentClosePolicy::Terminate` (`'terminate'`)",
    ];

    private const REQUIRED_EXCEPTION_CLASSES = ['WorkflowCancelledException', 'WorkflowTerminatedException'];

    private const REQUIRED_HISTORY_EVENTS_PARENT = [
        'CancelRequested',
        'WorkflowCancelled',
        'TerminateRequested',
        'WorkflowTerminated',
        'ActivityCancelled',
        'TimerCancelled',
        'ParentClosePolicyApplied',
        'ParentClosePolicyFailed',
        'ChildRunCancelled',
        'ChildRunTerminated',
    ];

    private const REQUIRED_FAILURE_CATEGORIES = ["'cancelled'", "'terminated'"];

    private const REQUIRED_PROPAGATION_KINDS = ['`cancelled`', '`terminated`'];

    private const REQUIRED_OPEN_WORK_SECTIONS = [
        'Open tasks',
        'Open activity executions',
        'Open timers',
        'Open child links',
    ];

    private const REQUIRED_CONSUMER_CLASSES = [
        'WorkflowStub::attemptCancel',
        'WorkflowStub::attemptTerminate',
        'WorkflowExecutor',
        'ActivityOutcomeRecorder',
        'ActivityCancellation',
        'TimerCancellation',
        'ParentClosePolicyEnforcer',
        'DefaultActivityTaskBridge',
        'ChildCallService',
        'RunLineageView',
        'RunWaitView',
        'RunSummaryProjector',
    ];

    private const REQUIRED_NON_GOALS = [
        'Per-call cancellation scopes',
        'Cancelling a specific activity call',
        'Cancel-then-continue',
        'Silent child abandonment',
        'Overlapping cancel/terminate',
    ];

    private const REQUIRED_COOPERATIVE_FLAG = 'cancel_requested';

    public function testContractDocumentExistsAndDeclaresFrozenSections(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_HEADINGS as $heading) {
            $this->assertStringContainsString(
                $heading,
                $contents,
                sprintf('Cancellation-scope contract is missing heading %s.', $heading),
            );
        }
    }

    public function testContractDocumentDefinesEveryNamedTerm(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_TERMS as $term) {
            $this->assertStringContainsString(
                sprintf('**%s**', $term),
                $contents,
                sprintf('Contract must define term %s in the Terminology section.', $term),
            );
        }
    }

    public function testContractDocumentNamesCommandTypes(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_COMMAND_TYPES as $type) {
            $this->assertStringContainsString(
                $type,
                $contents,
                sprintf('Contract must name command type %s.', $type),
            );
        }
    }

    public function testContractDocumentNamesParentClosePolicyValues(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_PARENT_CLOSE_POLICIES as $policy) {
            $this->assertStringContainsString(
                $policy,
                $contents,
                sprintf('Contract must name parent-close policy %s.', $policy),
            );
        }
    }

    public function testContractDocumentNamesExceptionClasses(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_EXCEPTION_CLASSES as $class) {
            $this->assertStringContainsString(
                $class,
                $contents,
                sprintf('Contract must name the %s exception class.', $class),
            );
        }
    }

    public function testContractDocumentNamesParentSideHistoryEvents(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_HISTORY_EVENTS_PARENT as $event) {
            $this->assertStringContainsString(
                $event,
                $contents,
                sprintf('Contract must name the %s history event.', $event),
            );
        }
    }

    public function testContractDocumentNamesFailureCategoriesAndPropagationKinds(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_FAILURE_CATEGORIES as $category) {
            $this->assertStringContainsString(
                $category,
                $contents,
                sprintf('Contract must name the %s failure_category value.', $category),
            );
        }
        foreach (self::REQUIRED_PROPAGATION_KINDS as $kind) {
            $this->assertStringContainsString(
                $kind,
                $contents,
                sprintf('Contract must name the %s propagation_kind value.', $kind),
            );
        }
    }

    public function testContractDocumentEnumeratesOpenWorkCleanupSections(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_OPEN_WORK_SECTIONS as $section) {
            $this->assertStringContainsString(
                $section,
                $contents,
                sprintf('Contract must name the %s cleanup section.', $section),
            );
        }
    }

    public function testContractDocumentNamesCooperativeHeartbeatFlag(): void
    {
        $contents = $this->documentContents();

        $this->assertStringContainsString(
            self::REQUIRED_COOPERATIVE_FLAG,
            $contents,
            'Contract must name the cancel_requested activity heartbeat flag.',
        );
        $this->assertMatchesRegularExpression(
            '/cooperative/i',
            $contents,
            'Contract must describe activity cancellation as cooperative.',
        );
    }

    public function testContractDocumentNamesEveryBoundConsumer(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_CONSUMER_CLASSES as $consumer) {
            $this->assertStringContainsString(
                $consumer,
                $contents,
                sprintf('Contract must name %s as a bound consumer.', $consumer),
            );
        }
    }

    public function testContractDocumentEnumeratesExplicitNonGoals(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_NON_GOALS as $nonGoal) {
            $this->assertStringContainsString(
                $nonGoal,
                $contents,
                sprintf('Contract must name the %s non-goal.', $nonGoal),
            );
        }
    }

    public function testContractDocumentStatesRunLevelNotCallLevel(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/run-level, not\s*\n?\s*\*?\*?call-level/i',
            $contents,
            'Contract must state cancel is run-level, not call-level.',
        );
        $this->assertMatchesRegularExpression(
            '/There is no v2 API for cancelling a single activity call/i',
            $contents,
            'Contract must explicitly state there is no per-activity cancel API.',
        );
    }

    public function testContractDocumentStatesParentClosePolicyIsBestEffortPerExhaustivePerLink(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/best-effort per child but exhaustive per link/i',
            $contents,
            'Contract must describe parent-close enforcement semantics.',
        );
    }

    public function testContractDocumentStatesParentCloseRecordsAppliedOrFailed(): void
    {
        $contents = $this->documentContents();

        $this->assertStringContainsString(
            'ParentClosePolicyApplied',
            $contents,
            'Contract must name ParentClosePolicyApplied.',
        );
        $this->assertStringContainsString(
            'ParentClosePolicyFailed',
            $contents,
            'Contract must name ParentClosePolicyFailed.',
        );
    }

    public function testContractDocumentBuildsOnPhaseOneAndChildOutcomeContracts(): void
    {
        $contents = $this->documentContents();

        $this->assertStringContainsString(
            'docs/architecture/execution-guarantees.md',
            $contents,
            'Contract must cite Phase 1 execution-guarantees as its foundation.',
        );
        $this->assertStringContainsString(
            'docs/architecture/child-outcome-source-of-truth.md',
            $contents,
            'Contract must cite the child-outcome source-of-truth contract.',
        );
    }

    public function testContractDocumentCitesPinningTest(): void
    {
        $contents = $this->documentContents();

        $this->assertStringContainsString(
            'tests/Unit/V2/CancellationScopeDocumentationTest.php',
            $contents,
            'Contract must cite its own pinning test path.',
        );
    }

    public function testEnumConstantsMatchDocumentedValues(): void
    {
        $contents = $this->documentContents();

        $this->assertSame('cancel', CommandType::Cancel->value);
        $this->assertSame('terminate', CommandType::Terminate->value);
        $this->assertSame('cancelled', CommandOutcome::Cancelled->value);
        $this->assertSame('terminated', CommandOutcome::Terminated->value);
        $this->assertSame('cancelled', FailureCategory::Cancelled->value);
        $this->assertSame('terminated', FailureCategory::Terminated->value);
        $this->assertSame('abandon', ParentClosePolicy::Abandon->value);
        $this->assertSame('request_cancel', ParentClosePolicy::RequestCancel->value);
        $this->assertSame('terminate', ParentClosePolicy::Terminate->value);

        foreach (
            [
                ParentClosePolicy::Abandon->value,
                ParentClosePolicy::RequestCancel->value,
                ParentClosePolicy::Terminate->value,
            ] as $value
        ) {
            $this->assertStringContainsString(
                sprintf("'%s'", $value),
                $contents,
                sprintf(
                    'Documented parent-close policy string %s must match the ParentClosePolicy runtime value verbatim.',
                    $value,
                ),
            );
        }
    }

    private function documentContents(): string
    {
        $path = dirname(__DIR__, 3) . '/' . self::DOCUMENT;
        $contents = file_get_contents($path);

        $this->assertIsString($contents, sprintf('Could not read %s.', self::DOCUMENT));

        return $contents;
    }
}
