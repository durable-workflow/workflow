<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\TestCase;

/**
 * Pins the v2 visibility-metadata contract documented in
 * docs/search-attributes-architecture.md and docs/workflow-memos-architecture.md.
 *
 * The contract names typed tables (workflow_search_attributes,
 * workflow_memos) as the only authoritative storage for v2 search
 * attributes and memos.
 *
 * v2 has never been released, so different v2 development snapshots
 * are not a "mixed fleet" and no v2-alpha-to-v2 backwards-compatibility
 * contract is owed. These tests fail closed if either document drifts
 * back into multi-phase migration framing or reintroduces alpha/beta
 * backwards-compatibility language.
 */
final class VisibilityMetadataContractDocumentationTest extends TestCase
{
    private const SEARCH_ATTRIBUTES_DOCUMENT = 'docs/search-attributes-architecture.md';

    private const MEMOS_DOCUMENT = 'docs/workflow-memos-architecture.md';

    public function testSearchAttributesDocNamesTypedTableAsAuthoritativeStorage(): void
    {
        $contents = $this->documentContents(self::SEARCH_ATTRIBUTES_DOCUMENT);

        $this->assertMatchesRegularExpression(
            '/`workflow_search_attributes`[^.]*authoritative storage/i',
            $contents,
            'Search-attributes architecture must name workflow_search_attributes as the authoritative v2 storage so projections, list filters, and Waterline visibility queries bind to one contract.',
        );
    }

    public function testMemosDocNamesTypedTableAsAuthoritativeStorage(): void
    {
        $contents = $this->documentContents(self::MEMOS_DOCUMENT);

        $this->assertMatchesRegularExpression(
            '/`workflow_memos`[^.]*authoritative storage/i',
            $contents,
            'Memos architecture must name workflow_memos as the authoritative v2 storage so detail/describe views bind to one contract.',
        );
    }

    public function testSearchAttributesDocDoesNotMentionLegacyWorkflowRunsColumn(): void
    {
        $contents = $this->documentContents(self::SEARCH_ATTRIBUTES_DOCUMENT);

        $this->assertStringNotContainsString(
            'workflow_runs.search_attributes',
            $contents,
            'Search-attributes architecture must describe the final typed-table contract without retaining the removed workflow_runs.search_attributes compatibility surface.',
        );
    }

    public function testMemosDocDoesNotMentionLegacyWorkflowRunsColumn(): void
    {
        $contents = $this->documentContents(self::MEMOS_DOCUMENT);

        $this->assertStringNotContainsString(
            'workflow_runs.memo',
            $contents,
            'Memos architecture must describe the final typed-table contract without retaining the removed workflow_runs.memo compatibility surface.',
        );
    }

    public function testSearchAttributesDocRulesOutAlphaToV2BackwardsCompatibility(): void
    {
        $contents = $this->documentContents(self::SEARCH_ATTRIBUTES_DOCUMENT);

        $this->assertMatchesRegularExpression(
            '/no v2-alpha to v2 backwards-compatibility contract/i',
            $contents,
            'Search-attributes architecture must state there is no v2-alpha-to-v2 backwards-compatibility contract so the clean-slate v2 release framing is explicit.',
        );
    }

    public function testMemosDocRulesOutAlphaToV2BackwardsCompatibility(): void
    {
        $contents = $this->documentContents(self::MEMOS_DOCUMENT);

        $this->assertMatchesRegularExpression(
            '/no v2-alpha to v2 backwards-compatibility contract/i',
            $contents,
            'Memos architecture must state there is no v2-alpha-to-v2 backwards-compatibility contract so the clean-slate v2 release framing is explicit.',
        );
    }

    public function testSearchAttributesDocDoesNotDescribePendingCleanupSurfaces(): void
    {
        $contents = $this->documentContents(self::SEARCH_ATTRIBUTES_DOCUMENT);

        $this->assertStringNotContainsString(
            'Required cleanup before v2.0 stable',
            $contents,
            'Search-attributes architecture must describe the current typed-table contract, not pending cleanup work.',
        );
        $this->assertStringNotContainsString(
            'create_workflow_runs_table',
            $contents,
            'Search-attributes architecture must not retain cleanup notes for the removed workflow_runs compatibility column.',
        );
    }

