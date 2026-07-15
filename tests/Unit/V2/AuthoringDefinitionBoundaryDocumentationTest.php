<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\TestCase;
use Workflow\V2\Workflow;
use Workflow\V2\WorkflowStub;

/**
 * Keeps the architecture document discoverable while pinning the authoring
 * boundary to executable helper and facade APIs. The Markdown body is
 * intentionally not parsed so editorial changes remain independent of tests.
 */
final class AuthoringDefinitionBoundaryDocumentationTest extends TestCase
{
    private const DOCUMENT = 'docs/architecture/authoring-definition-boundary.md';

    private const REQUIRED_HELPERS = [
        'activity',
        'child',
        'timer',
        'await',
        'now',
        'all',
        'parallel',
        'sideEffect',
        'continueAsNew',
        'getVersion',
        'upsertMemo',
        'upsertSearchAttributes',
    ];

    private const REQUIRED_STATIC_FACADE_METHODS = [
        'activity',
        'child',
        'timer',
        'await',
        'now',
        'all',
        'parallel',
        'sideEffect',
    ];

    public function testContractDocumentExists(): void
    {
        $this->assertFileExists(dirname(__DIR__, 3) . '/' . self::DOCUMENT);
    }

    public function testHelperAndFacadeAuthoringApisExist(): void
    {
        foreach (self::REQUIRED_HELPERS as $helper) {
            $this->assertTrue(function_exists('Workflow\\V2\\' . $helper));
        }

        foreach (self::REQUIRED_STATIC_FACADE_METHODS as $method) {
            $this->assertTrue(method_exists(Workflow::class, $method));
        }
    }

    public function testWorkflowStubRemainsTheRemoteHandleBoundary(): void
    {
        $this->assertTrue(method_exists(WorkflowStub::class, 'load'));
        $this->assertFalse(method_exists(WorkflowStub::class, 'activity'));
    }
}
