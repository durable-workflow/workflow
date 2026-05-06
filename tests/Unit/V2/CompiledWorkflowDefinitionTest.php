<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use LogicException;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\V2\TestUpdateWorkflow;
use Workflow\V2\Support\CompiledWorkflowDefinition;
use Workflow\V2\Support\ServerlessWorkflowCompiler;
use Workflow\V2\Support\WorkflowDefinitionVersionSelector;

final class CompiledWorkflowDefinitionTest extends TestCase
{
    public function testSchemaDeclaresCompiledIrIdentityAndStepKinds(): void
    {
        $schema = CompiledWorkflowDefinition::schema();

        $this->assertSame(
            'durable-workflow.v2.compiled-workflow-ir',
            $schema['properties']['schema']['const'],
        );
        $this->assertSame(1, $schema['properties']['schema_version']['const']);
        $this->assertContains('steps', $schema['required']);
        $this->assertContains(
            CompiledWorkflowDefinition::STEP_STATE,
            $schema['properties']['steps']['items']['properties']['kind']['enum'],
        );
        $this->assertContains(
            CompiledWorkflowDefinition::STEP_ACTION,
            $schema['properties']['steps']['items']['properties']['kind']['enum'],
        );
    }

    public function testWorkflowClassCompilesStableCommandStepIds(): void
    {
        $compiled = CompiledWorkflowDefinition::fromWorkflowClass(
            TestUpdateWorkflow::class,
            'test-update-workflow',
            'definition-v1',
        );
        $again = CompiledWorkflowDefinition::fromWorkflowClass(
            TestUpdateWorkflow::class,
            'test-update-workflow',
            'definition-v1',
        );

        $this->assertSame(CompiledWorkflowDefinition::SCHEMA, $compiled['schema']);
        $this->assertSame('test-update-workflow', $compiled['workflow_type']);
        $this->assertSame('definition-v1', $compiled['definition_version']);
        $this->assertStringStartsWith('sha256:', $compiled['definition_fingerprint']);
        $this->assertSame($compiled['definition_fingerprint'], $again['definition_fingerprint']);
        $this->assertSame(array_column($compiled['steps'], 'id'), array_column($again['steps'], 'id'));

        $entry = $this->step($compiled, CompiledWorkflowDefinition::STEP_ENTRY, 'handle');
        $signal = $this->step($compiled, CompiledWorkflowDefinition::STEP_SIGNAL, 'name-provided');
        $query = $this->step($compiled, CompiledWorkflowDefinition::STEP_QUERY, 'currentState');
        $update = $this->step($compiled, CompiledWorkflowDefinition::STEP_UPDATE, 'approve');

        $this->assertSame(
            CompiledWorkflowDefinition::stableStepId(CompiledWorkflowDefinition::STEP_ENTRY, 'handle'),
            $entry['id'],
        );
        $this->assertSame(
            CompiledWorkflowDefinition::stableStepId(CompiledWorkflowDefinition::STEP_SIGNAL, 'name-provided'),
            $signal['id'],
        );
        $this->assertSame(
            CompiledWorkflowDefinition::stableStepId(CompiledWorkflowDefinition::STEP_QUERY, 'currentState'),
            $query['id'],
        );
        $this->assertSame(
            CompiledWorkflowDefinition::stableStepId(CompiledWorkflowDefinition::STEP_UPDATE, 'approve'),
            $update['id'],
        );
        $this->assertCount(2, $update['parameters']);
        $this->assertSame($entry['id'], $compiled['entrypoint_step_id']);
        $this->assertSame('definition_fingerprint', $compiled['version_selection']['strategy']);
    }

