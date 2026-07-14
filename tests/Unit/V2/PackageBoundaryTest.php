<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\TestCase;

final class PackageBoundaryTest extends TestCase
{
    public function testStandaloneClientAndRemoteWorkerNamespacesAreNotShipped(): void
    {
        $root = dirname(__DIR__, 3);

        $this->assertDirectoryDoesNotExist($root . '/src/V2/Client');
        $this->assertDirectoryDoesNotExist($root . '/src/V2/Worker');

        foreach (
            [
                'Workflow\\V2\\Client\\ControlPlaneClient',
                'Workflow\\V2\\Client\\WorkflowClient',
                'Workflow\\V2\\Worker\\WorkerProtocolClient',
                'Workflow\\V2\\Worker\\StandaloneWorkflowWorker',
                'Workflow\\V2\\Worker\\WorkflowQueryTaskExecutor',
            ] as $removedClass
        ) {
            $this->assertFalse(class_exists($removedClass), $removedClass . ' must be provided by durable-workflow/sdk instead.');
        }
    }

    public function testRemoteServerConformanceCommandsAreNotRegistered(): void
    {
        $provider = file_get_contents(dirname(__DIR__, 3) . '/src/Providers/WorkflowServiceProvider.php');

        $this->assertIsString($provider);

        foreach (
            [
                'V2NamespaceConformanceCommand',
                'V2ScheduleConformanceCommand',
                'V2SearchAttributesConformanceCommand',
                'V2WorkflowUpdatesConformanceCommand',
            ] as $removedCommand
        ) {
            $this->assertStringNotContainsString($removedCommand, $provider);
        }

        $this->assertStringContainsString('V2ReplayConformanceCommand', $provider);
    }

    public function testComposerMetadataIdentifiesTheEmbeddedRuntimeAndOfficialAvroPackage(): void
    {
        $contents = file_get_contents(dirname(__DIR__, 3) . '/composer.json');
        $this->assertIsString($contents);

        $composer = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(
            'Embedded durable workflow runtime and orchestration engine for Laravel applications.',
            $composer['description'] ?? null,
        );
        $this->assertSame('^1.12', $composer['require']['apache/avro'] ?? null);
        $this->assertArrayNotHasKey('durable-workflow/sdk', $composer['require'] ?? []);
        $this->assertArrayHasKey('durable-workflow/sdk', $composer['suggest'] ?? []);
    }
}
