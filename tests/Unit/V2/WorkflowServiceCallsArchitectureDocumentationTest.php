<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\TestCase;
use Workflow\V2\Enums\FailureCategory;
use Workflow\V2\Enums\ServiceCallBindingKind;
use Workflow\V2\Enums\ServiceCallFailureReason;
use Workflow\V2\Enums\ServiceCallOperationMode;
use Workflow\V2\Enums\ServiceCallStatus;

/**
 * Pins the v2 cross-namespace service call lifecycle and outcome
 * contract documented in
 * docs/architecture/workflow-service-calls-architecture.md. The doc is
 * the single reference used by product docs, CLI reasoning, Waterline
 * diagnostics, SDK documentation, and webhook delivery for the durable
 * service-call id, the explicit non-terminal and terminal states, the
 * sync vs async operation modes, the explicit linked target
 * references, the deadline / cancellation / retry / idempotency
 * surface, the reference-based payload storage rule, and the failure
 * taxonomy. Changes to any named guarantee must update this test and
 * the documented contract in the same change so drift is reviewed
 * deliberately.
 */
final class WorkflowServiceCallsArchitectureDocumentationTest extends TestCase
{
    private const DOCUMENT = 'docs/architecture/workflow-service-calls-architecture.md';

    private const MIGRATION = 'src/migrations/2026_04_24_000193_create_workflow_service_calls_table.php';

    private const REQUIRED_HEADINGS = [
        '# Workflow V2 Cross-Namespace Service Call Lifecycle and Outcome Contract',
        '## Scope',
        '## Terminology',
        '## The durable service-call id',
        '## Service-call lifecycle',
        '## Sync vs async operation modes',
        '## Linked target references',
        '## Deadline, cancellation, retry, and idempotency',
        '## Reference-based payload storage',
        '## Failure taxonomy',
        '## Observability surface',
        '## Consumers bound by this contract',
        '## Non-goals',
        '## Test strategy alignment',
        '## Changing this contract',
    ];

    private const REQUIRED_TERMS = [
        'Cross-namespace service call',
        'Caller namespace',
        'Target namespace',
        'Durable service-call id',
        'Linked target reference',
        'Operation mode',
        'Resolution',
    ];

    private const REQUIRED_STATUS_PHRASES = [
        "`ServiceCallStatus`",
        "`'pending'`",
        "`'accepted'`",
        "`'started'`",
        "`'completed'`",
        "`'failed'`",
        "`'cancelled'`",
    ];

    private const REQUIRED_OPERATION_MODE_PHRASES = [
        "`ServiceCallOperationMode::Sync` (`'sync'`)",
        "`ServiceCallOperationMode::Async` (`'async'`)",
        "`ServiceCallOperationMode::SyncWithDurableReference`\n  (`'sync_with_durable_reference'`)",
    ];

    private const REQUIRED_BINDING_KIND_PHRASES = [
        "`'workflow_run'`",
        "`'workflow_update'`",
        "`'activity_execution'`",
        "`'invocable_carrier_request'`",
    ];

    private const REQUIRED_FAILURE_REASON_PHRASES = [
        "`ServiceCallFailureReason::ResolutionFailure`\n  (`'resolution_failure'`)",
        "`ServiceCallFailureReason::PolicyRejection` (`'policy_rejection'`)",
        "`ServiceCallFailureReason::Timeout` (`'timeout'`)",
        "`ServiceCallFailureReason::Cancellation` (`'cancellation'`)",
        "`ServiceCallFailureReason::HandlerFailure` (`'handler_failure'`)",
    ];

    private const REQUIRED_LINKED_REFERENCE_COLUMNS = [
        'linked_workflow_run_id',
        'linked_workflow_update_id',
        'linked_workflow_instance_id',
        'resolved_binding_kind',
        'resolved_target_reference',
    ];

    private const REQUIRED_POLICY_COLUMNS = [
        'deadline_policy',
        'cancellation_policy',
        'retry_policy',
        'idempotency_policy',
        'idempotency_key',
    ];

    private const REQUIRED_PAYLOAD_COLUMNS = [
        'input_payload_reference',
        'output_payload_reference',
        'failure_payload_reference',
        'payload_codec',
        'failure_message',
    ];

