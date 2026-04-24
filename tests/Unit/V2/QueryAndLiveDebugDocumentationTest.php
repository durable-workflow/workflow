<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\TestCase;
use Workflow\V2\Support\WorkflowExecutionGate;

/**
 * Pins the v2 query and live-debug non-durability contract documented
 * in docs/architecture/query-and-live-debug.md. The doc is the single
 * reference used by product docs, CLI reasoning, Waterline
 * diagnostics, server HTTP documentation, SDK documentation, and test
 * coverage for the non-durability guarantee, command-dispatch
 * suppression, the 200/409/422 query response boundary, the
 * blocked-reason gate, and the live-debug read surfaces. Changes to
 * any named guarantee must update this test and the documented
 * contract in the same change so drift is reviewed deliberately.
 */
final class QueryAndLiveDebugDocumentationTest extends TestCase
{
    private const DOCUMENT = 'docs/architecture/query-and-live-debug.md';

    private const REQUIRED_HEADINGS = [
        '# Workflow V2 Query and Live-Debug Non-Durability Contract',
        '## Scope',
        '## Terminology',
        '## Query invocation contract',
        '## Query response contract',
        '## Non-durability guarantees',
        '## Command-dispatch suppression',
        '## Live-debug surfaces',
        '## Query handler authoring rules',
        '## Query error taxonomy',
        '## Consumers bound by this contract',
        '## Test strategy alignment',
        '## What this contract does not yet guarantee',
        '## Changing this contract',
    ];

    private const REQUIRED_TERMS = [
        'Query',
        'Live-debug surface',
        'Command-dispatch suppression',
        'Non-durable',
        'Currently-resolved state',
    ];

    private const REQUIRED_RESPONSE_CODES = ['**200**', '**409**', '**422**'];

    private const REQUIRED_RESPONSE_FIELDS = [
        'query_name',
        'workflow_id',
        'run_id',
        'target_scope',
        'result',
        'validation_errors',
        'blocked_reason',
    ];

    private const REQUIRED_CLASSES = [
        'WorkflowStub',
        'QueryStateReplayer',
        'QueryResponse',
        'WorkflowExecutionGate',
        'WorkflowDefinition',
        'ChildWorkflowHandle',
        'RunLineageView',
        'RunWaitView',
        'HistoryTimeline',
        'OperatorMetrics',
        'OperatorQueueVisibility',
        'Webhooks',
    ];

    private const REQUIRED_EXCEPTIONS = [
        'InvalidQueryArgumentsException',
        'WorkflowExecutionUnavailableException',
        'LogicException',
    ];

    private const REQUIRED_NON_DURABLE_TABLES = [
        'workflow_history_events',
        'workflow_commands',
        'workflow_updates',
        'workflow_tasks',
        'workflow_activity_executions',
        'workflow_activity_attempts',
        'workflow_timers',
        'workflow_links',
        'workflow_child_calls',
        'workflow_messages',
        'workflow_memos',
        'workflow_search_attributes',
    ];

    private const REQUIRED_SDK_ATTRIBUTE = 'QueryMethod';

    private const REQUIRED_BLOCKED_REASON_CONSTANT = 'BLOCKED_WORKFLOW_DEFINITION_UNAVAILABLE';

    private const REQUIRED_BLOCKED_REASON_VALUE = 'workflow_definition_unavailable';

    private const REQUIRED_DISPATCH_FLAG_CALL = 'setCommandDispatchEnabled(false)';

    private const REQUIRED_METHODS_ON_SUPPRESSION = [
        'ChildWorkflowHandle::signalWithArguments',
        'Workflow::children',
    ];

    public function testContractDocumentExistsAndDeclaresFrozenSections(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_HEADINGS as $heading) {
            $this->assertStringContainsString(
                $heading,
                $contents,
                sprintf('Query-and-live-debug contract is missing heading %s.', $heading),
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

    public function testContractDocumentNamesEveryResponseCode(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_RESPONSE_CODES as $code) {
            $this->assertStringContainsString(
                $code,
                $contents,
                sprintf('Contract must name the %s query response code.', $code),
            );
        }
    }

    public function testContractDocumentNamesResponsePayloadFields(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_RESPONSE_FIELDS as $field) {
            $this->assertStringContainsString(
                $field,
                $contents,
                sprintf('Contract must name the %s response payload field.', $field),
            );
        }
    }

