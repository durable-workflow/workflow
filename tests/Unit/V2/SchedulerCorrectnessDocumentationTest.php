<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\TestCase;

/**
 * Pins the v2 scheduler correctness and cache independence contract
 * documented in docs/architecture/scheduler-correctness.md. The doc is
 * the single reference used by product docs, CLI reasoning, Waterline
 * diagnostics, server deployment guidance, cloud capacity planning,
 * and test coverage for durable dispatch state, wake acceleration,
 * degraded propagation, bounded discovery latency, backend
 * classification, and detection of misconfiguration. Changes to any
 * named guarantee must update this test and the documented contract in
 * the same change so drift is reviewed deliberately.
 */
final class SchedulerCorrectnessDocumentationTest extends TestCase
{
    private const DOCUMENT = 'docs/architecture/scheduler-correctness.md';

    private const REQUIRED_HEADINGS = [
        '# Workflow V2 Scheduler Correctness and Cache Independence Contract',
        '## Scope',
        '## Terminology',
        '## Durable dispatch state is the correctness substrate',
        '## The acceleration layer is optional',
        '## Degraded-mode behavior',
        '## Bounded discovery latency',
        '## Scheduler fire evaluation',
        '## Lease expiry and redelivery',
        '## Backend classification',
        '## Detection of misconfiguration and degraded acceleration',
        '## Config surface',
        '## Migration path',
        '## Operator-visible state',
        '## Test strategy alignment',
        '## What this contract does not yet guarantee',
        '## Changing this contract',
    ];

    private const REQUIRED_TERMS = [
        'Durable dispatch state',
        'Correctness substrate',
        'Acceleration layer',
        'Wake notification',
        'Wake propagation',
        'Degraded-mode',
        'Correctness boundary',
        'Bounded discovery latency',
    ];

    private const REQUIRED_DURABLE_TABLES = [
        '`workflow_tasks`',
        '`workflow_runs`',
        '`workflow_instances`',
        '`activity_executions`',
        '`activity_attempts`',
        '`workflow_schedules`',
    ];

    private const REQUIRED_REFERENCED_CLASSES = [
        'LongPollWakeStore',
        'CacheLongPollWakeStore',
        'LongPollCacheValidator',
        'WorkerCompatibilityFleet',
        'BackendCapabilities',
        'HealthCheck',
        'OperatorMetrics',
        'OperatorQueueVisibility',
        'ScheduleManager',
        'TaskRepair',
        'TaskRepairPolicy',
        'ActivityLease',
        'WorkerProtocolVersion',
    ];

    private const REQUIRED_CONFIG_KEYS = [
        'DW_V2_MULTI_NODE',
        'workflows.v2.long_poll.multi_node',
        'DW_V2_VALIDATE_CACHE_BACKEND',
        'workflows.v2.long_poll.validate_cache_backend',
        'DW_V2_CACHE_VALIDATION_MODE',
        'workflows.v2.long_poll.validation_mode',
        'DW_V2_TASK_DISPATCH_MODE',
        'workflows.v2.task_dispatch_mode',
        'DW_V2_TASK_REPAIR_REDISPATCH_AFTER_SECONDS',
        'DW_V2_TASK_REPAIR_LOOP_THROTTLE_SECONDS',
        'DW_V2_TASK_REPAIR_SCAN_LIMIT',
        'DW_V2_TASK_REPAIR_FAILURE_BACKOFF_MAX_SECONDS',
    ];

    private const REQUIRED_VALIDATION_MODES = ['`fail`', '`warn`', '`silent`'];

    private const REQUIRED_ACCELERATION_BACKENDS = ['`redis`', '`database`', '`memcached`'];

    private const REQUIRED_CORRECTNESS_DRIVERS = ['`mysql`', '`pgsql`', '`sqlite`', '`sqlsrv`'];

    private const REQUIRED_DEGRADED_MODE_SCENARIOS = [
        'Wake backend unreachable',
        'Wake backend partitioned',
        'Wake backend lost some signals',
        'Compatibility heartbeat cache unavailable',
        'Cache backend permanently unavailable',
    ];