    private const REQUIRED_TIMING_COLUMNS = [
        'accepted_at',
        'started_at',
        'completed_at',
        'failed_at',
        'cancelled_at',
    ];

    private const REQUIRED_OBSERVABILITY_COLUMNS = [
        'caller_namespace',
        'target_namespace',
        'caller_workflow_instance_id',
        'caller_workflow_run_id',
        'endpoint_name',
        'service_name',
        'operation_name',
        'workflow_service_endpoint_id',
        'workflow_service_id',
        'workflow_service_operation_id',
    ];

    private const REQUIRED_CONSUMERS = [
        'WorkflowServiceCall',
        'WorkflowServiceOperation',
        'WorkflowServiceEndpoint',
        'WorkflowServiceCallsArchitectureDocumentationTest',
        'WorkflowExecutor',
        'ChildCallService',
    ];

    private const REQUIRED_NON_GOALS = [
        'Inline-only payload transport',
        'Implicit lifecycles',
        'Per-transport retry semantics',
        'Cross-call ordering guarantees',
    ];

    private const REQUIRED_FAILURE_CATEGORY_MAPPINGS = [
        'FailureCategory::Application',
        'FailureCategory::Timeout',
        'FailureCategory::Cancelled',
        'FailureCategory::Activity',
        'FailureCategory::ChildWorkflow',
    ];

    private const REQUIRED_RELATED_DOCS = [
        'docs/architecture/workflow-child-calls-architecture.md',
        'docs/workflow-messages-architecture.md',
        'docs/architecture/execution-guarantees.md',
        'docs/architecture/control-plane-split.md',
    ];