    public function testServerlessWorkflowCompileUsesStableStateAndActionStepIds(): void
    {
        $document = $this->serverlessWorkflowDocument('1.0.0', [
            [
                'name' => 'Validate Order',
                'type' => 'operation',
                'actions' => [
                    [
                        'name' => 'Reserve Stock',
                        'functionRef' => 'reserveStock',
                    ],
                ],
                'transition' => 'Ship Order',
            ],
            [
                'name' => 'Ship Order',
                'type' => 'operation',
                'end' => true,
            ],
        ], 'Validate Order');
        $reordered = $this->serverlessWorkflowDocument('1.0.0', [
            [
                'name' => 'Ship Order',
                'type' => 'operation',
                'end' => true,
            ],
            [
                'name' => 'Validate Order',
                'type' => 'operation',
                'actions' => [
                    [
                        'name' => 'Reserve Stock',
                        'functionRef' => 'reserveStock',
                    ],
                ],
                'transition' => 'Ship Order',
            ],
        ], 'Validate Order');

        $compiled = ServerlessWorkflowCompiler::compile($document);
        $compiledAgain = ServerlessWorkflowCompiler::compile($reordered);

        $validate = $this->step($compiled, CompiledWorkflowDefinition::STEP_STATE, 'Validate Order');
        $validateAgain = $this->step($compiledAgain, CompiledWorkflowDefinition::STEP_STATE, 'Validate Order');
        $ship = $this->step($compiled, CompiledWorkflowDefinition::STEP_STATE, 'Ship Order');
        $action = $this->step($compiled, CompiledWorkflowDefinition::STEP_ACTION, 'Reserve Stock');
        $actionAgain = $this->step($compiledAgain, CompiledWorkflowDefinition::STEP_ACTION, 'Reserve Stock');

        $this->assertSame(CompiledWorkflowDefinition::SOURCE_SERVERLESS_WORKFLOW, $compiled['source']['format']);
        $this->assertSame('order.fulfillment', $compiled['workflow_type']);
        $this->assertSame('1.0.0', $compiled['definition_version']);
        $this->assertSame($validate['id'], $compiled['entrypoint_step_id']);
        $this->assertSame($validate['id'], $validateAgain['id']);
        $this->assertSame($action['id'], $actionAgain['id']);
        $this->assertSame($ship['id'], $validate['transition_step_id']);
        $this->assertSame($validate['id'], $action['state_step_id']);
    }

    public function testServerlessWorkflowCompileRejectsDuplicateStateNames(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('duplicated');

        ServerlessWorkflowCompiler::compile($this->serverlessWorkflowDocument('1.0.0', [
            ['name' => 'Validate Order', 'type' => 'operation'],
            ['name' => 'Validate Order', 'type' => 'operation'],
        ]));
    }

    public function testVersionSelectorSupportsExactAndHighestSemanticVersionSelection(): void
    {
        $v1 = ServerlessWorkflowCompiler::compile(
            $this->serverlessWorkflowDocument('1.0.0', [['name' => 'Start', 'type' => 'operation', 'end' => true]]),
        );
        $v2 = ServerlessWorkflowCompiler::compile(
            $this->serverlessWorkflowDocument('2.0.0', [['name' => 'Start', 'type' => 'operation', 'end' => true]]),
        );

        $selected = WorkflowDefinitionVersionSelector::select([$v2, $v1], '1.0.0');
        $latest = WorkflowDefinitionVersionSelector::select([$v1, $v2]);
        $compiledSelected = ServerlessWorkflowCompiler::compileSelected([
            $this->serverlessWorkflowDocument('1.0.0', [['name' => 'Start', 'type' => 'operation', 'end' => true]]),
            $this->serverlessWorkflowDocument('2.0.0', [['name' => 'Start', 'type' => 'operation', 'end' => true]]),
        ], '2.0.0');

        $this->assertSame('1.0.0', $selected['definition_version']);
        $this->assertSame('explicit_version', $selected['version_selection']['strategy']);
        $this->assertSame(['1.0.0', '2.0.0'], $selected['version_selection']['available_versions']);

        $this->assertSame('2.0.0', $latest['definition_version']);
        $this->assertSame('highest_semantic_version', $latest['version_selection']['strategy']);
        $this->assertSame($v2['definition_fingerprint'], $latest['definition_fingerprint']);

        $this->assertSame('2.0.0', $compiledSelected['definition_version']);
        $this->assertSame('explicit_version', $compiledSelected['version_selection']['strategy']);
    }

    /**
     * @param list<array<string, mixed>> $states
     * @return array<string, mixed>
     */
    private function serverlessWorkflowDocument(string $version, array $states, ?string $start = null): array
    {
        return [
            'id' => 'order.fulfillment',
            'name' => 'Order Fulfillment',
            'version' => $version,
            'specVersion' => '0.8',
            'start' => $start ?? ($states[0]['name'] ?? null),
            'states' => $states,
        ];
    }

    /**
     * @param array<string, mixed> $compiled
     * @return array<string, mixed>
     */
    private function step(array $compiled, string $kind, string $name): array
    {
        foreach ($compiled['steps'] as $step) {
            if (($step['kind'] ?? null) === $kind && ($step['name'] ?? null) === $name) {
                return $step;
            }
        }

        $this->fail(sprintf('Missing compiled workflow step [%s] named [%s].', $kind, $name));
    }
}