    public function testContractDocumentExistsAndDeclaresFrozenSections(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_HEADINGS as $heading) {
            $this->assertStringContainsString(
                $heading,
                $contents,
                sprintf('Scheduler correctness contract is missing heading %s.', $heading),
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
                sprintf('Scheduler correctness contract must define term %s in the Terminology section.', $term),
            );
        }
    }

    public function testContractDocumentNamesEveryDurableCorrectnessTable(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_DURABLE_TABLES as $table) {
            $this->assertStringContainsString(
                $table,
                $contents,
                sprintf(
                    'Scheduler correctness contract must name the %s table as part of the correctness substrate.',
                    $table,
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
                    'Scheduler correctness contract must reference %s as the canonical implementation surface.',
                    $class,
                ),
            );
        }
    }

    public function testContractDocumentNamesConfigSurface(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_CONFIG_KEYS as $key) {
            $this->assertStringContainsString(
                $key,
                $contents,
                sprintf('Scheduler correctness contract must name the %s config/env key.', $key),
            );
        }
    }

    public function testContractDocumentNamesValidationModes(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_VALIDATION_MODES as $mode) {
            $this->assertStringContainsString(
                $mode,
                $contents,
                sprintf('Scheduler correctness contract must name the %s cache validation mode.', $mode),
            );
        }
    }

    public function testContractDocumentClassifiesAccelerationBackends(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_ACCELERATION_BACKENDS as $backend) {
            $this->assertStringContainsString(
                $backend,
                $contents,
                sprintf(
                    'Scheduler correctness contract must classify the %s cache store as a permitted acceleration backend.',
                    $backend,
                ),
            );
        }
    }

    public function testContractDocumentClassifiesCorrectnessDrivers(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_CORRECTNESS_DRIVERS as $driver) {
            $this->assertStringContainsString(
                $driver,
                $contents,
                sprintf(
                    'Scheduler correctness contract must classify the %s database driver as a correctness substrate backend.',
                    $driver,
                ),
            );
        }
    }

    public function testContractDocumentDescribesEveryDegradedModeScenario(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_DEGRADED_MODE_SCENARIOS as $scenario) {
            $this->assertStringContainsString(
                $scenario,
                $contents,
                sprintf('Scheduler correctness contract must describe the %s degraded-mode scenario.', $scenario),
            );
        }
    }

    public function testContractDocumentRejectsCacheAsCorrectnessSubstrate(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/correctness boundary[\s]+is the workflow database/i',
            $contents,
            'Scheduler correctness contract must name the workflow database as the correctness boundary.',
        );
        $this->assertStringContainsString(
            'not the shared cache',
            $contents,
            'Scheduler correctness contract must state the shared cache is not the correctness boundary.',
        );
        $this->assertMatchesRegularExpression(
            '/Wake notification is a performance optimisation, not[\s]+the\s+correctness boundary/i',
            $contents,
            'Scheduler correctness contract must state wake notification is a performance optimisation, not the correctness boundary.',
        );
    }

    public function testContractDocumentNamesAfterCommitDeferral(): void
    {
        $contents = $this->documentContents();

        $this->assertStringContainsString(
            'DB::afterCommit()',
            $contents,
            'Scheduler correctness contract must name the DB::afterCommit() deferral that makes signals an effect of durable commit.',
        );
    }

    public function testContractDocumentNamesBoundedDiscoveryLatencyKnobs(): void
    {
        $contents = $this->documentContents();

        $this->assertStringContainsString(
            'DEFAULT_LONG_POLL_TIMEOUT',
            $contents,
            'Scheduler correctness contract must name the DEFAULT_LONG_POLL_TIMEOUT constant that bounds discovery latency.',
        );
        $this->assertStringContainsString(
            'MAX_LONG_POLL_TIMEOUT',
            $contents,
            'Scheduler correctness contract must name the MAX_LONG_POLL_TIMEOUT constant that bounds discovery latency.',
        );
        $this->assertMatchesRegularExpression(
            '/redispatch_after_seconds/i',
            $contents,
            'Scheduler correctness contract must name the task_repair.redispatch_after_seconds bound.',
        );
        $this->assertMatchesRegularExpression(
            '/loop_throttle_seconds/i',
            $contents,
            'Scheduler correctness contract must name the task_repair.loop_throttle_seconds bound.',
        );
    }

    public function testContractDocumentPinsActivityLeaseDuration(): void
    {
        $contents = $this->documentContents();

        $this->assertStringContainsString(
            'DURATION_MINUTES',
            $contents,
            'Scheduler correctness contract must name the ActivityLease::DURATION_MINUTES constant.',
        );
        $this->assertMatchesRegularExpression(
            '/5 minutes/i',
            $contents,
            'Scheduler correctness contract must name the 5-minute activity lease duration.',
        );
    }

    public function testContractDocumentPinsSignalTtlUpperBound(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/60-second signal TTL upper bound/i',
            $contents,
            'Scheduler correctness contract must restate the 60-second wake signal TTL upper bound from the Phase 3 contract.',
        );
    }

    public function testContractDocumentStatesAccelerationIsOptional(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/acceleration layer is optional|acceleration layer.*optional/i',
            $contents,
            'Scheduler correctness contract must state the acceleration layer is optional.',
        );
        $this->assertMatchesRegularExpression(
            '/MUST NOT refuse to make progress because the acceleration[\s]+layer is unavailable/i',
            $contents,
            'Scheduler correctness contract must forbid nodes from halting on acceleration-layer unavailability.',
        );
        $this->assertMatchesRegularExpression(
            '/MUST NOT fabricate task assignments,[\s]+schedule fires, or lease expiries/i',
            $contents,
            'Scheduler correctness contract must forbid fabrication of state from acceleration signals.',
        );
    }

    public function testContractDocumentStatesDetectionSeparatesDegradedFromBroken(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/Health surfaces MUST distinguish degraded acceleration from\s*\n?\s*durable correctness failures/i',
            $contents,
            'Scheduler correctness contract must require health surfaces to distinguish degraded acceleration from durable correctness failures.',
        );
        $this->assertMatchesRegularExpression(
            '/MUST NOT mask a\s*\n?\s*correctness failure/i',
            $contents,
            'Scheduler correctness contract must forbid cache outages from masking correctness failures.',
        );
    }

    public function testContractDocumentDefersPhaseSixExplicitly(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/Phase 6[\s\S]{0,200}rollout-safety/i',
            $contents,
            'Scheduler correctness contract must explicitly defer scheduler leader election to the Phase 6 rollout-safety work.',
        );
        $this->assertMatchesRegularExpression(
            '/Scheduler leader election across replicas/i',
            $contents,
            'Scheduler correctness contract must explicitly defer leader election across scheduler replicas.',
        );
    }

    public function testContractDocumentBuildsOnEarlierPhases(): void
    {
        $contents = $this->documentContents();

        $this->assertStringContainsString(
            'docs/architecture/execution-guarantees.md',
            $contents,
            'Scheduler correctness contract must cite the Phase 1 execution-guarantees contract as its foundation.',
        );
        $this->assertStringContainsString(
            'docs/architecture/worker-compatibility.md',
            $contents,
            'Scheduler correctness contract must cite the Phase 2 worker-compatibility contract as its foundation.',
        );
        $this->assertStringContainsString(
            'docs/architecture/task-matching.md',
            $contents,
            'Scheduler correctness contract must cite the Phase 3 task-matching contract as its foundation.',
        );
        $this->assertMatchesRegularExpression(
            '/Phase 4[^\n]{0,200}role split/i',
            $contents,
            'Scheduler correctness contract must cite the Phase 4 control-plane and execution-plane role split as its foundation.',
        );
    }

    public function testContractDocumentPinsSnapshotChangedSemantics(): void
    {
        $contents = $this->documentContents();

        $this->assertStringContainsString(
            '`snapshot()`',
            $contents,
            'Scheduler correctness contract must name the snapshot() wake primitive.',
        );
        $this->assertStringContainsString(
            '`changed()`',
            $contents,
            'Scheduler correctness contract must name the changed() wake primitive.',
        );
        $this->assertStringContainsString(
            '`signal()`',
            $contents,
            'Scheduler correctness contract must name the signal() wake primitive.',
        );
    }

    public function testContractDocumentPinsDisallowedAccelerationBackends(): void
    {
        $contents = $this->documentContents();

        $this->assertStringContainsString(
            '`file`',
            $contents,
            'Scheduler correctness contract must name the file cache store as unsupported for multi-node acceleration.',
        );
        $this->assertStringContainsString(
            '`array`',
            $contents,
            'Scheduler correctness contract must name the array cache store as unsupported for multi-node acceleration.',
        );
    }

    public function testContractDocumentStatesRolesSplitPreservesCorrectness(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/Phase 4.*preserves every guarantee here unchanged|split is a topology\s*\n?\s*change, not a correctness change/i',
            $contents,
            'Scheduler correctness contract must state the Phase 4 role split does not change correctness.',
        );
    }

    public function testContractDocumentForbidsCacheReadAsCorrectnessSignal(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/reads a cache key and treats it as a correctness signal/i',
            $contents,
            'Scheduler correctness contract must forbid cache reads being treated as correctness signals during migration.',
        );
    }

    public function testContractDocumentRequiresDurableScheduleFireEvaluation(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/It does not read any cache value\s*\n?\s*or notifier state to decide which schedules are due/i',
            $contents,
            'Scheduler correctness contract must state schedule fire evaluation reads durable rows only.',
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
