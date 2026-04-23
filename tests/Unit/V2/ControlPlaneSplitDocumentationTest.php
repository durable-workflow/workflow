<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\TestCase;

/**
 * Pins the v2 control-plane and execution-plane role split contract
 * documented in docs/architecture/control-plane-split.md. The doc is
 * the single reference used by product docs, CLI reasoning, Waterline
 * diagnostics, server deployment guidance, cloud topology, and test
 * coverage for how control-plane, execution-plane, matching,
 * history/projection, scheduler, and API ingress roles are named,
 * separated, and combined. Changes to any named guarantee must update
 * this test and the documented contract in the same change so drift
 * is reviewed deliberately.
 */
final class ControlPlaneSplitDocumentationTest extends TestCase
{
    private const DOCUMENT = 'docs/architecture/control-plane-split.md';

    private const REQUIRED_HEADINGS = [
        '# Workflow V2 Control-Plane and Execution-Plane Role Split Contract',
        '## Scope',
        '## Terminology',
        '## Role taxonomy',
        '### Control-plane role',
        '### Execution-plane role',
        '### Matching role',
        '### History/projection role',
        '### Scheduler role',
        '### API ingress role',
        '## Authority boundaries',
        '## Failure domains',
        '## Scaling boundaries',
        '## Supported deployment topologies',
        '### Embedded topology',
        '### Standalone server topology',
        '### Split control/execution topology',
        '## Migration path',
        '## Protocol version coordination',
        '## Authority over worker registration',
        '## Operator-visible role state',
        '## Test strategy alignment',
        '## What this contract does not yet guarantee',
        '## Changing this contract',
    ];

    private const REQUIRED_TERMS = [
        'Role',
        'Control plane',
        'Execution plane',
        'Matching role',
        'History/projection role',
        'Scheduler role',
        'API ingress role',
        'Deployment topology',
        'Authority boundary',
    ];

    private const REQUIRED_REFERENCED_CLASSES = [
        'WorkflowControlPlane',
        'DefaultWorkflowControlPlane',
        'RunSummaryProjector',
        'DefaultOperatorObservabilityRepository',
        'OperatorObservabilityRepository',
        'OperatorMetrics',
        'OperatorQueueVisibility',
        'ScheduleManager',
        'ScheduleTriggerResult',
        'ScheduleStartResult',
        'ScheduleWorkflowStarter',
        'WorkflowTaskBridge',
        'ActivityTaskBridge',
        'LongPollWakeStore',
        'HistoryExport',
        'RunWorkflowTask',
        'RunActivityTask',
        'RunTimerTask',
    ];

    private const REQUIRED_HTTP_ROUTES = [
        '/api/worker/workflow-tasks/poll',
        '/api/worker/workflow-tasks/{taskId}/complete',
        '/api/worker/workflow-tasks/{taskId}/fail',
        '/api/worker/activity-tasks/poll',
        '/api/worker/activity-tasks/{taskId}/complete',
        '/api/worker/activity-tasks/{taskId}/fail',
        '/api/system/metrics',
        '/api/system/repair',
        '/api/system/activity-timeouts',
        '/api/system/retention',
        '/api/workers/{workerId}',
    ];

    private const REQUIRED_SERVER_CONTROLLERS = [
        'WorkflowController',
        'WorkerManagementController',
        'WorkerProtocolVersionResolver',
        'ControlPlaneVersionResolver',
    ];

    private const REQUIRED_AUTHORITY_SURFACES = [
        'workflow_instances',
        'workflow_runs',
        'workflow_tasks',
        'activity_executions',
        'activity_attempts',
        'history_events',
        'run_summaries',
        'workflow_schedules',
        'worker_compatibility_heartbeats',
        'worker_registrations',
    ];

    private const REQUIRED_TOPOLOGIES = [
        '### Embedded topology',
        '### Standalone server topology',
        '### Split control/execution topology',
    ];

    private const REQUIRED_FAILURE_DOMAINS = [
        '**Control plane down**',
        '**Execution plane down**',
        '**Matching role down**',
        '**History/projection role down**',
        '**Scheduler down**',
        '**API ingress down**',
    ];

