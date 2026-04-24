<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\TestCase;
use Workflow\V2\Support\WorkflowDeterminismDiagnostics;

/**
 * Pins the v2 mutable-side-effect policy contract documented in
 * docs/architecture/mutable-side-effect-policy.md. The doc is the
 * single reference used by product docs, CLI reasoning, Waterline
 * diagnostics, SDK documentation, and test coverage for the
 * replay-safe conversion primitives, the seven rule codes emitted by
 * WorkflowDeterminismDiagnostics, the three status and three source
 * constants carried on findings, the boot-time WorkflowModeGuard
 * modes, and the explicit non-goals list. Changes to any named
 * guarantee must update this test and the documented contract in the
 * same change so drift is reviewed deliberately.
 */
final class MutableSideEffectPolicyDocumentationTest extends TestCase
{
    private const DOCUMENT = 'docs/architecture/mutable-side-effect-policy.md';

    private const REQUIRED_HEADINGS = [
        '# Workflow V2 Mutable-Side-Effect Policy Contract',
        '## Scope',
        '## Terminology',
        '## The policy',
        '## Replay-safe conversion primitives',
        '### `sideEffect(callable)`',
        '### `uuid4()` / `uuid7()`',
        '### `now()`',
        '### Durable timers',
        '### Activities',
        '### Signals, updates, and workflow input',
        '## Forbidden patterns and diagnostic rules',
        '## Detection and diagnostics',
        '### Static analysis',
        '### Boot-time guardrail',
        '### Operator surfacing',
        '## Non-goals',
        '## Consumers bound by this contract',
        '## History-event surface',
        '## Test strategy alignment',
        '## Changing this contract',
    ];

    private const REQUIRED_TERMS = [
        'Authoring code',
        'Mutable side effect',
        'Replay-safe',
        'Durable primitive',
        'Determinism diagnostic',
    ];

    private const REQUIRED_DIAGNOSTIC_RULES = [
        'workflow_wall_clock_call',
        'workflow_random_call',
        'workflow_ambient_context_call',
        'workflow_database_facade_call',
        'workflow_cache_facade_call',
        'workflow_auth_facade_call',
        'workflow_http_facade_call',
    ];

    private const REQUIRED_DIAGNOSTIC_STATUS_CONSTANTS = ['STATUS_CLEAN', 'STATUS_WARNING', 'STATUS_UNAVAILABLE'];

    private const REQUIRED_DIAGNOSTIC_STATUS_VALUES = ["'clean'", "'warning'", "'unavailable'"];

    private const REQUIRED_DIAGNOSTIC_SOURCE_CONSTANTS = [
        'SOURCE_DEFINITION_DRIFT',
        'SOURCE_LIVE_DEFINITION',
        'SOURCE_UNAVAILABLE',
    ];

    private const REQUIRED_DIAGNOSTIC_SOURCE_VALUES = [
        "'definition_drift'",
        "'live_definition'",
        "'unavailable'",
    ];

    private const REQUIRED_WALL_CLOCK_FUNCTIONS = [
        '`date`',
        '`gmdate`',
        '`hrtime`',
        '`microtime`',
        '`now`',
        '`time`',
    ];

    private const REQUIRED_RANDOM_FUNCTIONS = [
        '`random_bytes`',
        '`random_int`',
        '`rand`',
        '`mt_rand`',
        '`uniqid`',
    ];

    private const REQUIRED_GUARDRAIL_MODES = ["`'warn'`", "`'silent'`", "`'throw'`"];

    private const REQUIRED_REPLAY_SAFE_PRIMITIVES = ['sideEffect(', 'uuid4()', 'uuid7()', 'now()', 'timer('];

