<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\TestCase;
use Workflow\V2\Support\ActivityOptions;
use Workflow\V2\Support\ChildWorkflowOptions;
use Workflow\V2\Support\RoutingResolver;
use Workflow\WorkflowOptions;

/**
 * Pins the v2 routing precedence and inheritance contract documented
 * in docs/architecture/routing-precedence.md. The doc is the single
 * reference used by product docs, CLI reasoning, Waterline diagnostics,
 * server deployment guidance, and test coverage for how workflow-task
 * and activity-task routing targets are resolved, snapped, and
 * inherited across retries, continue-as-new, and parent-to-child
 * transitions. Changes to any named guarantee must update this test
 * and the documented contract in the same change so drift is reviewed
 * deliberately.
 */
final class RoutingPrecedenceDocumentationTest extends TestCase
{
    private const DOCUMENT = 'docs/architecture/routing-precedence.md';

    private const REQUIRED_HEADINGS = [
        '# Workflow V2 Routing Precedence and Inheritance Contract',
        '## Scope',
        '## Terminology',
        '## Guaranteeing authority',
        '## Resolution at workflow start',
        '## Resolution at child-workflow scheduling',
        '## Resolution at activity scheduling',
        '## Schedule-triggered runs',
        '## Snapped routing columns',
        '## Retry inheritance',
        '### Activity-attempt retries',
        '### Child-workflow retries',
        '### Workflow-task retries and repair',
        '### Activity heartbeats',
        '## Continue-as-new inheritance',
        '## Snapped routing preserves dedicated-queue and same-server affinity',
        '## Effective routing in projections',
        '## Interaction with compatibility',
        '## Config surface and defaults',
        '## What this contract does not yet guarantee',
        '## Test strategy alignment',
        '## Changing this contract',
    ];

    private const REQUIRED_TERMS = [
        'Routing target',
        'Per-call options',
        'Class defaults',
        'Snapped routing',
        'Inheritance',
        'Resolution',
        'Re-resolution',
        'Dedicated-queue pattern',
        'Same-server affinity pattern',
    ];

    private const REQUIRED_REFERENCED_CLASSES = [
        'RoutingResolver',
        'WorkflowOptions',
        'ActivityOptions',
        'ChildWorkflowOptions',
        'WorkflowStub',
        'WorkflowExecutor',
        'ScheduleManager',
        'PhpClassScheduleStarter',
        'TaskRepair',
        'RunDetailView',
        'RunListItemView',
        'OperatorQueueVisibility',
        'WorkerCompatibilityFleet',
        'ActivityTaskClaimer',
        'WorkflowMetadata',
        'DefaultPropertyCache',
    ];

    private const REQUIRED_DURABLE_COLUMNS = [
        'workflow_runs.connection',
        'workflow_runs.queue',
        'workflow_tasks.connection',
        'workflow_tasks.queue',
        'activity_executions.connection',
        'activity_executions.queue',
        'workflow_schedules.connection',
        'workflow_schedules.queue',
    ];

    private const REQUIRED_RESOLVER_METHODS = [
        'RoutingResolver::workflowConnection',
        'RoutingResolver::workflowQueue',
        'RoutingResolver::activityConnection',
        'RoutingResolver::activityQueue',
    ];

    private const REQUIRED_CONFIG_KEYS = [
        'queue.default',
        'queue.connections.<connection>.queue',
        'queue.connections.<name>.queue',
        'workflows.v2.namespace',
    ];

    private const REQUIRED_CROSS_CONTRACT_CITATIONS = [
        'docs/architecture/execution-guarantees.md',
        'docs/architecture/worker-compatibility.md',
        'docs/architecture/task-matching.md',
        'docs/architecture/rollout-safety.md',
    ];

    private const REQUIRED_ROADMAP_PHASES = ['Phase 1', 'Phase 2', 'Phase 3', 'Phase 4', 'Phase 5'];