    public function testContractDocumentExistsAndDeclaresFrozenSections(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_HEADINGS as $heading) {
            $this->assertStringContainsString(
                $heading,
                $contents,
                sprintf('Control-plane split contract is missing heading %s.', $heading),
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
                sprintf('Control-plane split contract must define term %s in the Terminology section.', $term),
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
                    'Control-plane split contract must reference %s as the canonical implementation surface.',
                    $class
                ),
            );
        }
    }

    public function testContractDocumentNamesServerHttpSurface(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_HTTP_ROUTES as $route) {
            $this->assertStringContainsString(
                $route,
                $contents,
                sprintf(
                    'Control-plane split contract must name the %s HTTP route so role ownership is explicit.',
                    $route
                ),
            );
        }
    }

    public function testContractDocumentNamesServerControllers(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_SERVER_CONTROLLERS as $controller) {
            $this->assertStringContainsString(
                $controller,
                $contents,
                sprintf(
                    'Control-plane split contract must name %s as the server-side role binding surface.',
                    $controller
                ),
            );
        }
    }

    public function testContractDocumentNamesAuthorityBoundarySurfaces(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_AUTHORITY_SURFACES as $surface) {
            $this->assertStringContainsString(
                $surface,
                $contents,
                sprintf(
                    'Control-plane split contract must name the %s authority surface in the boundary table.',
                    $surface
                ),
            );
        }
    }

    public function testContractDocumentDescribesEveryTopology(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_TOPOLOGIES as $topology) {
            $this->assertStringContainsString(
                $topology,
                $contents,
                sprintf('Control-plane split contract must describe the %s topology.', $topology),
            );
        }
    }

    public function testContractDocumentEnumeratesEveryFailureDomain(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_FAILURE_DOMAINS as $failure) {
            $this->assertStringContainsString(
                $failure,
                $contents,
                sprintf('Control-plane split contract must describe the %s failure domain.', $failure),
            );
        }
    }

    public function testContractDocumentStatesEmbeddedMustRemainSupported(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/embedded[^.]*MUST continue to work|existing embedded host\s+is required to migrate/i',
            $contents,
            'Control-plane split contract must guarantee the embedded topology keeps working without forced migration.',
        );
    }

    public function testContractDocumentStatesStandaloneServerMustRemainSupported(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/MUST continue to work without topology-specific configuration/i',
            $contents,
            'Control-plane split contract must guarantee the standalone server topology keeps working without topology-specific configuration.',
        );
    }

    public function testContractDocumentStatesExecutionPlaneIsTheOnlyRoleRunningUserCode(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/\*\*only\*\*\s+role authorised to run user\s+code/i',
            $contents,
            'Control-plane split contract must state the execution plane is the only role authorised to run user workflow/activity code.',
        );
    }

    public function testContractDocumentStatesControlPlaneOwnsWorkflowStateTransitions(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/only[^.]*role authorised to perform the[\s\S]{0,40}mutations above/i',
            $contents,
            'Control-plane split contract must state the control plane is the only role authorised to perform durable workflow state mutations.',
        );
    }

    public function testContractDocumentStatesSynchronousProjectionGuarantee(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/synchronous with the event it reflects|Projection is synchronous/i',
            $contents,
            'Control-plane split contract must preserve the synchronous projection guarantee across the role split.',
        );
        $this->assertMatchesRegularExpression(
            '/MUST be immediately readable through the\s+projection/i',
            $contents,
            'Control-plane split contract must state successful claims/commands are immediately readable through the projection.',
        );
    }

    public function testContractDocumentStatesHistoryRecordingRemainsExactlyOnce(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/exactly-once per logical event/i',
            $contents,
            'Control-plane split contract must preserve the Phase 1 exactly-once history recording guarantee across the role split.',
        );
    }

    public function testContractDocumentStatesRoleSplitIsReversible(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/collapsing the roles back[^.]*legal topology|Each step is reversible/i',
            $contents,
            'Control-plane split contract must state the migration path is reversible — collapsing roles back onto a single node is always legal.',
        );
    }

    public function testContractDocumentDefersPhaseFiveAndSixExplicitly(): void
    {
        $contents = $this->documentContents();

        foreach (['#583', '#584'] as $issueRef) {
            $this->assertStringContainsString(
                $issueRef,
                $contents,
                sprintf(
                    'Control-plane split contract must explicitly defer follow-on roadmap issue %s.',
                    $issueRef
                ),
            );
        }
    }

    public function testContractDocumentBuildsOnPhasesOneTwoAndThree(): void
    {
        $contents = $this->documentContents();

        $this->assertStringContainsString(
            'docs/architecture/execution-guarantees.md',
            $contents,
            'Control-plane split contract must cite the Phase 1 execution-guarantees contract as its foundation.',
        );
        $this->assertStringContainsString(
            'docs/architecture/worker-compatibility.md',
            $contents,
            'Control-plane split contract must cite the Phase 2 worker-compatibility contract as its foundation.',
        );
        $this->assertStringContainsString(
            '#581',
            $contents,
            'Control-plane split contract must cite the Phase 3 task-matching roadmap (#581) as its foundation.',
        );
    }

    public function testContractDocumentStatesRoleAuthorityRule(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/Reads of another role[^.]*always allowed/i',
            $contents,
            'Control-plane split contract must state cross-role reads are always allowed.',
        );
        $this->assertMatchesRegularExpression(
            '/writes[^.]*always forbidden|MAY read[^.]*MAY NOT write/i',
            $contents,
            'Control-plane split contract must state cross-role writes are forbidden.',
        );
    }

    public function testContractDocumentStatesProtocolVersionCoordinationRule(): void
    {
        $contents = $this->documentContents();

        $this->assertStringContainsString(
            'WorkerProtocolVersionResolver',
            $contents,
            'Control-plane split contract must reference WorkerProtocolVersionResolver as the worker protocol version authority.',
        );
        $this->assertStringContainsString(
            'ControlPlaneVersionResolver',
            $contents,
            'Control-plane split contract must reference ControlPlaneVersionResolver as the operator protocol version authority.',
        );
        $this->assertMatchesRegularExpression(
            '/no role is\s+allowed to assume a newer contract than it has already negotiated/i',
            $contents,
            'Control-plane split contract must state no role may assume a newer contract than it has negotiated.',
        );
    }

    public function testContractDocumentStatesNoSecondHistoryWriter(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/MUST NOT introduce a second writer/i',
            $contents,
            'Control-plane split contract must forbid a second history writer when the role moves out of process.',
        );
    }

    public function testContractDocumentStatesAuditTrailIsObservable(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/MUST NOT make operations\s+harder to observe/i',
            $contents,
            'Control-plane split contract must state splitting a role out of process does not reduce operator observability.',
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