    private const REQUIRED_HISTORY_EVENTS = [
        'SideEffectRecorded',
        'VersionMarkerRecorded',
        'TimerScheduled',
        'TimerFired',
        'TimerCancelled',
        'ActivityScheduled',
        'ActivityStarted',
        'ActivityCompleted',
        'ActivityFailed',
        'ActivityCancelled',
        'ActivityRetryScheduled',
        'ChildWorkflowScheduled',
        'ChildRunStarted',
        'ChildRunCompleted',
        'ChildRunFailed',
        'ChildRunCancelled',
        'ChildRunTerminated',
        'SearchAttributesUpserted',
    ];

    private const REQUIRED_CONSUMER_CLASSES = [
        'WorkflowExecutor',
        'QueryStateReplayer',
        'WorkflowDeterminismDiagnostics',
        'WorkflowModeGuard',
        'RunDetailView',
        'DeterministicUuid',
        'WorkflowFiberContext',
    ];

    private const REQUIRED_NON_GOALS = [
        'Ambient mutable mode',
        'Implicit capture',
        'Opaque sandboxing',
        'maybe-deterministic',
        'Retrofitted determinism',
    ];

    private const REQUIRED_CONFIG_KEY = 'workflows.v2.guardrails.boot';

    public function testContractDocumentExistsAndDeclaresFrozenSections(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_HEADINGS as $heading) {
            $this->assertStringContainsString(
                $heading,
                $contents,
                sprintf('Mutable-side-effect policy contract is missing heading %s.', $heading),
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

    public function testContractDocumentNamesEveryDiagnosticRuleCode(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_DIAGNOSTIC_RULES as $rule) {
            $this->assertStringContainsString(
                $rule,
                $contents,
                sprintf('Contract must name the %s rule code emitted by WorkflowDeterminismDiagnostics.', $rule),
            );
        }
    }

    public function testContractDocumentNamesDiagnosticStatusConstants(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_DIAGNOSTIC_STATUS_CONSTANTS as $constant) {
            $this->assertStringContainsString(
                $constant,
                $contents,
                sprintf('Contract must name the %s constant.', $constant),
            );
        }
        foreach (self::REQUIRED_DIAGNOSTIC_STATUS_VALUES as $value) {
            $this->assertStringContainsString(
                $value,
                $contents,
                sprintf('Contract must quote the %s status string value.', $value),
            );
        }
    }

