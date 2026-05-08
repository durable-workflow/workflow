<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\TestCase;

/**
 * Pins the v1 -> v2 feature mapping contract documented in
 * docs/workflow/plan.md. This document is the index that tells product
 * docs, server/CLI/Waterline reasoning, and compatibility work where
 * every current feature lives in the v2 durable kernel.
 */
final class FeatureMappingDocumentationTest extends TestCase
{
    private const DOCUMENT = 'docs/workflow/plan.md';

    private const REQUIRED_HEADINGS = [
        '# Workflow V2 Feature Mapping and Compatibility Contract',
        '## Scope',
        '## Status',
        '## Managed Cloud Readiness',
        '## Feature Compatibility Matrix',
        '## New-In-V2 Capabilities',
        '## V2.0 Defaults',
        '## Child Parent-Close Policy Contract',
        '## Time, Timer, and Schedule Determinism Contract',
        '## Gap Analysis',
        '## Migration Strategy',
        '### Storage compatibility',
        '### Package and naming compatibility',
        '### Payload, config, and model compatibility',
        '### Adoption',
        '## Relationship To Other Contracts',
        '## Changing This Contract',
    ];

    private const REQUIRED_PARENT_CLOSE_POLICY_PINS = [
        '### Snapshot and default',
        '### Per-child override observability',
        '### Parent disposition matrix',
        '### Waterline observability',
        'workflow_child_calls.parent_close_policy',
        'workflow_links.parent_close_policy',
        '`abandon`, `request_cancel`, or',
        'ChildWorkflowOptions',
        'Workflow::child(...)',
        'Workflow::executeChildWorkflow(...)',
        '| Completion |',
        '| Failure |',
        '| Timeout |',
        '| Cancellation |',
        '| Termination |',
        '| Continue-as-new |',
        '| Reset |',
        'ParentClosePolicyApplied',
        'ParentClosePolicyFailed',
        'ParentClosePolicyEnforcer::enforce',
    ];

    private const REQUIRED_TIME_DETERMINISM_PINS = [
        '### Virtual time',
        '### Timer lifecycle and supersession',
        '### Activity timeout taxonomy',
        '### Workflow execution-timeout, run-timeout, and workflow-task-timeout',
        '### Workflow-level retry first-release contract',
        '### Named schedule lifecycle',
        '### Replay tests for timeout helpers and timer ordering',
        '`Workflow::now()`',
        '`Workflow\\V2\\now()`',
        '`Carbon::now()`',
        '`Carbon::setTestNow()`',
        '`workflow_run_timers`',
        '`TimerScheduled`',
        '`TimerFired`',
        '`TimerCancelled`',
        '`TimerTransportChunker`',
        '`schedule_to_start`',
        '`start_to_close`',
        '`schedule_to_close`',
        '`heartbeat`',
        '`ActivityTimeoutEnforcer::enforce`',
        '`execution_timeout`',
        '`run_timeout`',
        '`workflow_task_timeout_seconds`',
        '`WorkflowExecutor::timeoutRun`',
        'Top-level workflow runs do NOT retry',
        '`ActivityOptions`',
        '`ChildWorkflowRetryPolicy`',
        '`ScheduleManager`',
        '`ScheduleStatus::Active`',
        '`ScheduleStatus::Paused`',
        '`ScheduleOverlapPolicy::Skip`',
        '`BufferOne`',
        '`AllowAll`',
        '`CancelOther`',
        '`TerminateOther`',
        'workflow:v2:schedule-tick',
        'tests/Feature/V2/V2DeterministicTimeTest.php',
        'tests/Feature/V2/V2DeterministicTimeReplayTest.php',
        'tests/Feature/V2/V2ActivityTimeoutTest.php',
        'tests/Feature/V2/V2ScheduleTest.php',
        'tests/Unit/V2/WorkflowFiberContextTimeTest.php',
    ];