    public function testContractDocumentExistsAndDeclaresFrozenSections(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_HEADINGS as $heading) {
            $this->assertStringContainsString(
                $heading,
                $contents,
                sprintf('Routing precedence contract is missing heading %s.', $heading),
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
                sprintf('Routing precedence contract must define term %s in the Terminology section.', $term),
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
                    'Routing precedence contract must reference %s as a canonical implementation surface.',
                    $class
                ),
            );
        }
    }

    public function testContractDocumentNamesEveryDurableRoutingColumn(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_DURABLE_COLUMNS as $column) {
            $this->assertStringContainsString(
                $column,
                $contents,
                sprintf(
                    'Routing precedence contract must name the durable column %s so snapshots are explicit.',
                    $column
                ),
            );
        }
    }

    public function testContractDocumentNamesEveryResolverAuthorityMethod(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_RESOLVER_METHODS as $method) {
            $this->assertStringContainsString(
                $method,
                $contents,
                sprintf('Routing precedence contract must name the resolver authority %s.', $method),
            );
        }
    }

    public function testContractDocumentNamesEveryConfigFallbackKey(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_CONFIG_KEYS as $key) {
            $this->assertStringContainsString(
                $key,
                $contents,
                sprintf(
                    'Routing precedence contract must name the config key %s so the fallback tail is visible.',
                    $key
                ),
            );
        }
    }

    public function testContractDocumentCitesAdjacentPhaseContracts(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_CROSS_CONTRACT_CITATIONS as $citation) {
            $this->assertStringContainsString(
                $citation,
                $contents,
                sprintf('Routing precedence contract must cite %s so the adjacent phases are explicit.', $citation),
            );
        }
    }

    public function testContractDocumentCitesAdjacentRoadmapPhases(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_ROADMAP_PHASES as $phase) {
            $this->assertStringContainsString(
                $phase,
                $contents,
                sprintf('Routing precedence contract must cite %s so the adjacent phases are explicit.', $phase),
            );
        }
    }

    public function testContractDocumentPinsPinningTestPath(): void
    {
        $contents = $this->documentContents();

        $this->assertStringContainsString(
            'tests/Unit/V2/RoutingPrecedenceDocumentationTest.php',
            $contents,
            'Routing precedence contract must name its own pinning test so future changes know where the guardrails live.',
        );
    }

    public function testContractDocumentStatesWorkflowStartOptionsOnlyAffectWorkflowTasks(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/Workflow start options override workflow defaults \*\*only for\s+workflow tasks\*\*/i',
            $contents,
            'Routing precedence contract must state workflow start options only override defaults for workflow tasks, not activities.',
        );
    }

    public function testContractDocumentStatesChildCallOnlyOverridesChildsOwnTaskRouting(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/Child-call options override the child workflow\'s own task routing,\s*\*\*not every activity inside that child\*\*/i',
            $contents,
            "Routing precedence contract must state child-call options override the child's own task routing, not activities inside that child.",
        );
    }

    public function testContractDocumentStatesActivityLevelDefaultsWinForActivityExecutions(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/Activity-level routing defaults win for activity executions/i',
            $contents,
            'Routing precedence contract must state activity-level routing defaults win for activity executions.',
        );
    }

    public function testContractDocumentStatesRetriesReuseSnappedRouting(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/(Activity retries never re-run resolution|reuse the snapshot|Retries never re-resolve)/i',
            $contents,
            'Routing precedence contract must state retries never re-resolve routing and instead reuse the snapshot.',
        );
        $this->assertMatchesRegularExpression(
            '/heartbeats[\s\S]{0,200}(never change routing|extend the lease on the queue the attempt already belongs to)/i',
            $contents,
            'Routing precedence contract must state heartbeats never change routing.',
        );
    }

    public function testContractDocumentStatesContinueAsNewInheritsSnapshotAndCompatibility(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/continued run therefore inherits both routing and compatibility\s+from the previous run by default/i',
            $contents,
            'Routing precedence contract must state continue-as-new inherits both routing and compatibility from the previous run by default.',
        );
    }

    public function testContractDocumentStatesNewRunSnapshotsAtCreation(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/New run snapshots inherited values on creation/i',
            $contents,
            'Routing precedence contract must state new runs snapshot inherited values on creation.',
        );
    }

    public function testContractDocumentPreservesDedicatedQueueAndAffinityPatterns(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/Snapped routing preserves dedicated-queue and same-server affinity/i',
            $contents,
            'Routing precedence contract must state snapped routing preserves dedicated-queue and same-server affinity patterns.',
        );
        $this->assertMatchesRegularExpression(
            '/repair path uses the same snapshot/i',
            $contents,
            'Routing precedence contract must state the repair path uses the same snapshot so affinity is preserved across recovery.',
        );
    }

    public function testContractDocumentExposesEffectiveRoutingInProjections(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/Effective routing in projections/i',
            $contents,
            'Routing precedence contract must title the Effective-routing-in-projections section so operator-visibility is explicit.',
        );
        $this->assertMatchesRegularExpression(
            '/consume the snapped values; they do not recompute\s+routing from class defaults/i',
            $contents,
            'Routing precedence contract must state projections consume snapshot values and do not recompute routing from class defaults.',
        );
    }

    public function testContractDocumentStatesResolverIsSoleAuthority(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/the sole authority that turns per-call\s+options and class defaults into a `\(connection, queue\)` pair/i',
            $contents,
            'Routing precedence contract must state RoutingResolver is the sole authority on (connection, queue) resolution.',
        );
        $this->assertMatchesRegularExpression(
            '/No other class is allowed to\s+compute `\(connection, queue\)`/i',
            $contents,
            'Routing precedence contract must forbid other classes from computing (connection, queue) from the same inputs.',
        );
    }

    public function testContractDocumentPreservesWireShapeOfOptions(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/WorkflowOptions[\s\S]{0,300}carries only `\?string \$connection` and\s+`\?string \$queue`/i',
            $contents,
            'Routing precedence contract must freeze the WorkflowOptions wire shape at exactly (connection, queue).',
        );
        $this->assertMatchesRegularExpression(
            '/Any additional fields on `WorkflowOptions` are a\s+protocol change/i',
            $contents,
            'Routing precedence contract must flag additional fields on WorkflowOptions as a protocol change.',
        );
    }

    public function testWorkflowOptionsWireShapeMatchesFrozenContract(): void
    {
        $reflection = new \ReflectionClass(WorkflowOptions::class);

        $properties = array_map(
            static fn (\ReflectionProperty $p): string => $p->getName(),
            $reflection->getProperties(),
        );

        sort($properties);

        $this->assertSame(
            ['connection', 'queue'],
            $properties,
            'WorkflowOptions must carry exactly the (connection, queue) fields frozen in the routing precedence contract.',
        );
    }

    public function testActivityOptionsRoutingFieldsMatchFrozenContract(): void
    {
        $reflection = new \ReflectionClass(ActivityOptions::class);
        $names = array_map(
            static fn (\ReflectionProperty $p): string => $p->getName(),
            $reflection->getProperties(),
        );

        foreach (['connection', 'queue'] as $routingField) {
            $this->assertContains(
                $routingField,
                $names,
                sprintf(
                    'ActivityOptions must carry the %s routing field frozen in the routing precedence contract.',
                    $routingField
                ),
            );
        }

        $options = new ActivityOptions();
        $this->assertFalse(
            $options->hasRoutingOverrides(),
            'ActivityOptions with no connection or queue must report hasRoutingOverrides() as false.',
        );

        $options = new ActivityOptions(queue: 'billing');
        $this->assertTrue(
            $options->hasRoutingOverrides(),
            'ActivityOptions with a non-null queue must report hasRoutingOverrides() as true.',
        );
    }

    public function testChildWorkflowOptionsRoutingFieldsMatchFrozenContract(): void
    {
        $reflection = new \ReflectionClass(ChildWorkflowOptions::class);
        $names = array_map(
            static fn (\ReflectionProperty $p): string => $p->getName(),
            $reflection->getProperties(),
        );

        foreach (['connection', 'queue'] as $routingField) {
            $this->assertContains(
                $routingField,
                $names,
                sprintf(
                    'ChildWorkflowOptions must carry the %s routing field frozen in the routing precedence contract.',
                    $routingField
                ),
            );
        }

        $options = new ChildWorkflowOptions();
        $this->assertFalse(
            $options->hasRoutingOverrides(),
            'ChildWorkflowOptions with no connection or queue must report hasRoutingOverrides() as false.',
        );

        $options = new ChildWorkflowOptions(connection: 'redis');
        $this->assertTrue(
            $options->hasRoutingOverrides(),
            'ChildWorkflowOptions with a non-null connection must report hasRoutingOverrides() as true.',
        );
    }

    public function testRoutingResolverSurfaceMatchesFrozenContract(): void
    {
        $reflection = new \ReflectionClass(RoutingResolver::class);

        foreach (['workflowConnection', 'workflowQueue', 'activityConnection', 'activityQueue'] as $method) {
            $this->assertTrue(
                $reflection->hasMethod($method),
                sprintf('RoutingResolver must expose the frozen %s method so callers have one authority.', $method),
            );
        }
    }

    public function testContractDocumentStatesCompatibilityAndRoutingAreIndependent(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/Routing and compatibility are independent axes/i',
            $contents,
            'Routing precedence contract must state routing and compatibility are independent axes.',
        );
    }

    public function testContractDocumentDefersPriorityAndShardingExplicitly(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/Queue priority ordering\.[^.]*not bake priority\s+into queue names or into the resolver/i',
            $contents,
            'Routing precedence contract must defer queue priority ordering to hosts.',
        );
        $this->assertMatchesRegularExpression(
            '/Sharding by task id\.[^.]*not shard tasks\s+across pollers by hash/i',
            $contents,
            'Routing precedence contract must defer task-id sharding as an operator-level queue-naming choice.',
        );
    }

    public function testContractDocumentDoesNotIntroduceNewEnvironmentVariables(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/contract does not introduce new environment variables/i',
            $contents,
            'Routing precedence contract must state it does not introduce new environment variables.',
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
