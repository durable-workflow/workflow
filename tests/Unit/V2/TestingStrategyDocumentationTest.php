<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\TestCase;

/**
 * Pins the workflow v2 testing-strategy contract documented in
 * docs/architecture/testing-strategy.md. The document is the
 * authority for the supported testing API surface and for the
 * required test buckets implementations must cover.
 */
final class TestingStrategyDocumentationTest extends TestCase
{
    private const DOCUMENT = 'docs/architecture/testing-strategy.md';

    private const REQUIRED_HEADINGS = [
        '# Workflow V2 Testing Strategy Contract',
        '## Scope',
        '## Status',
        '## Testing API Surface',
        '## Bucket Status Conventions',
        '## Required Test Buckets',
        '### Replay and command correctness',
        '### Identity, lifecycle, and routing',
        '### Time, timers, schedules, and updates',
        '### Children, sagas, and parallel coordination',
        '### Visibility, metadata, and projections',
        '### Worker protocol, transport, and operator surfaces',
        '### Service catalog and cross-namespace calls',
        '### Backups, imports, and migration',
        '## Suite Layering',
        '## Test-Mode Defaults',
        '## Documentation Pins',
        '## Relationship To Other Contracts',
        '## Changing This Contract',
    ];

    private const REQUIRED_API_SURFACE = [
        '`WorkflowStub::fake()`',
        '`WorkflowStub::mock(',
        '`WorkflowStub::assertDispatched(',
        '`assertDispatchedTimes(',
        '`assertNotDispatched(',
        '`assertNothingDispatched()`',
        'Framework time travel',
        '`WorkflowStub::runReadyTasks(',
        'Delayed-callback hooks',
        'Unhandled-exception policy',
        '`Workflow\\V2\\Testing\\ActivityFakeContext`',
    ];

    private const REQUIRED_BUCKETS = [
        'Deterministic replay',
        'Command idempotency',
        'Typed command-result compatibility',
        'Workflow-task-failure taxonomy',
        'Side-effect purity',
        'Structural-limit failures',
        'Workflow-mode guardrails',
        'Duplicate-start policy',
        'Instance-vs-run targeting',
        'Cancellation-scope propagation',
        'Compatibility-set routing',
        'Routing precedence',
        'Webhook routing',
        'Type-key collision detection',
        'Timer ordering and fan-out',
        'Timeout taxonomy',
        'Schedule lifecycle',
        'Schedule degraded-mode',
        'Update lifecycle',
        'Signal-with-start ordering',
        'Query behavior',
        'Child failure / parent close / continue-as-new',
        'Fan-in barriers',
        'Saga compensation',
        'Visibility indexing, filters, and saved views',
        'Search-attribute and memo behavior',
        'Lifecycle event compatibility',
        'Waterline projection and adapter correctness',
        'History budgets',
        'HTTP/JSON worker protocol',
        'Heartbeats and lease expiry',
        'Transport repair',
        'Liveness bootstrap',
        'Message-stream cursors',
        'Transaction and after-commit boundaries',
        'Operator command auditability',
        'Auth/audit boundaries',
        'Namespace service catalog',
        'Cross-namespace service calls',
        'Backend capability validation',
        'Backup/restore/projection-rebuild exercises',
        'Embedded v2 history import',
        'Cross-service compatibility',
        'Model-payload codec',
        'Launch/wait helpers',
        'Scoped execution contexts',
    ];

    private const REQUIRED_SUITE_LAYERS = [
        'Workflow package fixtures and feature tests',
        'Workflow package unit and contract suites',
        'Server feature and unit suites',
        'Waterline V2 dedicated suites',
        'Platform conformance fixtures',
        'Replay-debug bundles',
    ];

    private const REQUIRED_TEST_MODE_DEFAULTS = [
        'Activity dispatch, signal sending, and update sending are recorded',
        'Child workflows under fake mode execute as real nested v2 runs',
        'Time travel advances virtual time deterministically',
        '`WorkflowStub::runReadyTasks()` is the one supported drainage',
        'Unhandled workflow exceptions fail the test fast in test mode',
        'Mocking a workflow class is rejected',
    ];

    private const REQUIRED_BUCKET_STATUSES = [
        'proven',
        'partial',
        'planned',
    ];

    private const REQUIRED_RELATED_CONTRACTS = [
        '../workflow/plan.md',
        'multi-node-hardening-roadmap.md',
        'execution-guarantees.md',
        'operational-liveness.md',
        'platform-conformance-suite.md',
        'sdk-neutrality.md',
        '../api-stability.md',
    ];

    public function testDocumentExistsAndDeclaresRequiredHeadings(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_HEADINGS as $heading) {
            $this->assertStringContainsString(
                $heading,
                $contents,
                sprintf('Testing strategy contract is missing heading %s.', $heading),
            );
        }
    }

    public function testDocumentNamesEverySupportedTestingApi(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_API_SURFACE as $api) {
            $this->assertStringContainsString(
                $api,
                $contents,
                sprintf('Testing strategy contract must name supported testing API %s.', $api),
            );
        }
    }

    public function testDocumentNamesEveryRequiredTestBucket(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_BUCKETS as $bucket) {
            $this->assertStringContainsString(
                $bucket,
                $contents,
                sprintf('Testing strategy contract must name required test bucket %s.', $bucket),
            );
        }
    }

    public function testDocumentDeclaresAllBucketStatusConventions(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_BUCKET_STATUSES as $status) {
            $this->assertStringContainsString(
                sprintf('**%s**', $status),
                $contents,
                sprintf('Testing strategy contract must declare bucket status convention "%s".', $status),
            );
        }
    }

    public function testDocumentNamesEverySuiteLayer(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_SUITE_LAYERS as $layer) {
            $this->assertStringContainsString(
                $layer,
                $contents,
                sprintf('Testing strategy contract must name suite layer %s.', $layer),
            );
        }
    }

    public function testDocumentDeclaresTestModeDefaults(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_TEST_MODE_DEFAULTS as $default) {
            $this->assertStringContainsString(
                $default,
                $contents,
                sprintf('Testing strategy contract must declare test-mode default "%s".', $default),
            );
        }
    }

    public function testDocumentLinksAdjacentContracts(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_RELATED_CONTRACTS as $reference) {
            $this->assertStringContainsString(
                $reference,
                $contents,
                sprintf('Testing strategy contract must link adjacent contract %s.', $reference),
            );
        }
    }

    public function testDocumentReferencesPinTestPath(): void
    {
        $contents = $this->documentContents();

        $this->assertStringContainsString(
            'tests/Unit/V2/TestingStrategyDocumentationTest.php',
            $contents,
            'Testing strategy contract must name its documentation pin test path.',
        );
    }

    public function testDocumentRequiresPinTestUpdatesOnChange(): void
    {
        $contents = $this->documentContents();

        $this->assertStringContainsString(
            'requires updating',
            $contents,
            'Testing strategy contract must spell out the change-control requirement.',
        );
        $this->assertStringContainsString(
            'tests/Unit/V2/TestingStrategyDocumentationTest.php',
            $contents,
            'Testing strategy contract change-control text must name the documentation pin test.',
        );
    }

    public function testDocumentationPinTestIsBackedByThisFile(): void
    {
        // Self-pinning sanity check: this test file lives at the path the
        // contract advertises, so a future rename of either side is caught.
        $expectedPath = dirname(__DIR__, 3) . '/tests/Unit/V2/TestingStrategyDocumentationTest.php';

        $this->assertFileExists(
            $expectedPath,
            'Documentation pin test must live at the path named by the testing-strategy contract.',
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