    private const REQUIRED_FEATURE_ROWS = [
        'Workflow start and identity',
        'Workflow status and output',
        'Activities',
        'Activity retries',
        'Activity heartbeats',
        'Timers and sleep',
        'Condition waits',
        'Signals',
        'Queries',
        'Updates',
        'Namespace service catalog and boundary policy',
        'Cross-namespace service calls',
        'Child workflows',
        'Continue-as-new',
        'Side effects',
        'Versioning and patches',
        'Sagas and compensation',
        'Parallel coordination',
        'Cancellation and termination',
        'Schedules',
        'Search attributes',
        'Memo',
        'Inbox/outbox message streams',
        'External payload storage',
        'Webhooks and external ingress',
        'Replay debug and history export',
        'Embedded v2 history import',
        'Waterline observability',
        'Worker compatibility and routing',
        'Worker deployments and rollout controls',
        'Sticky execution',
        'Task matching and long-poll coordination',
        'Backend capabilities and guardrails',
        'Retention and pruning',
    ];

    private const REQUIRED_DURABLE_HOMES = [
        'workflow_instances',
        'workflow_runs',
        'workflow_commands',
        'workflow_history_events',
        'workflow_tasks',
        'activity_executions',
        'activity_attempts',
        'workflow_run_timers',
        'workflow_run_waits',
        'workflow_signal_records',
        'workflow_updates',
        'workflow_child_calls',
        'workflow_links',
        'workflow_run_lineage_entries',
        'workflow_schedules',
        'workflow_schedule_history_events',
        'workflow_search_attributes',
        'workflow_memos',
        'workflow_messages',
        'workflow_run_summaries',
        'worker_compatibility_heartbeats',
        'workflow_service_endpoints',
        'workflow_services',
        'workflow_service_operations',
        'workflow_service_calls',
        'workflow_worker_build_id_rollouts',
        'workflow_runs.sticky_worker_id',
        'workflow_tasks.sticky_replay_mode',
        'workflow_runs.import_source',
        'workflow_runs.import_id',
        'workflow_runs.import_dedupe_key',
    ];

    private const REQUIRED_HISTORY_EVENTS = [
        'StartAccepted',
        'StartRejected',
        'WorkflowStarted',
        'ActivityScheduled',
        'ActivityStarted',
        'ActivityCompleted',
        'ActivityFailed',
        'ActivityTimedOut',
        'ActivityHeartbeatRecorded',
        'TimerScheduled',
        'TimerFired',
        'SignalReceived',
        'SignalApplied',
        'UpdateAccepted',
        'UpdateRejected',
        'UpdateApplied',
        'UpdateCompleted',
        'ChildWorkflowScheduled',
        'ChildRunStarted',
        'ChildRunCompleted',
        'WorkflowContinuedAsNew',
        'SideEffectRecorded',
        'VersionMarkerRecorded',
        'ScheduleTriggered',
        'SearchAttributesUpserted',
        'MemoUpserted',
        'MessageCursorAdvanced',
    ];

    private const REQUIRED_V2_DEFAULTS = [
        'Queries replay from committed history',
        'External commands default to instance-targeted active-run resolution',
        'Updates expose both wait-for-accepted and wait-for-completed modes',
        'Active v1 runs finish on v1',
        'Async closure transport is deferred',
        'Workflow-mode guardrails ship early',
        'Indexed search attributes and non-indexed memo are separate',
        'Workflows default to no retry unless an explicit retry policy is set',
        'Child workflows default to `ParentClosePolicy::Abandon`',
        'Supported backend combinations are validated early',
        'Operator actions are typed engine commands',
        'Cross-namespace service calls always write a durable service-call row',
        'Sticky execution is a replay optimization',
        'Reset is reserved as a later-phase command',
        'Local activities and worker sessions are deferred',
    ];

    private const REQUIRED_GAP_ITEMS = [
        'Current v1 workflow authoring APIs',
        'Current v1 runtime observability',
        'Active v1 runs',
        'Local activities',
        'Worker sessions',
        'Sticky execution',
        'Cross-namespace service calls',
        'Embedded v2 history import',
        'Reset',
        'Cross-process async closure transport',
        'Async closure compatibility across SDKs',
        'Unsupported backend combinations',
    ];

    private const REQUIRED_MIGRATION_POINTS = [
        'V2 does not interpret v1 tables as native runtime truth',
        'Active v1 executions finish on v1',
        'workflow:v2:history-import',
        'PHP_INT_MAX',
        'finish on v1, start new on v2',
        'durable-workflow/workflow',
        'Workflow\\V2',
        'workflows.v2.types',
        'embedded-to-server',
        'avro',
        'workflow-serializer-y',
        'workflow-serializer-base64',
        'SerializableClosure',
        'WorkflowMetadata',
        'ModelIdentifier',
        'OperatorObservabilityRepository',
        'Monolith',
        'Multi-app',
        'Microservice',
        'Operator-heavy',
        'Canary',
        'Drain',
        'Rollback',
        'Replay-debug',
        '"Mixed-fleet" is not a v2-internal rollout primitive',
        'v1→v2 transition',
    ];

