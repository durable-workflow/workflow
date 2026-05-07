<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\TestCase;

/**
 * Pins the parent roadmap for workflow v2 multi-node architecture hardening.
 * The phase-specific architecture documents own their behavior; this test keeps
 * the phase order, contract mapping, and dependency chain explicit.
 */
final class MultiNodeHardeningRoadmapDocumentationTest extends TestCase
{
    private const DOCUMENT = 'docs/architecture/multi-node-hardening-roadmap.md';

    private const REQUIRED_HEADINGS = [
        '# Workflow V2 Multi-Node Architecture Hardening Roadmap',
        '## Background',
        '## Ordered Phases',
        '## Dependency Rules',
        '## Adjacent Work',
        '## Cross-Repo Coordination',
        '## Non-Goals',
        '## Close Condition',
    ];

    private const PHASES = [
        'Phase 1: execution guarantees and idempotency contract' => 'docs/architecture/execution-guarantees.md',
        'Phase 2: mixed-version compatibility and worker routing' => 'docs/architecture/worker-compatibility.md',
        'Phase 3: dedicated task matching and dispatch' => 'docs/architecture/task-matching.md',
        'Phase 4: control-plane and execution-plane role split' => 'docs/architecture/control-plane-split.md',
        'Phase 5: remove shared cache from scheduler correctness' => 'docs/architecture/scheduler-correctness.md',
        'Phase 6: rollout safety enforcement and coordination health' => 'docs/architecture/rollout-safety.md',
    ];

    private const ADJACENT_CONTRACTS = [
        'operational liveness and transport repair',
        'testing strategy',
        'documentation plan',
        'deployment modes',
        'routing precedence and inheritance',
        'operating envelope and hosting guidance',
        'hosted control-plane and data-plane split',
    ];

    public function testRoadmapDocumentExistsAndDeclaresRequiredSections(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_HEADINGS as $heading) {
            $this->assertStringContainsString(
                $heading,
                $contents,
                sprintf('Multi-node hardening roadmap is missing heading %s.', $heading),
            );
        }
    }

    public function testRoadmapKeepsPhasesInDependencyOrder(): void
    {
        $contents = $this->documentContents();
        $previousPosition = -1;

        foreach (self::PHASES as $phaseTitle => $contractPath) {
            $position = strpos($contents, $phaseTitle);

            $this->assertIsInt($position, sprintf('Roadmap must name phase %s.', $phaseTitle));
            $this->assertGreaterThan(
                $previousPosition,
                $position,
                sprintf('Roadmap must list %s after the previous phase.', $phaseTitle),
            );
            $this->assertStringContainsString(
                $contractPath,
                $contents,
                sprintf('Roadmap must map %s to %s.', $phaseTitle, $contractPath),
            );

            $previousPosition = $position;
        }
    }

    public function testRoadmapNamesEveryAdjacentContractWithoutDuplicatingOwnership(): void
    {
        $contents = $this->documentContents();

        foreach (self::ADJACENT_CONTRACTS as $contract) {
            $this->assertStringContainsString(
                $contract,
                $contents,
                sprintf('Roadmap must name adjacent contract %s.', $contract),
            );
        }

        $this->assertStringContainsString(
            'The phase-linked adjacent contracts are inputs, not duplicates:',
            $contents,
            'Roadmap must state that adjacent contracts are consumed rather than duplicated.',
        );
    }

    public function testRoadmapStatesDependencyChainExplicitly(): void
    {
        $contents = $this->documentContents();

        foreach ([
            'Phase 1 freezes the semantic vocabulary.',
            'Phase 2 consumes Phase 1',
            'Phase 3 consumes Phases 1 and 2',
            'Phase 4 consumes Phases 1 through 3',
            'Phase 5 consumes Phases 1 through 4',
            'Phase 6 consumes every earlier phase',
        ] as $needle) {
            $this->assertStringContainsString($needle, $contents);
        }
    }

    public function testRoadmapKeepsNonGoalsExplicit(): void
    {
        $contents = $this->documentContents();

        foreach ([
            'Adopting Temporal wholesale.',
            'Rebuilding the entire system in one step.',
            'Eliminating SQL persistence.',
            'Treating shared cache plus identical app nodes as the final architecture.',
        ] as $nonGoal) {
            $this->assertStringContainsString($nonGoal, $contents);
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
