<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\TestCase;

/**
 * Pins the v2 child-outcome source-of-truth contract documented in
 * docs/architecture/child-outcome-source-of-truth.md. The doc is the
 * single reference used by product docs, CLI reasoning, Waterline
 * diagnostics, and test coverage for the five-step resolution
 * precedence, the three history-authority modes, payload precedence
 * for output and exception, the parent-history blocking invariant,
 * and continue-as-new traversal. Changes to any named guarantee must
 * update this test and the documented contract in the same change so
 * drift is reviewed deliberately.
 */
final class ChildOutcomeSourceOfTruthDocumentationTest extends TestCase
{
    private const DOCUMENT = 'docs/architecture/child-outcome-source-of-truth.md';

    private const REQUIRED_HEADINGS = [
        '# Workflow V2 Child-Outcome Source-of-Truth Contract',
        '## Scope',
        '## Terminology',
        '## The resolution precedence',
        '## Payload authority',
        '### Output payload',
        '### Exception payload',
        '### Parent-perspective message for cancelled or terminated children',
        '## Child-run identity resolution',
        '## Resolved status decoding',
        '## Consumers bound by this contract',
        '## Parent-history blocking invariant',
        '## Continue-as-new across child boundaries',
        '## Parent reference and child-call id',
        '## Waterline and CLI surfacing',
        '## Test strategy alignment',
        '## What this contract does not yet guarantee',
        '## Changing this contract',
    ];

    private const REQUIRED_TERMS = [
        'Parent run',
        'Child call sequence',
        'Parent history events',
        'Resolution event',
        'Child run',
        'Child link',
        'Authoritative',
    ];

    private const REQUIRED_PARENT_RESOLUTION_EVENTS = [
        'ChildRunCompleted',
        'ChildRunFailed',
        'ChildRunCancelled',
        'ChildRunTerminated',
    ];

    private const REQUIRED_PARENT_OPEN_EVENTS = ['ChildWorkflowScheduled', 'ChildRunStarted'];

    private const REQUIRED_CHILD_TERMINAL_EVENTS = [
        'WorkflowCompleted',
        'WorkflowFailed',
        'WorkflowCancelled',
        'WorkflowTerminated',
    ];

    private const REQUIRED_RUN_STATUSES = [
        'Completed',
        'Failed',
        'Cancelled',
        'Terminated',
        'Pending',
        'Running',
        'Waiting',
    ];

    private const REQUIRED_HISTORY_AUTHORITY_CONSTANTS = [
        'HISTORY_AUTHORITY_TYPED',
        'HISTORY_AUTHORITY_MUTABLE_OPEN_FALLBACK',
        'HISTORY_AUTHORITY_UNSUPPORTED_TERMINAL',
    ];

    private const REQUIRED_HISTORY_AUTHORITY_VALUES = [
        "'typed_history'",
        "'mutable_open_fallback'",
        "'unsupported_terminal_without_history'",
    ];

    private const REQUIRED_UNSUPPORTED_REASON = 'terminal_child_link_without_typed_parent_history';

    private const REQUIRED_CHILD_RUN_HISTORY_METHODS = [
        'parentHistoryBlocksResolutionWithoutEvent',
        'childRunForSequence',
        'resolvedStatus',
        'outputForResolution',
        'outputForChildRun',
        'exceptionForResolution',
        'exceptionForChildRun',
        'waitSnapshotForSequence',
        'scheduledEventForSequence',
        'startedEventForSequence',
        'resolutionEventForSequence',
        'latestLinkForSequence',
        'followContinuedRun',
        'continuedFromRunId',
        'parentReferenceForRun',
        'childCallIdForSequence',
        'childCallIdForRun',
    ];

    private const REQUIRED_CONSUMERS = [
        'QueryStateReplayer',
        'WorkflowExecutor',
        'ParallelChildGroup',
        'RunLineageView',
        'RunWaitView',
        'DefaultWorkflowTaskBridge',
        'RunSummaryProjector',
        'Webhooks',
        'WorkflowStub',
    ];

    private const REQUIRED_SUPPORT_CLASSES = [
        'ChildRunHistory',
        'FailureFactory',
        'CurrentRunResolver',
        'ConfiguredV2Models',
    ];