    public function testContractDocumentReferencesCanonicalClasses(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_CLASSES as $class) {
            $this->assertStringContainsString(
                $class,
                $contents,
                sprintf('Contract must reference %s as a canonical consumer or surface.', $class),
            );
        }
    }

    public function testContractDocumentReferencesExceptionTypes(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_EXCEPTIONS as $exception) {
            $this->assertStringContainsString(
                $exception,
                $contents,
                sprintf('Contract must reference exception %s in the error taxonomy.', $exception),
            );
        }
    }

    public function testContractDocumentNamesEveryNonDurableTable(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_NON_DURABLE_TABLES as $table) {
            $this->assertStringContainsString(
                $table,
                $contents,
                sprintf(
                    'Contract must name the %s table in the non-durability guarantees so drift is reviewable.',
                    $table,
                ),
            );
        }
    }

    public function testContractDocumentNamesQueryMethodAttribute(): void
    {
        $contents = $this->documentContents();

        $this->assertStringContainsString(
            self::REQUIRED_SDK_ATTRIBUTE,
            $contents,
            'Contract must name the QueryMethod authoring attribute.',
        );
        $this->assertStringContainsString(
            '#[QueryMethod]',
            $contents,
            'Contract must show the #[QueryMethod] attribute syntax so authors know how to declare queries.',
        );
    }

    public function testContractDocumentNamesBlockedReasonGate(): void
    {
        $contents = $this->documentContents();

        $this->assertStringContainsString(
            self::REQUIRED_BLOCKED_REASON_CONSTANT,
            $contents,
            sprintf(
                'Contract must name the %s WorkflowExecutionGate constant.',
                self::REQUIRED_BLOCKED_REASON_CONSTANT
            ),
        );
        $this->assertStringContainsString(
            sprintf("'%s'", self::REQUIRED_BLOCKED_REASON_VALUE),
            $contents,
            sprintf(
                'Contract must quote the %s string value so operator surfaces can match on it.',
                self::REQUIRED_BLOCKED_REASON_VALUE,
            ),
        );
    }

    public function testContractDocumentNamesCommandDispatchSuppression(): void
    {
        $contents = $this->documentContents();

        $this->assertStringContainsString(
            self::REQUIRED_DISPATCH_FLAG_CALL,
            $contents,
            'Contract must name the setCommandDispatchEnabled(false) call that silences dispatch in queries.',
        );
        $this->assertStringContainsString(
            'commandDispatchEnabled',
            $contents,
            'Contract must name the commandDispatchEnabled flag on Workflow.',
        );
        foreach (self::REQUIRED_METHODS_ON_SUPPRESSION as $method) {
            $this->assertStringContainsString(
                $method,
                $contents,
                sprintf('Contract must name %s as a suppressed dispatch site.', $method),
            );
        }
    }

    public function testContractDocumentStatesQueriesProduceNoHistory(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/zero durable state/i',
            $contents,
            'Contract must state queries leave zero durable state behind.',
        );
        $this->assertMatchesRegularExpression(
            '/no row is written/i',
            $contents,
            'Contract must explicitly state no durable row is written during a query.',
        );
        $this->assertMatchesRegularExpression(
            '/no Laravel job is enqueued/i',
            $contents,
            'Contract must explicitly state no Laravel job is enqueued during a query.',
        );
        $this->assertMatchesRegularExpression(
            '/no wake notification is signalled/i',
            $contents,
            'Contract must explicitly state wake notification is not signalled during a query.',
        );
    }

    public function testContractDocumentStatesUpdateReplayUsesSameSwitch(): void
    {
        $contents = $this->documentContents();

        $this->assertStringContainsString(
            'WorkflowExecutor',
            $contents,
            'Contract must call out that WorkflowExecutor uses the same suppression switch during update replay.',
        );
        $this->assertStringContainsString(
            'UpdateApplied',
            $contents,
            'Contract must name UpdateApplied as the replay path that uses the suppression switch.',
        );
        $this->assertMatchesRegularExpression(
            '/finally/i',
            $contents,
            'Contract must state the executor restores the flag to true in a finally block.',
        );
    }

    public function testContractDocumentPinsErrorTaxonomyTable(): void
    {
        $contents = $this->documentContents();

        foreach (
            [
                'Run not started yet',
                'Method not declared as a query',
                'Arguments fail validation',
                'Workflow definition not resolvable',
                'Query body raised',
            ] as $row
        ) {
            $this->assertStringContainsString(
                $row,
                $contents,
                sprintf('Contract must name the %s row in the error taxonomy table.', $row),
            );
        }
    }

    public function testContractDocumentStatesLiveDebugSurfacesAreReadOnly(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/read-only/i',
            $contents,
            'Contract must state live-debug surfaces are read-only.',
        );
        $this->assertStringContainsString(
            'dw run describe',
            $contents,
            'Contract must cite the CLI `dw run describe` live-debug surface.',
        );
    }

    public function testContractDocumentForbidsSignalsActivitiesAndChildren(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/MUST NOT call activities/i',
            $contents,
            'Contract must forbid queries from calling activities.',
        );
        $this->assertMatchesRegularExpression(
            '/schedule timers/i',
            $contents,
            'Contract must forbid queries from scheduling timers.',
        );
        $this->assertMatchesRegularExpression(
            '/send signals|signals that reach/i',
            $contents,
            'Contract must forbid queries from sending signals to other workflows.',
        );
        $this->assertMatchesRegularExpression(
            '/open child workflows/i',
            $contents,
            'Contract must forbid queries from opening child workflows.',
        );
    }

    public function testContractDocumentBuildsOnPhaseOne(): void
    {
        $contents = $this->documentContents();

        $this->assertStringContainsString(
            'docs/architecture/execution-guarantees.md',
            $contents,
            'Contract must cite Phase 1 execution-guarantees as its foundation.',
        );
    }

    public function testContractDocumentCitesPinningTest(): void
    {
        $contents = $this->documentContents();

        $this->assertStringContainsString(
            'tests/Unit/V2/QueryAndLiveDebugDocumentationTest.php',
            $contents,
            'Contract must cite its own pinning test path.',
        );
    }

    public function testWorkflowExecutionGateConstantMatchesDocumentedValue(): void
    {
        $contents = $this->documentContents();

        $this->assertSame(
            'workflow_definition_unavailable',
            WorkflowExecutionGate::BLOCKED_WORKFLOW_DEFINITION_UNAVAILABLE,
        );
        $this->assertStringContainsString(
            WorkflowExecutionGate::BLOCKED_WORKFLOW_DEFINITION_UNAVAILABLE,
            $contents,
            'Documented blocked-reason string must match the WorkflowExecutionGate runtime constant verbatim.',
        );
    }

    private function documentContents(): string
    {
        $path = dirname(__DIR__, 3) . '/' . self::DOCUMENT;
        $contents = file_get_contents($path);

        $this->assertIsString($contents, sprintf('Could not read %s.', self::DOCUMENT));

        return $contents;
    }
}
