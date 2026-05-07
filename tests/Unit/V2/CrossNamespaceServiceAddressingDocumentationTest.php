<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\TestCase;
use Workflow\V2\Enums\ServiceCallBindingKind;

/**
 * Pins the v2 cross-namespace service-addressing contract documented
 * in docs/architecture/cross-namespace-service-addressing.md.
 *
 * The contract names endpoint/service/operation as the caller-facing
 * durable capability address above workflow and activity routing. It
 * also keeps child workflows in their lineage-bearing orchestration
 * role instead of making parent/child identity the public
 * cross-namespace service boundary.
 */
final class CrossNamespaceServiceAddressingDocumentationTest extends TestCase
{
    private const DOCUMENT = 'docs/architecture/cross-namespace-service-addressing.md';

    private const ROUTING_DOCUMENT = 'docs/architecture/routing-precedence.md';

    private const CHILD_CALLS_DOCUMENT = 'docs/architecture/workflow-child-calls-architecture.md';

    private const REQUIRED_HEADINGS = [
        '# Workflow V2 Cross-Namespace Service Addressing Contract',
        '## Scope',
        '## Terminology',
        '## Contract Address',
        '## Endpoint Indirection',
        '## Handler Binding Kinds',
        '## Caller-Facing Dispatch',
        '## Resolution Semantics',
        '## Relationship With Task Routing',
        '## Relationship With Child Workflows',
        '## Durable Records And Visibility',
        '## Failure And Boundary Rules',
        '## Test Strategy Alignment',
        '## What This Contract Does Not Yet Guarantee',
        '## Changing This Contract',
    ];

    private const REQUIRED_TERMS = [
        'Service contract address',
        'Endpoint',
        'Service',
        'Operation',
        'Target namespace',
        'Caller namespace',
        'Handler binding',
        'Service call record',
        'Public service surface',
    ];

    private const REQUIRED_HANDLER_BINDING_KINDS = [
        'start_workflow',
        'signal_workflow',
        'update_workflow',
        'query_workflow',
        'activity_execution',
        'invocable_http',
    ];

    private const REQUIRED_TABLES = [
        'workflow_service_endpoints',
        'workflow_services',
        'workflow_service_operations',
        'workflow_service_calls',
    ];

    private const REQUIRED_MODELS = [
        'WorkflowServiceEndpoint',
        'WorkflowService',
        'WorkflowServiceOperation',
        'WorkflowServiceCall',
        'WorkflowInstance',
        'WorkflowRun',
        'WorkflowUpdate',
    ];

    private const REQUIRED_FIELDS = [
        'endpoint_name',
        'service_name',
        'operation_name',
        'namespace',
        'caller_namespace',
        'target_namespace',
        'handler_binding_kind',
        'handler_target_reference',
        'handler_binding',
        'resolved_binding_kind',
        'resolved_target_reference',
        'caller_workflow_instance_id',
        'caller_workflow_run_id',
        'linked_workflow_instance_id',
        'linked_workflow_run_id',
        'linked_workflow_update_id',
    ];

    private const REQUIRED_MIGRATIONS = [
        'src/migrations/2026_04_24_000190_create_workflow_service_endpoints_table.php',
        'src/migrations/2026_04_24_000191_create_workflow_services_table.php',
        'src/migrations/2026_04_24_000192_create_workflow_service_operations_table.php',
        'src/migrations/2026_04_24_000193_create_workflow_service_calls_table.php',
    ];

    private const REQUIRED_MODEL_FILES = [
        'src/V2/Models/WorkflowServiceEndpoint.php',
        'src/V2/Models/WorkflowService.php',
        'src/V2/Models/WorkflowServiceOperation.php',
        'src/V2/Models/WorkflowServiceCall.php',
    ];

    public function testContractDocumentExistsAndDeclaresFrozenSections(): void
    {
        $contents = $this->documentContents(self::DOCUMENT);

        foreach (self::REQUIRED_HEADINGS as $heading) {
            $this->assertStringContainsString(
                $heading,
                $contents,
                sprintf('Cross-namespace service contract is missing heading %s.', $heading),
            );
        }
    }

    public function testContractDocumentDefinesEveryNamedTerm(): void
    {
        $contents = $this->documentContents(self::DOCUMENT);

        foreach (self::REQUIRED_TERMS as $term) {
            $this->assertStringContainsString(
                sprintf('**%s**', $term),
                $contents,
                sprintf('Cross-namespace service contract must define term %s.', $term),
            );
        }
    }