    public function testContractDocumentExistsAndDeclaresFrozenSections(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_HEADINGS as $heading) {
            $this->assertStringContainsString(
                $heading,
                $contents,
                sprintf('Child-outcome source-of-truth contract is missing heading %s.', $heading),
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
                sprintf(
                    'Child-outcome source-of-truth contract must define term %s in the Terminology section.',
                    $term,
                ),
            );
        }
    }

    public function testContractDocumentNamesEveryParentResolutionEvent(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_PARENT_RESOLUTION_EVENTS as $event) {
            $this->assertStringContainsString(
                $event,
                $contents,
                sprintf('Contract must name the %s parent resolution event.', $event),
            );
        }
    }

    public function testContractDocumentNamesParentOpenSlotEvents(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_PARENT_OPEN_EVENTS as $event) {
            $this->assertStringContainsString(
                $event,
                $contents,
                sprintf('Contract must name the %s parent open-slot event.', $event),
            );
        }
    }

    public function testContractDocumentNamesChildTerminalHistoryEvents(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_CHILD_TERMINAL_EVENTS as $event) {
            $this->assertStringContainsString(
                $event,
                $contents,
                sprintf('Contract must name the %s child-run terminal history event.', $event),
            );
        }
    }

    public function testContractDocumentNamesRunStatusValues(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_RUN_STATUSES as $status) {
            $this->assertStringContainsString(
                $status,
                $contents,
                sprintf('Contract must name the RunStatus %s value.', $status),
            );
        }
    }

    public function testContractDocumentNamesHistoryAuthorityConstants(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_HISTORY_AUTHORITY_CONSTANTS as $constant) {
            $this->assertStringContainsString(
                $constant,
                $contents,
                sprintf('Contract must name the %s ChildRunHistory constant.', $constant),
            );
        }
    }

    public function testContractDocumentNamesHistoryAuthorityStringValues(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_HISTORY_AUTHORITY_VALUES as $value) {
            $this->assertStringContainsString(
                $value,
                $contents,
                sprintf('Contract must quote the %s string value so operator surfaces can match on it.', $value),
            );
        }
    }

    public function testContractDocumentNamesUnsupportedTerminalReasonCode(): void
    {
        $contents = $this->documentContents();

        $this->assertStringContainsString(
            self::REQUIRED_UNSUPPORTED_REASON,
            $contents,
            sprintf(
                'Contract must name the %s reason code so operator surfaces can match on it.',
                self::REQUIRED_UNSUPPORTED_REASON,
            ),
        );
    }

    public function testContractDocumentNamesChildRunHistoryMethodSurface(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_CHILD_RUN_HISTORY_METHODS as $method) {
            $this->assertStringContainsString(
                $method,
                $contents,
                sprintf('Contract must name the ChildRunHistory::%s method.', $method),
            );
        }
    }

    public function testContractDocumentNamesEveryBoundConsumer(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_CONSUMERS as $consumer) {
            $this->assertStringContainsString(
                $consumer,
                $contents,
                sprintf('Contract must name %s as a bound consumer of this precedence.', $consumer),
            );
        }
    }

    public function testContractDocumentReferencesCanonicalSupportClasses(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_SUPPORT_CLASSES as $class) {
            $this->assertStringContainsString(
                $class,
                $contents,
                sprintf('Contract must reference %s as a canonical support class.', $class),
            );
        }
    }

    public function testContractDocumentEnumeratesFiveStepResolutionPrecedence(): void
    {
        $contents = $this->documentContents();

        foreach (
            [
                '1. **Parent resolution event**',
                '2. **Parent open-slot block**',
                '3. **Typed child terminal history fallback**',
                '4. **Mutable open fallback**',
                '5. **Unsupported terminal fallback**',
            ] as $step
        ) {
            $this->assertStringContainsString(
                $step,
                $contents,
                sprintf('Contract must enumerate the %s step of the resolution precedence.', $step),
            );
        }
    }

    public function testContractDocumentStatesParentHistoryBlockingInvariant(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/no consumer may resolve that\s*\n?>?\s*slot until the parent commits a matching resolution event/i',
            $contents,
            'Contract must state the parent-history blocking invariant verbatim.',
        );
        $this->assertStringContainsString(
            'parentHistoryBlocksResolutionWithoutEvent',
            $contents,
            'Contract must cite parentHistoryBlocksResolutionWithoutEvent as the implementation.',
        );
    }

    public function testContractDocumentPinsOutputPayloadPrecedence(): void
    {
        $contents = $this->documentContents();

        $this->assertStringContainsString(
            '`output` on the parent resolution event payload',
            $contents,
            'Output precedence step 1 must name the resolution event payload.',
        );
        $this->assertStringContainsString(
            'terminalEventForRun',
            $contents,
            'Output precedence step 2 must name terminalEventForRun as the child-side fallback.',
        );
        $this->assertStringContainsString(
            '`$childRun->output`',
            $contents,
            'Output precedence step 3 must name the mutable $childRun->output column.',
        );
        $this->assertStringContainsString(
            'payload_codec',
            $contents,
            'Output precedence must name the payload_codec envelope rule.',
        );
    }

    public function testContractDocumentPinsExceptionPayloadPrecedence(): void
    {
        $contents = $this->documentContents();

        $this->assertStringContainsString(
            'WorkflowFailure',
            $contents,
            'Exception precedence must name the WorkflowFailure row as the third fallback.',
        );
        $this->assertStringContainsString(
            'FailureFactory::restoreForReplay',
            $contents,
            'Exception precedence must name FailureFactory::restoreForReplay as the canonical constructor.',
        );
        $this->assertStringContainsString(
            'RuntimeException::class',
            $contents,
            'Exception precedence must pin the synthesised fallback class as RuntimeException.',
        );
    }

    public function testContractDocumentPinsParentPerspectiveMessage(): void
    {
        $contents = $this->documentContents();

        $this->assertStringContainsString(
            'Child workflow <run_id> closed as <status>',
            $contents,
            'Contract must pin the parent-perspective message format for cancelled/terminated children.',
        );
        $this->assertStringContainsString(
            '`cancel(reason)`',
            $contents,
            'Contract must call out the cancel(reason) case as producing a reason suffix.',
        );
    }

    public function testContractDocumentPinsResolvedStatusDecoderSteps(): void
    {
        $contents = $this->documentContents();

        foreach (
            [
                'child_status',
                'RunStatus::tryFrom',
                'ChildRunCompleted => Completed',
                'ChildRunFailed => Failed',
                'ChildRunCancelled => Cancelled',
                'ChildRunTerminated =>',
                'Terminated',
                'WorkflowCompleted => Completed',
                'WorkflowFailed => Failed',
                'WorkflowCancelled => Cancelled',
                'WorkflowTerminated =>',
            ] as $fragment
        ) {
            $this->assertStringContainsString(
                $fragment,
                $contents,
                sprintf('Contract must pin the resolvedStatus decoder fragment %s.', $fragment),
            );
        }
    }

    public function testContractDocumentPinsContinueAsNewTraversalRule(): void
    {
        $contents = $this->documentContents();

        $this->assertStringContainsString(
            "closed_reason = 'continued'",
            $contents,
            'Contract must name the closed_reason = continued marker.',
        );
        $this->assertStringContainsString(
            'followContinuedRun',
            $contents,
            'Contract must cite followContinuedRun as the traversal implementation.',
        );
        $this->assertStringContainsString(
            'cycle',
            $contents,
            'Contract must describe the cycle guard on continued-chain traversal.',
        );
        $this->assertStringContainsString(
            'CurrentRunResolver::forRun',
            $contents,
            'Contract must cite CurrentRunResolver::forRun as the descending resolver.',
        );
    }

    public function testContractDocumentNamesEagerLoadedRelations(): void
    {
        $contents = $this->documentContents();

        foreach (['summary', 'instance', 'failures', 'historyEvents'] as $relation) {
            $this->assertStringContainsString(
                $relation,
                $contents,
                sprintf('Contract must name the %s eager-load relation used by childRunForSequence.', $relation),
            );
        }
    }

    public function testContractDocumentForbidsDirectTableReads(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/MUST route through\s*\n?`?ChildRunHistory`?/i',
            $contents,
            'Contract must state new consumers route through ChildRunHistory, not raw tables.',
        );
        foreach (['workflow_runs', 'workflow_links'] as $table) {
            $this->assertStringContainsString(
                $table,
                $contents,
                sprintf('Contract must name the %s table in the direct-read prohibition.', $table),
            );
        }
    }

    public function testContractDocumentBuildsOnPhaseOneAndPhaseFour(): void
    {
        $contents = $this->documentContents();

        $this->assertStringContainsString(
            'docs/architecture/execution-guarantees.md',
            $contents,
            'Contract must cite Phase 1 execution-guarantees as its foundation.',
        );
        $this->assertStringContainsString(
            'docs/architecture/control-plane-split.md',
            $contents,
            'Contract must cite Phase 4 control-plane-split as its foundation.',
        );
    }

    public function testContractDocumentCitesPinningTest(): void
    {
        $contents = $this->documentContents();

        $this->assertStringContainsString(
            'tests/Unit/V2/ChildOutcomeSourceOfTruthDocumentationTest.php',
            $contents,
            'Contract must cite its own pinning test path.',
        );
    }

    public function testChildRunHistoryConstantsMatchDocumentedValues(): void
    {
        $contents = $this->documentContents();

        $this->assertSame('typed_history', \Workflow\V2\Support\ChildRunHistory::HISTORY_AUTHORITY_TYPED);
        $this->assertSame(
            'mutable_open_fallback',
            \Workflow\V2\Support\ChildRunHistory::HISTORY_AUTHORITY_MUTABLE_OPEN_FALLBACK,
        );
        $this->assertSame(
            'unsupported_terminal_without_history',
            \Workflow\V2\Support\ChildRunHistory::HISTORY_AUTHORITY_UNSUPPORTED_TERMINAL,
        );
        $this->assertSame(
            'terminal_child_link_without_typed_parent_history',
            \Workflow\V2\Support\ChildRunHistory::UNSUPPORTED_TERMINAL_REASON,
        );

        foreach (
            [
                \Workflow\V2\Support\ChildRunHistory::HISTORY_AUTHORITY_TYPED,
                \Workflow\V2\Support\ChildRunHistory::HISTORY_AUTHORITY_MUTABLE_OPEN_FALLBACK,
                \Workflow\V2\Support\ChildRunHistory::HISTORY_AUTHORITY_UNSUPPORTED_TERMINAL,
                \Workflow\V2\Support\ChildRunHistory::UNSUPPORTED_TERMINAL_REASON,
            ] as $value
        ) {
            $this->assertStringContainsString(
                $value,
                $contents,
                sprintf(
                    'Documented history-authority string %s must match the ChildRunHistory runtime constant verbatim.',
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