    private const REQUIRED_RELATED_CONTRACTS = [
        'docs/api-stability.md',
        'docs/architecture/query-and-live-debug.md',
        'docs/architecture/child-outcome-source-of-truth.md',
        'docs/architecture/scheduler-correctness.md',
        'docs/architecture/worker-compatibility.md',
        'docs/architecture/worker-deployment.md',
        'docs/architecture/sticky-execution.md',
        'docs/architecture/workflow-service-calls-architecture.md',
        'docs/architecture/cross-namespace-service-policy.md',
        'docs/architecture/control-plane-split.md',
        'docs/architecture/webhook-and-command-taxonomy.md',
        'docs/deployment/ha-failover.md',
        'docs/deployment/multi-region.md',
        'docs/workflow-messages-architecture.md',
        'docs/search-attributes-architecture.md',
        'docs/workflow-memos-architecture.md',
        'docs/architecture/platform-conformance-suite.md',
    ];

    public function testContractDocumentExistsAndDeclaresFrozenSections(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_HEADINGS as $heading) {
            $this->assertStringContainsString(
                $heading,
                $contents,
                sprintf('Feature mapping contract is missing heading %s.', $heading),
            );
        }
    }

    public function testContractDocumentMapsCurrentProductFeatures(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_FEATURE_ROWS as $feature) {
            $this->assertStringContainsString(
                sprintf('| %s |', $feature),
                $contents,
                sprintf('Feature mapping contract must include a matrix row for %s.', $feature),
            );
        }
    }

    public function testContractDocumentNamesDurableHomes(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_DURABLE_HOMES as $home) {
            $this->assertStringContainsString(
                $home,
                $contents,
                sprintf('Feature mapping contract must name durable home %s.', $home),
            );
        }
    }

    public function testContractDocumentNamesHistoryEvents(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_HISTORY_EVENTS as $event) {
            $this->assertStringContainsString(
                $event,
                $contents,
                sprintf('Feature mapping contract must name history event %s.', $event),
            );
        }
    }

    public function testContractDocumentPinsV20Defaults(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_V2_DEFAULTS as $default) {
            $this->assertStringContainsString(
                $default,
                $contents,
                sprintf('Feature mapping contract must pin v2.0 default: %s.', $default),
            );
        }
    }

    public function testContractDocumentPinsChildParentClosePolicyContract(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_PARENT_CLOSE_POLICY_PINS as $pin) {
            $this->assertStringContainsString(
                $pin,
                $contents,
                sprintf('Child parent-close policy contract must include %s.', $pin),
            );
        }
    }

    public function testContractDocumentPinsTimeTimerAndScheduleDeterminismContract(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_TIME_DETERMINISM_PINS as $pin) {
            $this->assertStringContainsString(
                $pin,
                $contents,
                sprintf('Time, Timer, and Schedule Determinism contract must include %s.', $pin),
            );
        }
    }

    public function testContractDocumentDeclaresGapAnalysis(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_GAP_ITEMS as $item) {
            $this->assertStringContainsString(
                sprintf('| %s |', $item),
                $contents,
                sprintf('Feature mapping contract must analyze gap item %s.', $item),
            );
        }

        $this->assertStringContainsString(
            'There are no known current v1 product features left without a v2 home.',
            $contents,
            'Feature mapping contract must state the v1 parity conclusion explicitly.',
        );
    }

    public function testContractDocumentDeclaresMigrationStrategy(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_MIGRATION_POINTS as $point) {
            $this->assertStringContainsString(
                $point,
                $contents,
                sprintf('Feature mapping contract migration strategy must cover %s.', $point),
            );
        }
    }

    public function testContractDocumentCitesRelatedContracts(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_RELATED_CONTRACTS as $path) {
            $this->assertStringContainsString(
                $path,
                $contents,
                sprintf('Feature mapping contract must cite related contract %s.', $path),
            );
        }

        $this->assertStringContainsString(
            'tests/Unit/V2/FeatureMappingDocumentationTest.php',
            $contents,
            'Feature mapping contract must cite its own pinning test path.',
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