    public function testContractDocumentPinsEndpointServiceOperationAddress(): void
    {
        $contents = $this->documentContents(self::DOCUMENT);

        $this->assertStringContainsString(
            '`endpoint/service/operation`',
            $contents,
            'Cross-namespace service contract must pin endpoint/service/operation as the stable address.',
        );
        $this->assertMatchesRegularExpression(
            '/endpoint_name[\s\S]{0,120}service_name[\s\S]{0,120}operation_name/i',
            $contents,
            'Cross-namespace service contract must map the address segments to endpoint, service, and operation names.',
        );
    }

    public function testContractDocumentNamesEveryFrozenHandlerBindingKind(): void
    {
        $contents = $this->documentContents(self::DOCUMENT);

        foreach (self::REQUIRED_HANDLER_BINDING_KINDS as $bindingKind) {
            $this->assertStringContainsString(
                sprintf('`%s`', $bindingKind),
                $contents,
                sprintf('Cross-namespace service contract must pin handler binding kind %s.', $bindingKind),
            );
        }
    }

    public function testContractDocumentNamesEveryRuntimeResolvedBindingKind(): void
    {
        $contents = $this->documentContents(self::DOCUMENT);

        foreach (ServiceCallBindingKind::cases() as $bindingKind) {
            $this->assertStringContainsString(
                sprintf('`%s`', $bindingKind->value),
                $contents,
                sprintf(
                    'Cross-namespace service contract must pin runtime resolved binding kind %s.',
                    $bindingKind->value,
                ),
            );
        }
    }

    public function testContractDocumentNamesEveryRegistryTableAndField(): void
    {
        $contents = $this->documentContents(self::DOCUMENT);

        foreach (self::REQUIRED_TABLES as $table) {
            $this->assertStringContainsString(
                $table,
                $contents,
                sprintf('Cross-namespace service contract must name registry table %s.', $table),
            );
        }

        foreach (self::REQUIRED_FIELDS as $field) {
            $this->assertStringContainsString(
                $field,
                $contents,
                sprintf('Cross-namespace service contract must name service field %s.', $field),
            );
        }
    }

    public function testContractDocumentReferencesOperatorVisibleModels(): void
    {
        $contents = $this->documentContents(self::DOCUMENT);

        foreach (self::REQUIRED_MODELS as $model) {
            $this->assertStringContainsString(
                $model,
                $contents,
                sprintf('Cross-namespace service contract must reference model %s.', $model),
            );
        }
    }

    public function testContractDocumentStatesCallerDoesNotDispatchByRawQueueTopology(): void
    {
        $contents = strtolower($this->normalizedDocumentContents(self::DOCUMENT));

        $this->assertStringContainsString(
            'caller-facing dispatch over the contract address rather than raw `(namespace, connection, queue)` fields',
            $contents,
            'Cross-namespace service contract must state callers dispatch by contract address instead of raw queue topology.',
        );
        $this->assertStringContainsString(
            'the caller speaks in durable capability names, not worker topology',
            $contents,
            'Cross-namespace service contract must state callers speak in durable capability names, not worker topology.',
        );
    }

    public function testContractDocumentStatesServiceDispatchDoesNotRewriteTaskRouting(): void
    {
        $contents = $this->normalizedDocumentContents(self::DOCUMENT);

        $this->assertStringContainsString(
            'namespace still partitions the poll surface, but service dispatch is not itself a task-routing rewrite',
            strtolower($contents),
            'Cross-namespace service contract must state service dispatch is additive over routing, not a task-routing rewrite.',
        );
        $this->assertStringContainsString(
            'cross-namespace service addressing is therefore a contract boundary, not a queue-selection trick',
            strtolower($contents),
            'Cross-namespace service contract must state service addressing is a contract boundary, not a queue-selection trick.',
        );
    }

    public function testContractDocumentStatesChildWorkflowsRemainLineageNotServiceSurface(): void
    {
        $contents = $this->normalizedDocumentContents(self::DOCUMENT);

        $this->assertStringContainsString(
            'child workflows remain lineage-bearing orchestration',
            strtolower($contents),
            'Cross-namespace service contract must keep child workflows in their lineage-bearing orchestration role.',
        );
        $this->assertStringContainsString(
            'the public cross-namespace service surface',
            strtolower($contents),
            'Cross-namespace service contract must explicitly distinguish child workflows from the public service surface.',
        );
    }

