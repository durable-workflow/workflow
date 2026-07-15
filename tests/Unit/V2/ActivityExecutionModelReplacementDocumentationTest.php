<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\TestCase;
use Workflow\V2\Support\LocalActivityExecutor;
use Workflow\V2\Support\StickyExecution;
use Workflow\V2\Support\WorkerSession;

/**
 * Keeps the architecture document discoverable while pinning the replacement
 * primitives to executable package APIs. The Markdown body is intentionally
 * not parsed so editorial changes do not require synchronized test changes.
 */
final class ActivityExecutionModelReplacementDocumentationTest extends TestCase
{
    private const DOCUMENT = 'docs/architecture/activity-execution-model-replacement.md';

    public function testContractDocumentExists(): void
    {
        $this->assertFileExists(dirname(__DIR__, 3) . '/' . self::DOCUMENT);
    }

    public function testReplacementPrimitivesHaveRuntimeAuthorities(): void
    {
        $this->assertTrue(method_exists(LocalActivityExecutor::class, 'execute'));
        $this->assertTrue(method_exists(WorkerSession::class, 'activity'));
        $this->assertTrue(method_exists(StickyExecution::class, 'describe'));
    }
}
