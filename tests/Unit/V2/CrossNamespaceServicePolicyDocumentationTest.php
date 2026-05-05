<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\TestCase;
use Workflow\V2\Models\WorkflowService;
use Workflow\V2\Models\WorkflowServiceCall;
use Workflow\V2\Models\WorkflowServiceEndpoint;
use Workflow\V2\Models\WorkflowServiceOperation;

/**
 * Pins the v2 cross-namespace service authorization and policy
 * contract documented in
 * docs/architecture/cross-namespace-service-policy.md. The doc is the
 * single reference used by product docs, CLI reasoning, Waterline
 * diagnostics, server deployment guidance, and test coverage for the
 * contract addressing rules, the boundary policy enforcement order,
 * the frozen outcome taxonomy, the audit columns required for every
 * accepted and rejected call, and the operator-visibility rules.
 * Changes to any named guarantee must update this test and the
 * documented contract in the same change so drift is reviewed
 * deliberately.
 */
final class CrossNamespaceServicePolicyDocumentationTest extends TestCase
{
    private const DOCUMENT = 'docs/architecture/cross-namespace-service-policy.md';

    private const REQUIRED_HEADINGS = [
        '# Workflow V2 Cross-Namespace Service Authorization and Policy Contract',
        '## Scope',
        '## Terminology',
        '## Guaranteeing authority',
        '## Contract addressing',
        '### Resolution',
        '### Caller namespace versus target namespace',
        '### Idempotency key',
        '## Boundary policy enforcement order',
        '### Why this order is fixed',
        '### Distinguishability invariant',
        '## Service-level limits',
        '### Rate limit',
        '### Concurrency limit',
        '### Circuit break',
        '### Service-level limits apply across handler bindings',
        '## Outcome taxonomy',
        '### Mapping handler outcomes to FailureCategory',
        '### Why a separate `handler_failed`',
        '## Audit facts',
        '## Payload privacy and trust',
        '## Operator visibility',
        '## Interaction with adjacent contracts',
        '## Config surface and defaults',
        '## What this contract does not yet guarantee',
        '## Test strategy alignment',
        '## Changing this contract',
    ];

    private const REQUIRED_TERMS = [
        'Endpoint',
        'Service',
        'Operation',
        'Contract address',
        'Caller identity',
        'Boundary policy',
        'Service-level limits',
        'Handler binding',
        'Outcome',
        'Rejection',
    ];

    private const REQUIRED_REFERENCED_CLASSES = [
        'WorkflowServiceEndpoint',
        'WorkflowService',
        'WorkflowServiceOperation',
        'WorkflowServiceCall',
        'PayloadEnvelopeResolver',
        'CodecRegistry',
        'ConfiguredV2Models',
        'OperatorQueueVisibility',
        'RunDetailView',
        'FailureCategory',
        'ExternalPayloadStorageDriver',
    ];

    private const REQUIRED_DURABLE_TABLES = [
        'workflow_service_endpoints',
        'workflow_services',
        'workflow_service_operations',
        'workflow_service_calls',
    ];

    private const REQUIRED_DURABLE_COLUMNS = [
        'workflow_service_calls.status',
        'workflow_service_calls.resolved_binding_kind',
        'workflow_service_calls.namespace',
        'caller_namespace',
        'caller_workflow_instance_id',
        'caller_workflow_run_id',
        'target_namespace',
        'endpoint_name',
        'service_name',
        'operation_name',
        'workflow_service_endpoint_id',
        'workflow_service_id',
        'workflow_service_operation_id',
        'workflow_service_endpoints.boundary_policy',
        'workflow_services.boundary_policy',
        'workflow_service_operations.boundary_policy',
        'workflow_service_calls.boundary_policy',
        'linked_workflow_instance_id',
        'linked_workflow_run_id',
        'linked_workflow_update_id',
        'idempotency_key',
        'idempotency_policy',
        'deadline_policy',
        'cancellation_policy',
        'retry_policy',
        'payload_codec',
        'input_payload_reference',
        'output_payload_reference',
        'failure_payload_reference',
        'failure_message',
        'accepted_at',
        'completed_at',
        'failed_at',
        'cancelled_at',
        'handler_binding',
        'handler_binding_kind',
        'handler_target_reference',
    ];

    private const REQUIRED_UNIQUE_KEYS = [
        'wf_service_endpoints_namespace_name_unique',
        'wf_services_namespace_endpoint_name_unique',
        'wf_service_ops_namespace_service_name_unique',
    ];

    private const REQUIRED_OUTCOME_VALUES = [
        '`pending`',
        '`accepted`',
        '`running`',
        '`completed`',
        '`cancelled`',
        '`degraded`',
        '`handler_failed`',
        '`rejected_not_found`',
        '`rejected_forbidden`',
        '`rejected_throttled`',
        '`rejected_concurrency_limited`',
        '`rejected_circuit_open`',
    ];

