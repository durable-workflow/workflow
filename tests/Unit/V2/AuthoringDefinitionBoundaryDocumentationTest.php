<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\TestCase;

/**
 * Pins the v2 authoring and definition-boundary contract documented in
 * docs/architecture/authoring-definition-boundary.md. The doc is the
 * reference for helper-first workflow authoring, the Workflow static facade,
 * the WorkflowStub remote-handle boundary, replay-safety diagnostics,
 * idempotency surfaces, schedules, type registration, and future SDK rules.
 */
final class AuthoringDefinitionBoundaryDocumentationTest extends TestCase
{
    private const DOCUMENT = 'docs/architecture/authoring-definition-boundary.md';

    private const REQUIRED_HEADINGS = [
        '# Workflow V2 Authoring and Definition Boundary Contract',
        '## Scope',
        '## Primary authoring style',
        '## Static facade alternative',
        '## Runtime primitives and remote handles',
        '## Concurrency boundary',
        '## Replay-safety guardrails',
        '## History budget observation',
        '## Activity idempotency surface',
        '## Schedule definition boundary',
        '## SDK and client adapter boundary',
        '## Type registration and future SDKs',
        '## Migration and reference architectures',
        '## Test strategy alignment',
        '## Changing this contract',
    ];

    private const REQUIRED_HELPERS = [
        'activity()',
        'child()',
        'timer()',
        'await()',
        'now()',
        'all()',
        'parallel()',
        'sideEffect()',
        'continueAsNew()',
        'getVersion()',
        'upsertMemo()',
        'upsertSearchAttributes()',
    ];

    private const REQUIRED_STATIC_FACADE_METHODS = [
        'Workflow::activity()',
        'Workflow::child()',
        'Workflow::timer()',
        'Workflow::await()',
        'Workflow::now()',
        'Workflow::all()',
        'Workflow::parallel()',
        'Workflow::sideEffect()',
    ];

    private const REQUIRED_GUARDRAIL_SURFACES = [
        'WorkflowDeterminismDiagnostics',
        'WorkflowModeGuard',
        'WorkflowDefinitionFingerprint',
        'Database',
        'cache',
        'wall-clock',
        'randomness',
    ];

    private const REQUIRED_IDEMPOTENCY_FIELDS = [
        'activity_execution_id',
        'activity_attempt_id',
        'idempotency_key',
    ];

    private const REQUIRED_SCHEDULE_RECORDS = [
        'workflow_schedules',
        'ScheduleCreated',
        'StartAccepted',
        'StartRejected',
        'timer',
    ];

    public function testContractDocumentExistsAndDeclaresFrozenSections(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_HEADINGS as $heading) {
            $this->assertStringContainsString(
                $heading,
                $contents,
                sprintf('Authoring boundary contract is missing heading %s.', $heading),
            );
        }
    }

    public function testContractNamesThePrimaryHelperVocabulary(): void
    {
        $contents = $this->documentContents();

        $this->assertStringContainsString(
            'Workflow\V2\functions.php',
            $contents,
            'Authoring boundary contract must name Workflow\V2\functions.php as the helper source.',
        );

        foreach (self::REQUIRED_HELPERS as $helper) {
            $this->assertStringContainsString(
                $helper,
                $contents,
                sprintf('Authoring boundary contract must name helper %s.', $helper),
            );
        }
    }

    public function testContractPinsWorkflowFacadeAsSameBehaviorAlternative(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_STATIC_FACADE_METHODS as $method) {
            $this->assertStringContainsString(
                $method,
                $contents,
                sprintf('Authoring boundary contract must name facade method %s.', $method),
            );
        }

        $this->assertMatchesRegularExpression(
            '/same semantics as the namespaced helpers/i',
            $contents,
            'Authoring boundary contract must state the facade has the same helper semantics.',
        );
    }

    public function testContractKeepsWorkflowStubAtTheRemoteHandleBoundary(): void
    {
        $contents = $this->documentContents();

        $this->assertStringContainsString('WorkflowStub', $contents);
        $this->assertMatchesRegularExpression(
            '/reserved for remote workflow handles/i',
            $contents,
            'Authoring boundary contract must reserve WorkflowStub for remote handles.',
        );
        $this->assertMatchesRegularExpression(
            '/not the authoring primitive/i',
            $contents,
            'Authoring boundary contract must keep runtime primitives off WorkflowStub.',
        );
    }

    public function testContractPinsConcurrencyToAllAndParallel(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/Concurrency is expressed through `all\(\)` and `parallel\(\)`/',
            $contents,
            'Authoring boundary contract must pin all()/parallel() as the concurrency boundary.',
        );
        $this->assertMatchesRegularExpression(
            '/fire-and-forget handles/i',
            $contents,
            'Authoring boundary contract must reject fire-and-forget handles for normal fan-out.',
        );
    }

    public function testContractNamesReplaySafetyGuardrails(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_GUARDRAIL_SURFACES as $surface) {
            $this->assertStringContainsString(
                $surface,
                $contents,
                sprintf('Authoring boundary contract must name replay-safety surface %s.', $surface),
            );
        }
    }

    public function testContractNamesHistoryBudgetAndActivityIdempotencySurfaces(): void
    {
        $contents = $this->documentContents();

        foreach (['historyLength()', 'historySize()', 'shouldContinueAsNew()'] as $method) {
            $this->assertStringContainsString($method, $contents);
        }

        foreach (self::REQUIRED_IDEMPOTENCY_FIELDS as $field) {
            $this->assertStringContainsString(
                $field,
                $contents,
                sprintf('Authoring boundary contract must name idempotency field %s.', $field),
            );
        }
    }

    public function testContractNamesScheduleAndFutureSdkBoundaries(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_SCHEDULE_RECORDS as $record) {
            $this->assertStringContainsString(
                $record,
                $contents,
                sprintf('Authoring boundary contract must name schedule record %s.', $record),
            );
        }

        foreach (['explicit manifests', 'Python', 'future workers', 'history event catalog'] as $term) {
            $this->assertStringContainsString($term, $contents);
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