    public function testContractDocumentNamesDiagnosticSourceConstants(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_DIAGNOSTIC_SOURCE_CONSTANTS as $constant) {
            $this->assertStringContainsString(
                $constant,
                $contents,
                sprintf('Contract must name the %s source constant.', $constant),
            );
        }
        foreach (self::REQUIRED_DIAGNOSTIC_SOURCE_VALUES as $value) {
            $this->assertStringContainsString(
                $value,
                $contents,
                sprintf('Contract must quote the %s source string value.', $value),
            );
        }
    }

    public function testContractDocumentNamesWallClockFunctions(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_WALL_CLOCK_FUNCTIONS as $fn) {
            $this->assertStringContainsString(
                $fn,
                $contents,
                sprintf('Contract must list the %s wall-clock function as forbidden.', $fn),
            );
        }
    }

    public function testContractDocumentNamesRandomFunctions(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_RANDOM_FUNCTIONS as $fn) {
            $this->assertStringContainsString(
                $fn,
                $contents,
                sprintf('Contract must list the %s random function as forbidden.', $fn),
            );
        }
    }

    public function testContractDocumentNamesForbiddenFacades(): void
    {
        $contents = $this->documentContents();

        foreach (['DB', 'Database', 'Cache', 'Auth', 'Http'] as $facade) {
            $this->assertStringContainsString(
                $facade,
                $contents,
                sprintf('Contract must name the %s facade as forbidden in authoring code.', $facade),
            );
        }
    }

    public function testContractDocumentNamesEveryReplaySafePrimitive(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_REPLAY_SAFE_PRIMITIVES as $primitive) {
            $this->assertStringContainsString(
                $primitive,
                $contents,
                sprintf('Contract must name the %s replay-safe primitive.', $primitive),
            );
        }
    }

    public function testContractDocumentNamesEveryGuardrailMode(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_GUARDRAIL_MODES as $mode) {
            $this->assertStringContainsString(
                $mode,
                $contents,
                sprintf('Contract must describe the %s guardrail mode.', $mode),
            );
        }
    }

    public function testContractDocumentNamesGuardrailConfigKey(): void
    {
        $contents = $this->documentContents();

        $this->assertStringContainsString(
            self::REQUIRED_CONFIG_KEY,
            $contents,
            'Contract must name the workflows.v2.guardrails.boot config key that drives the boot-time guardrail.',
        );
    }

    public function testContractDocumentNamesHistoryEventSurface(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_HISTORY_EVENTS as $event) {
            $this->assertStringContainsString(
                $event,
                $contents,
                sprintf('Contract must name the %s history event in the authoring-caused event surface.', $event),
            );
        }
    }

    public function testContractDocumentNamesEveryBoundConsumer(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_CONSUMER_CLASSES as $class) {
            $this->assertStringContainsString(
                $class,
                $contents,
                sprintf('Contract must name %s as a bound consumer of this policy.', $class),
            );
        }
    }

    public function testContractDocumentEnumeratesSixPolicyRules(): void
    {
        $contents = $this->documentContents();

        foreach (['1. ', '2. ', '3. ', '4. ', '5. ', '6. '] as $prefix) {
            $this->assertStringContainsString(
                $prefix,
                $contents,
                sprintf('Contract must enumerate policy rule %s.', trim($prefix)),
            );
        }
        $this->assertMatchesRegularExpression(
            '/MUST NOT read wall-clock time directly/i',
            $contents,
            'Policy rule 1 must forbid wall-clock reads.',
        );
        $this->assertMatchesRegularExpression(
            '/MUST NOT read randomness directly/i',
            $contents,
            'Policy rule 2 must forbid random reads.',
        );
        $this->assertMatchesRegularExpression(
            '/MUST NOT read ambient request or\s*\n?\s*authentication context/i',
            $contents,
            'Policy rule 3 must forbid ambient context reads.',
        );
        $this->assertMatchesRegularExpression(
            '/MUST NOT perform filesystem IO/i',
            $contents,
            'Policy rule 5 must forbid filesystem IO.',
        );
        $this->assertMatchesRegularExpression(
            '/MUST NOT mutate external state/i',
            $contents,
            'Policy rule 6 must forbid external state mutation.',
        );
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
            'tests/Unit/V2/MutableSideEffectPolicyDocumentationTest.php',
            $contents,
            'Contract must cite its own pinning test path.',
        );
    }

    public function testDiagnosticConstantsMatchDocumentedValues(): void
    {
        $contents = $this->documentContents();

        $this->assertSame('clean', WorkflowDeterminismDiagnostics::STATUS_CLEAN);
        $this->assertSame('warning', WorkflowDeterminismDiagnostics::STATUS_WARNING);
        $this->assertSame('unavailable', WorkflowDeterminismDiagnostics::STATUS_UNAVAILABLE);
        $this->assertSame('definition_drift', WorkflowDeterminismDiagnostics::SOURCE_DEFINITION_DRIFT);
        $this->assertSame('live_definition', WorkflowDeterminismDiagnostics::SOURCE_LIVE_DEFINITION);
        $this->assertSame('unavailable', WorkflowDeterminismDiagnostics::SOURCE_UNAVAILABLE);

        foreach (
            [
                WorkflowDeterminismDiagnostics::STATUS_CLEAN,
                WorkflowDeterminismDiagnostics::STATUS_WARNING,
                WorkflowDeterminismDiagnostics::STATUS_UNAVAILABLE,
                WorkflowDeterminismDiagnostics::SOURCE_DEFINITION_DRIFT,
                WorkflowDeterminismDiagnostics::SOURCE_LIVE_DEFINITION,
            ] as $value
        ) {
            $this->assertStringContainsString(
                $value,
                $contents,
                sprintf(
                    'Documented status/source string %s must match the WorkflowDeterminismDiagnostics runtime constant verbatim.',
                    $value,
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