    private const REQUIRED_FAILURE_CATEGORY_VALUES = [
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

    private const REQUIRED_CONFIG_KEYS = [
        'workflows.v2.namespace',
        'workflows.v2.service_endpoint_model',
        'workflows.v2.service_model',
        'workflows.v2.service_operation_model',
        'workflows.v2.service_call_model',
    ];

    private const REQUIRED_CROSS_CONTRACT_CITATIONS = [
        'docs/architecture/routing-precedence.md',
        'docs/architecture/workflow-child-calls-architecture.md',
        'docs/architecture/control-plane-split.md',
        'docs/architecture/cancellation-scope.md',
        'docs/architecture/child-outcome-source-of-truth.md',
        'docs/architecture/execution-guarantees.md',
        'docs/architecture/task-matching.md',
        'docs/architecture/worker-compatibility.md',
    ];

    private const REQUIRED_ENFORCEMENT_STEPS = [
        '1. **Caller stamp.**',
        '2. **Address resolution.**',
        '3. **Authorization.**',
        '4. **Rate limit.**',
        '5. **Concurrency limit.**',
        '6. **Circuit break.**',
        '7. **Handler dispatch.**',
    ];

    private const REQUIRED_AUTHORIZATION_AXES = [
        'caller-versus-endpoint',
        'service',
        'operation',
    ];

    public function testContractDocumentExistsAndDeclaresFrozenSections(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_HEADINGS as $heading) {
            $this->assertStringContainsString(
                $heading,
                $contents,
                sprintf('Cross-namespace service policy contract is missing heading %s.', $heading),
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
                    'Cross-namespace service policy contract must define term %s in the Terminology section.',
                    $term,
                ),
            );
        }
    }

    public function testContractDocumentReferencesCanonicalSupportClasses(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_REFERENCED_CLASSES as $class) {
            $this->assertStringContainsString(
                $class,
                $contents,
                sprintf(
                    'Cross-namespace service policy contract must reference %s as a canonical implementation surface.',
                    $class,
                ),
            );
        }
    }

    public function testContractDocumentNamesEveryDurableTable(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_DURABLE_TABLES as $table) {
            $this->assertStringContainsString(
                $table,
                $contents,
                sprintf(
                    'Cross-namespace service policy contract must name the %s durable table so the boundary topology is explicit.',
                    $table,
                ),
            );
        }
    }

    public function testContractDocumentNamesEveryDurableColumn(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_DURABLE_COLUMNS as $column) {
            $this->assertStringContainsString(
                $column,
                $contents,
                sprintf(
                    'Cross-namespace service policy contract must name the durable column %s so audit facts are explicit.',
                    $column,
                ),
            );
        }
    }

    public function testContractDocumentNamesEveryUniqueKeyInvariant(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_UNIQUE_KEYS as $key) {
            $this->assertStringContainsString(
                $key,
                $contents,
                sprintf(
                    'Cross-namespace service policy contract must name the unique-key invariant %s so addressing is unambiguous.',
                    $key,
                ),
            );
        }
    }

    public function testContractDocumentEnumeratesEveryOutcomeValue(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_OUTCOME_VALUES as $value) {
            $this->assertStringContainsString(
                $value,
                $contents,
                sprintf(
                    'Cross-namespace service policy contract must enumerate the %s outcome so caller-facing surfaces can match on it.',
                    $value,
                ),
            );
        }
    }

    public function testContractDocumentReusesFailureCategoryTaxonomy(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_FAILURE_CATEGORY_VALUES as $value) {
            $this->assertStringContainsString(
                $value,
                $contents,
                sprintf(
                    'Cross-namespace service policy contract must name the FailureCategory value %s so handler failures map to the existing taxonomy.',
                    $value,
                ),
            );
        }
    }

    public function testContractDocumentNamesEveryConfigKey(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_CONFIG_KEYS as $key) {
            $this->assertStringContainsString(
                $key,
                $contents,
                sprintf(
                    'Cross-namespace service policy contract must name the config key %s so the boundary configuration surface is explicit.',
                    $key,
                ),
            );
        }
    }

    public function testContractDocumentCitesAdjacentContracts(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_CROSS_CONTRACT_CITATIONS as $citation) {
            $this->assertStringContainsString(
                $citation,
                $contents,
                sprintf(
                    'Cross-namespace service policy contract must cite %s so the adjacent contracts are explicit.',
                    $citation,
                ),
            );
        }
    }

    public function testContractDocumentEnumeratesBoundaryEnforcementOrder(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_ENFORCEMENT_STEPS as $step) {
            $this->assertStringContainsString(
                $step,
                $contents,
                sprintf('Cross-namespace service policy contract must enumerate the %s enforcement-order step.', $step),
            );
        }
    }

    public function testContractDocumentNamesAllThreeAuthorizationAxes(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_AUTHORIZATION_AXES as $axis) {
            $this->assertStringContainsString(
                $axis,
                $contents,
                sprintf(
                    'Cross-namespace service policy contract must name the %s authorization axis so caller-versus-target authorization is explicit.',
                    $axis,
                ),
            );
        }
    }

    public function testContractDocumentStatesDistinguishabilityInvariant(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/Resolution, authorization, throttling, concurrency, circuit-break,\s+and handler failure are recorded as distinct outcome values/i',
            $contents,
            'Cross-namespace service policy contract must state the distinguishability invariant verbatim.',
        );
        $this->assertMatchesRegularExpression(
            '/collapses any pair of these into a shared value[\s\S]{0,200}is out of contract/i',
            $contents,
            'Cross-namespace service policy contract must forbid collapsing distinct outcomes into a shared value.',
        );
    }

    public function testContractDocumentRecordsAuditFactsForRejections(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/accepted \*or\* rejected/i',
            $contents,
            'Cross-namespace service policy contract must state audit facts apply to accepted and rejected calls alike.',
        );
        $this->assertMatchesRegularExpression(
            '/that records a rejection without caller identity[\s\S]{0,200}is out of contract/i',
            $contents,
            'Cross-namespace service policy contract must forbid rejection rows without caller identity, address, or outcome.',
        );
    }

    public function testContractDocumentDeclaresLimitsAboveHandlerBinding(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/declared \*above\* the handler binding/i',
            $contents,
            'Cross-namespace service policy contract must state service-level limits are declared above the handler binding.',
        );
        $this->assertMatchesRegularExpression(
            '/applies to every\s+handler binding kind/i',
            $contents,
            'Cross-namespace service policy contract must state service-level limits apply across handler binding kinds.',
        );
    }

    public function testContractDocumentReusesCodecAndDataConverterModel(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/reuse the existing codec and data-converter model/i',
            $contents,
            'Cross-namespace service policy contract must state payload privacy reuses the existing codec and data-converter model.',
        );
        $this->assertMatchesRegularExpression(
            '/does not implement a parallel decoder|does not introduce a second resolver|does not own a second key model/i',
            $contents,
            'Cross-namespace service policy contract must forbid a second payload security model.',
        );
    }

    public function testContractDocumentPreservesNamespaceScopingForOperatorSurfaces(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/Operator surfaces[\s\S]{0,200}namespace\s*scoped/i',
            $contents,
            'Cross-namespace service policy contract must state operator surfaces are namespace scoped.',
        );
        $this->assertMatchesRegularExpression(
            '/hides rejections from the operator surface is out of\s+contract/i',
            $contents,
            'Cross-namespace service policy contract must require operator surfaces to expose boundary rejections.',
        );
    }

    public function testContractDocumentDoesNotIntroduceNewEnvironmentVariables(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/contract does not introduce new environment variables/i',
            $contents,
            'Cross-namespace service policy contract must state it does not introduce new environment variables.',
        );
    }

    public function testContractDocumentPinsPinningTestPath(): void
    {
        $contents = $this->documentContents();

        $this->assertStringContainsString(
            'tests/Unit/V2/CrossNamespaceServicePolicyDocumentationTest.php',
            $contents,
            'Cross-namespace service policy contract must name its own pinning test so future changes know where the guardrails live.',
        );
    }

    public function testServiceModelsExistOnTheirFrozenTables(): void
    {
        $this->assertSame(
            'workflow_service_endpoints',
            (new WorkflowServiceEndpoint())->getTable(),
            'WorkflowServiceEndpoint must point at the workflow_service_endpoints table frozen in the contract.',
        );
        $this->assertSame(
            'workflow_services',
            (new WorkflowService())->getTable(),
            'WorkflowService must point at the workflow_services table frozen in the contract.',
        );
        $this->assertSame(
            'workflow_service_operations',
            (new WorkflowServiceOperation())->getTable(),
            'WorkflowServiceOperation must point at the workflow_service_operations table frozen in the contract.',
        );
        $this->assertSame(
            'workflow_service_calls',
            (new WorkflowServiceCall())->getTable(),
            'WorkflowServiceCall must point at the workflow_service_calls table frozen in the contract.',
        );
    }

    public function testServiceCallModelCastsThePolicySnapshotColumns(): void
    {
        $casts = (new WorkflowServiceCall())->getCasts();

        foreach (
            [
                'deadline_policy',
                'idempotency_policy',
                'cancellation_policy',
                'retry_policy',
                'boundary_policy',
                'metadata',
            ] as $policyColumn
        ) {
            $this->assertArrayHasKey(
                $policyColumn,
                $casts,
                sprintf(
                    'WorkflowServiceCall must cast the %s JSON column so policy snapshots survive the round-trip frozen in the contract.',
                    $policyColumn,
                ),
            );
        }
    }

    public function testCatalogModelsCastBoundaryPolicyColumns(): void
    {
        foreach (
            [
                WorkflowServiceEndpoint::class,
                WorkflowService::class,
                WorkflowServiceOperation::class,
            ] as $modelClass
        ) {
            $casts = (new $modelClass())->getCasts();

            $this->assertArrayHasKey(
                'boundary_policy',
                $casts,
                sprintf(
                    '%s must cast boundary_policy so endpoint, service, and operation policy defaults survive the JSON round-trip frozen in the contract.',
                    $modelClass,
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