    public function testContractDocumentExistsAndDeclaresFrozenSections(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_HEADINGS as $heading) {
            $this->assertStringContainsString(
                $heading,
                $contents,
                sprintf('Cross-namespace service call contract is missing heading %s.', $heading),
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
                    'Cross-namespace service call contract must define term %s in the Terminology section.',
                    $term,
                ),
            );
        }
    }

    public function testContractDocumentNamesEveryServiceCallStatusValue(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_STATUS_PHRASES as $phrase) {
            $this->assertStringContainsString(
                $phrase,
                $contents,
                sprintf('Contract must name service-call status phrase %s.', $phrase),
            );
        }
    }

    public function testContractDocumentNamesEveryOperationMode(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_OPERATION_MODE_PHRASES as $phrase) {
            $this->assertStringContainsString(
                $phrase,
                $contents,
                sprintf('Contract must name operation-mode phrase %s.', $phrase),
            );
        }
    }

    public function testContractDocumentNamesEveryBindingKind(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_BINDING_KIND_PHRASES as $phrase) {
            $this->assertStringContainsString(
                $phrase,
                $contents,
                sprintf('Contract must name binding-kind phrase %s.', $phrase),
            );
        }
    }

    public function testContractDocumentNamesEveryFailureReason(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_FAILURE_REASON_PHRASES as $phrase) {
            $this->assertStringContainsString(
                $phrase,
                $contents,
                sprintf('Contract must name failure-reason phrase %s.', $phrase),
            );
        }
    }

    public function testContractDocumentNamesLinkedReferenceColumns(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_LINKED_REFERENCE_COLUMNS as $column) {
            $this->assertStringContainsString(
                $column,
                $contents,
                sprintf('Contract must name the %s linked-reference column.', $column),
            );
        }
    }

    public function testContractDocumentNamesPolicyColumns(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_POLICY_COLUMNS as $column) {
            $this->assertStringContainsString(
                $column,
                $contents,
                sprintf('Contract must name the %s policy column.', $column),
            );
        }
    }

    public function testContractDocumentNamesPayloadColumns(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_PAYLOAD_COLUMNS as $column) {
            $this->assertStringContainsString(
                $column,
                $contents,
                sprintf('Contract must name the %s payload column.', $column),
            );
        }
    }

    public function testContractDocumentNamesTimingColumns(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_TIMING_COLUMNS as $column) {
            $this->assertStringContainsString(
                $column,
                $contents,
                sprintf('Contract must name the %s timing column.', $column),
            );
        }
    }

    public function testContractDocumentNamesObservabilityColumns(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_OBSERVABILITY_COLUMNS as $column) {
            $this->assertStringContainsString(
                $column,
                $contents,
                sprintf('Contract must name the %s observability column.', $column),
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

    public function testContractDocumentMapsFailureReasonsIntoFailureCategory(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_FAILURE_CATEGORY_MAPPINGS as $category) {
            $this->assertStringContainsString(
                $category,
                $contents,
                sprintf('Contract must map a failure reason to %s.', $category),
            );
        }
    }

    public function testContractDocumentCitesRelatedContracts(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_RELATED_DOCS as $doc) {
            $this->assertStringContainsString(
                $doc,
                $contents,
                sprintf('Contract must cite related document %s.', $doc),
            );
        }
    }

    public function testContractDocumentStatesClarityPrinciple(): void
    {
        $contents = $this->documentContents();

        $this->assertStringContainsString(
            'A cross-namespace service call is not "just an HTTP request" and not',
            $contents,
            'Contract must restate the clarity principle that frames the call as a durable operation.',
        );
        $this->assertMatchesRegularExpression(
            '/lifecycle, references, and outcome semantics/i',
            $contents,
            'Contract must restate the lifecycle / references / outcome semantics framing of the clarity principle.',
        );
    }

    public function testContractDocumentStatesDurableIdInvariants(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/stable across .*retries/i',
            $contents,
            'Contract must state the durable id is stable across retries.',
        );
        $this->assertMatchesRegularExpression(
            '/stable across .*replays/i',
            $contents,
            'Contract must state the durable id is stable across replays.',
        );
        $this->assertMatchesRegularExpression(
            '/continue-as-new of the caller does not change the\s*\n?\s*id/i',
            $contents,
            'Contract must state the durable id survives caller continue-as-new.',
        );
    }

    public function testContractDocumentStatesReferenceBasedPayloadRule(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/Inline-only payload transport is not part of this contract/i',
            $contents,
            'Contract must forbid inline-only payload transport.',
        );
        $this->assertStringContainsString(
            'ExternalPayloadStorageDriver',
            $contents,
            'Contract must reference ExternalPayloadStorageDriver as a permitted payload backing store.',
        );
    }

    public function testContractDocumentStatesObservabilityWithoutTransportLogs(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/No part of the observability surface MAY require raw transport\s*\n?\s*logs/i',
            $contents,
            'Contract must state the observability surface MUST NOT require raw transport logs.',
        );
    }

    public function testContractDocumentCitesPinningTest(): void
    {
        $contents = $this->documentContents();

        $this->assertStringContainsString(
            'tests/Unit/V2/WorkflowServiceCallsArchitectureDocumentationTest.php',
            $contents,
            'Contract must cite its own pinning test path.',
        );
    }

    public function testServiceCallStatusEnumMatchesDocumentedValues(): void
    {
        $contents = $this->documentContents();

        $this->assertSame('pending', ServiceCallStatus::Pending->value);
        $this->assertSame('accepted', ServiceCallStatus::Accepted->value);
        $this->assertSame('started', ServiceCallStatus::Started->value);
        $this->assertSame('completed', ServiceCallStatus::Completed->value);
        $this->assertSame('failed', ServiceCallStatus::Failed->value);
        $this->assertSame('cancelled', ServiceCallStatus::Cancelled->value);

        $this->assertFalse(ServiceCallStatus::Pending->isTerminal());
        $this->assertFalse(ServiceCallStatus::Accepted->isTerminal());
        $this->assertFalse(ServiceCallStatus::Started->isTerminal());
        $this->assertTrue(ServiceCallStatus::Completed->isTerminal());
        $this->assertTrue(ServiceCallStatus::Failed->isTerminal());
        $this->assertTrue(ServiceCallStatus::Cancelled->isTerminal());

        foreach (ServiceCallStatus::cases() as $case) {
            $this->assertStringContainsString(
                sprintf("`'%s'`", $case->value),
                $contents,
                sprintf(
                    'Documented service-call status string %s must match the ServiceCallStatus runtime value verbatim.',
                    $case->value,
                ),
            );
        }
    }

    public function testServiceCallOperationModeEnumMatchesDocumentedValues(): void
    {
        $contents = $this->documentContents();

        $this->assertSame('sync', ServiceCallOperationMode::Sync->value);
        $this->assertSame('async', ServiceCallOperationMode::Async->value);
        $this->assertSame(
            'sync_with_durable_reference',
            ServiceCallOperationMode::SyncWithDurableReference->value,
        );

        foreach (ServiceCallOperationMode::cases() as $case) {
            $this->assertStringContainsString(
                sprintf("`'%s'`", $case->value),
                $contents,
                sprintf(
                    'Documented operation-mode string %s must match the ServiceCallOperationMode runtime value verbatim.',
                    $case->value,
                ),
            );
        }
    }

    public function testServiceCallBindingKindEnumMatchesDocumentedValues(): void
    {
        $contents = $this->documentContents();

        $this->assertSame('workflow_run', ServiceCallBindingKind::WorkflowRun->value);
        $this->assertSame('workflow_update', ServiceCallBindingKind::WorkflowUpdate->value);
        $this->assertSame('activity_execution', ServiceCallBindingKind::ActivityExecution->value);
        $this->assertSame(
            'invocable_carrier_request',
            ServiceCallBindingKind::InvocableCarrierRequest->value,
        );

        foreach (ServiceCallBindingKind::cases() as $case) {
            $this->assertStringContainsString(
                sprintf("`'%s'`", $case->value),
                $contents,
                sprintf(
                    'Documented binding-kind string %s must match the ServiceCallBindingKind runtime value verbatim.',
                    $case->value,
                ),
            );
        }
    }

    public function testServiceCallFailureReasonEnumMatchesDocumentedValues(): void
    {
        $contents = $this->documentContents();

        $this->assertSame('resolution_failure', ServiceCallFailureReason::ResolutionFailure->value);
        $this->assertSame('policy_rejection', ServiceCallFailureReason::PolicyRejection->value);
        $this->assertSame('timeout', ServiceCallFailureReason::Timeout->value);
        $this->assertSame('cancellation', ServiceCallFailureReason::Cancellation->value);
        $this->assertSame('handler_failure', ServiceCallFailureReason::HandlerFailure->value);

        foreach (ServiceCallFailureReason::cases() as $case) {
            $this->assertStringContainsString(
                sprintf("`'%s'`", $case->value),
                $contents,
                sprintf(
                    'Documented failure-reason string %s must match the ServiceCallFailureReason runtime value verbatim.',
                    $case->value,
                ),
            );
        }
    }

    public function testFailureCategoryMappingValuesAreLiveEnumCases(): void
    {
        $this->assertSame('application', FailureCategory::Application->value);
        $this->assertSame('timeout', FailureCategory::Timeout->value);
        $this->assertSame('cancelled', FailureCategory::Cancelled->value);
        $this->assertSame('activity', FailureCategory::Activity->value);
        $this->assertSame('child_workflow', FailureCategory::ChildWorkflow->value);
    }

    public function testMigrationDeclaresEveryColumnNamedByTheContract(): void
    {
        $migration = $this->migrationContents();

        foreach (
            array_merge(
                self::REQUIRED_LINKED_REFERENCE_COLUMNS,
                self::REQUIRED_POLICY_COLUMNS,
                self::REQUIRED_PAYLOAD_COLUMNS,
                self::REQUIRED_TIMING_COLUMNS,
                self::REQUIRED_OBSERVABILITY_COLUMNS,
            ) as $column
        ) {
            $this->assertStringContainsString(
                sprintf("'%s'", $column),
                $migration,
                sprintf(
                    'Migration %s must declare column %s named by the contract.',
                    self::MIGRATION,
                    $column,
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

    private function migrationContents(): string
    {
        $path = dirname(__DIR__, 3) . '/' . self::MIGRATION;
        $contents = file_get_contents($path);

        $this->assertIsString($contents, sprintf('Could not read %s.', self::MIGRATION));

        return $contents;
    }
}