    public function testRegistryMigrationsStillExposeContractTablesAndColumns(): void
    {
        foreach (self::REQUIRED_MIGRATIONS as $relativePath) {
            $contents = $this->sourceContents($relativePath);

            $this->assertStringContainsString(
                'workflow_service',
                $contents,
                sprintf('Migration %s must remain part of the service registry surface.', $relativePath),
            );
        }

        $operationMigration = $this->sourceContents(
            'src/migrations/2026_04_24_000192_create_workflow_service_operations_table.php',
        );
        $callMigration = $this->sourceContents(
            'src/migrations/2026_04_24_000193_create_workflow_service_calls_table.php',
        );

        foreach (['handler_binding_kind', 'handler_target_reference', 'handler_binding'] as $field) {
            $this->assertStringContainsString($field, $operationMigration);
        }

        foreach (['resolved_binding_kind', 'resolved_target_reference', 'target_namespace'] as $field) {
            $this->assertStringContainsString($field, $callMigration);
        }
    }

    public function testServiceModelsRemainConfigurableRelationshipSurfaces(): void
    {
        foreach (self::REQUIRED_MODEL_FILES as $relativePath) {
            $contents = $this->sourceContents($relativePath);

            $this->assertStringContainsString(
                'ConfiguredV2Models::resolve',
                $contents,
                sprintf('Model file %s must keep configurable relationship resolution.', $relativePath),
            );
        }

        $operationModel = $this->sourceContents('src/V2/Models/WorkflowServiceOperation.php');
        $callModel = $this->sourceContents('src/V2/Models/WorkflowServiceCall.php');

        $this->assertStringContainsString("'handler_binding' => 'array'", $operationModel);
        $this->assertStringContainsString('linkedUpdate', $callModel);
        $this->assertStringContainsString('callerRun', $callModel);
    }

    public function testRoutingDocumentPointsToServiceAddressingInsteadOfRawCrossNamespaceRouting(): void
    {
        $contents = $this->normalizedDocumentContents(self::ROUTING_DOCUMENT);

        $this->assertStringContainsString(
            'docs/architecture/cross-namespace-service-addressing.md',
            $contents,
            'Routing contract must cite the cross-namespace service-addressing contract.',
        );
        $this->assertStringContainsString(
            'service dispatch is not itself a task-routing rewrite',
            strtolower($contents),
            'Routing contract must state service dispatch does not rewrite task routing.',
        );
        $this->assertStringNotContainsString(
            'cross-namespace calls are out of scope for this contract',
            strtolower($contents),
            'Routing contract must not leave cross-namespace service calls as an unqualified out-of-scope gap.',
        );
    }

    public function testChildCallsDocumentKeepsLineageSeparateFromPublicServiceAddress(): void
    {
        $contents = $this->normalizedDocumentContents(self::CHILD_CALLS_DOCUMENT);

        $this->assertStringContainsString(
            'docs/architecture/cross-namespace-service-addressing.md',
            $contents,
            'Child-calls contract must cite the cross-namespace service-addressing contract.',
        );
        $this->assertStringContainsString(
            'child workflow calls remain lineage-bearing orchestration',
            strtolower($contents),
            'Child-calls contract must state child workflows remain lineage-bearing orchestration.',
        );
        $this->assertStringContainsString(
            'not the public cross-namespace service surface',
            strtolower($contents),
            'Child-calls contract must state child workflows are not the public cross-namespace service surface.',
        );
        $this->assertStringContainsString(
            '`endpoint/service/operation`',
            $contents,
            'Child-calls contract must name endpoint/service/operation as the service address.',
        );
    }

    private function documentContents(string $relativePath): string
    {
        $path = dirname(__DIR__, 3) . '/' . $relativePath;
        $contents = file_get_contents($path);

        $this->assertIsString($contents, sprintf('Could not read %s.', $relativePath));

        return $contents;
    }

    private function normalizedDocumentContents(string $relativePath): string
    {
        return (string) preg_replace('/\s+/', ' ', $this->documentContents($relativePath));
    }

    private function sourceContents(string $relativePath): string
    {
        $path = dirname(__DIR__, 3) . '/' . $relativePath;
        $contents = file_get_contents($path);

        $this->assertIsString($contents, sprintf('Could not read %s.', $relativePath));

        return $contents;
    }
}