    public function testMemosDocDoesNotDescribePendingCleanupSurfaces(): void
    {
        $contents = $this->documentContents(self::MEMOS_DOCUMENT);

        $this->assertStringNotContainsString(
            'Required cleanup before v2.0 stable',
            $contents,
            'Memos architecture must describe the current typed-table contract, not pending cleanup work.',
        );
        $this->assertStringNotContainsString(
            'create_workflow_runs_table',
            $contents,
            'Memos architecture must not retain cleanup notes for the removed workflow_runs compatibility column.',
        );
    }

    public function testSearchAttributesDocRetiresMultiPhaseMigrationLadder(): void
    {
        $contents = $this->documentContents(self::SEARCH_ATTRIBUTES_DOCUMENT);

        $this->assertStringNotContainsString(
            'Phase 0**: Deploy table and model',
            $contents,
            'Search-attributes architecture must not reintroduce the multi-phase migration ladder; the v2 contract is one storage surface, not a multi-phase rollout.',
        );
        $this->assertStringNotContainsString(
            'For v2 alpha/beta releases:',
            $contents,
            'Search-attributes architecture must not reintroduce the v2 alpha/beta backwards-compatibility section; v2 is one product, not a sequence of supported alpha snapshots.',
        );
    }

    public function testMemosDocRetiresMultiPhaseMigrationLadder(): void
    {
        $contents = $this->documentContents(self::MEMOS_DOCUMENT);

        $this->assertStringNotContainsString(
            'Phase 0**: Deploy table and model',
            $contents,
            'Memos architecture must not reintroduce the multi-phase migration ladder; the v2 contract is one storage surface, not a multi-phase rollout.',
        );
        $this->assertStringNotContainsString(
            'For v2 alpha/beta releases:',
            $contents,
            'Memos architecture must not reintroduce the v2 alpha/beta backwards-compatibility section; v2 is one product, not a sequence of supported alpha snapshots.',
        );
    }

    public function testSearchAttributesDocFailureModeDeniesJsonSafetyNet(): void
    {
        $contents = $this->normalizedDocumentContents(self::SEARCH_ATTRIBUTES_DOCUMENT);

        $this->assertStringContainsString(
            'no silent fallback path for typed-storage failure',
            $contents,
            'Search-attributes architecture must state that typed-storage failures have no silent fallback path.',
        );
    }

    public function testMemosDocFailureModeDeniesJsonSafetyNet(): void
    {
        $contents = $this->normalizedDocumentContents(self::MEMOS_DOCUMENT);

        $this->assertStringContainsString(
            'no silent fallback path for typed-storage failure',
            $contents,
            'Memos architecture must state that typed-storage failures have no silent fallback path.',
        );
    }

    public function testWorkflowExecutorHasNoSilentJsonSafetyNetCatch(): void
    {
        $contents = $this->sourceContents('src/V2/Support/WorkflowExecutor.php');

        $this->assertStringNotContainsString(
            "Log::warning('Failed to write typed search attributes'",
            $contents,
            'WorkflowExecutor must not silently swallow typed-storage failures and continue with the JSON column for search attributes; a typed-storage failure must surface as a workflow task failure.',
        );
        $this->assertStringNotContainsString(
            "Log::warning('Failed to write typed memos'",
            $contents,
            'WorkflowExecutor must not silently swallow typed-storage failures and continue with the JSON column for memos; a typed-storage failure must surface as a workflow task failure.',
        );
        $this->assertStringNotContainsString(
            'JSON blob is still authoritative during transition',
            $contents,
            'WorkflowExecutor must not describe the JSON column as authoritative during transition; the typed tables are authoritative and the JSON column is a transitional mirror only.',
        );
    }

    private function documentContents(string $relativePath): string
    {
        $path = dirname(__DIR__, 3) . '/' . $relativePath;
        $contents = file_get_contents($path);

        $this->assertIsString($contents, sprintf('Could not read %s.', $relativePath));

        return $contents;
    }

    private function normalizedDocumentContents(string $relativePath): string
    {
        return (string) preg_replace('/\s+/', ' ', $this->documentContents($relativePath));
    }

    private function sourceContents(string $relativePath): string
    {
        $path = dirname(__DIR__, 3) . '/' . $relativePath;
        $contents = file_get_contents($path);

        $this->assertIsString($contents, sprintf('Could not read %s.', $relativePath));

        return $contents;
    }
}
