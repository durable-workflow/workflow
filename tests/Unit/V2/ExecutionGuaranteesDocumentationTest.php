<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\TestCase;
use Workflow\V2\Enums\CommandOutcome;
use Workflow\V2\Enums\DuplicateStartPolicy;

/**
 * Pins the v2 execution-guarantees contract documented in
 * docs/architecture/execution-guarantees.md. The doc is the single
 * reference used by product docs, CLI reasoning, Waterline diagnostics,
 * and test coverage for duplicate execution, retries, lease expiry, and
 * redelivery semantics. Changes to any named guarantee must update this
 * test and the documented contract in the same change so drift is
 * reviewed deliberately.
 */
final class ExecutionGuaranteesDocumentationTest extends TestCase
{
    private const DOCUMENT = 'docs/architecture/execution-guarantees.md';

    private const REQUIRED_HEADINGS = [
        '# Workflow V2 Execution Guarantees and Idempotency Contract',
        '## Scope',
        '## Terminology',
        '## Workflow task execution semantics',
        '## Activity attempt execution semantics',
        '## Retry semantics',
        '### Activity attempt retry',
        '### Workflow-task retry and repair',
        '### Child workflow retry',
        '## Lease expiry and redelivery',
        '## External commands and duplicate-start policy',
        '## Durable message stream semantics',
        '## Side effects and version markers',
        '## Schedule triggers',
        '## Framework-provided idempotency surfaces',
        '## What developers must make idempotent',
        '## Operator and diagnostic guidance',
        '## Test strategy alignment',
        '## Changing this contract',
    ];

    private const REQUIRED_TERMS = [
        'At-least-once',
        'At-most-once',
        'Deterministic replay',
        'Exactly-once at the durable state layer',
        'Redelivery',
        'Replay',
    ];

    private const REQUIRED_IDEMPOTENCY_SURFACES = [
        'workflow_instance_id',
        'workflow_run_id',
        'activity_execution_id',
        'activity_attempt_id',
        'workflow_command_id',
        'stream_key',
        'idempotencyKey',
        'SideEffectRecorded',
        'VersionMarkerRecorded',
        'schedule_id',
    ];

    public function testContractDocumentExistsAndDeclaresFrozenSections(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_HEADINGS as $heading) {
            $this->assertStringContainsString(
                $heading,
                $contents,
                sprintf('Execution guarantees contract is missing heading %s.', $heading),
            );
        }
    }

    public function testContractDocumentDefinesEveryNamedSemanticsTerm(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_TERMS as $term) {
            $this->assertStringContainsString(
                sprintf('**%s**', $term),
                $contents,
                sprintf('Execution guarantees contract must define term %s in the Terminology section.', $term),
            );
        }
    }

    public function testContractDocumentNamesEveryFrameworkIdempotencySurface(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_IDEMPOTENCY_SURFACES as $surface) {
            $this->assertStringContainsString(
                $surface,
                $contents,
                sprintf('Execution guarantees contract must name the %s idempotency surface.', $surface),
            );
        }
    }

    public function testContractDocumentMatchesDuplicateStartPolicyCases(): void
    {
        $contents = $this->documentContents();

        foreach (DuplicateStartPolicy::cases() as $case) {
            $this->assertStringContainsString(
                $case->value,
                $contents,
                sprintf('Execution guarantees contract must name DuplicateStartPolicy::%s.', $case->name),
            );
        }

        $this->assertStringContainsString(
            'CommandOutcome::' . CommandOutcome::RejectedDuplicate->name,
            $contents,
            'Execution guarantees contract must reference CommandOutcome::RejectedDuplicate.',
        );
    }

    public function testContractDocumentStatesDuplicateExecutionIsNotABugCondition(): void
    {
        $contents = $this->documentContents();

        $this->assertMatchesRegularExpression(
            '/Duplicate execution[^.]*not a bug condition/i',
            $contents,
            'Execution guarantees contract must state duplicate execution is not a bug condition.',
        );
    }

    public function testContractDocumentDescribesActivityOutcomeExactlyOnceRule(): void
    {
        $contents = $this->documentContents();

        $this->assertStringContainsString(
            'ActivityOutcomeRecorder',
            $contents,
            'Execution guarantees contract must cite ActivityOutcomeRecorder as the exactly-once recorder.',
        );
        $this->assertStringContainsString(
            'recorded=false',
            $contents,
            'Execution guarantees contract must explain the recorded=false redelivery signal.',
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
