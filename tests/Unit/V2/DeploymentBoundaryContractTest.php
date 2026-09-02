<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class DeploymentBoundaryContractTest extends TestCase
{
    public function testWorkflowDoesNotPublishAHostedTopologyManifest(): void
    {
        $class = 'Workflow\\V2\\Support\\Hosted' . 'ControlPlaneContract';

        $this->assertFalse(class_exists($class));
        $this->assertFileDoesNotExist(dirname(__DIR__, 3) . '/src/V2/Support/Hosted' . 'ControlPlaneContract.php');
    }

    public function testPublicPackageSurfacesDoNotAdvertiseManagedRuntimeTargets(): void
    {
        $root = dirname(__DIR__, 3);
        $forbidden = [
            'attach_runtime_' . 'target',
            'move_namespace_' . 'target',
            'runtime_target_' . 'base_url',
            'hosted_control_plane_' . 'contract',
        ];

        foreach (['src', 'docs'] as $directory) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory));

            foreach ($files as $file) {
                if (! $file->isFile()) {
                    continue;
                }

                $contents = file_get_contents($file->getPathname());
                $this->assertIsString($contents);

                foreach ($forbidden as $token) {
                    $this->assertStringNotContainsString(
                        $token,
                        $contents,
                        sprintf('%s still publishes removed hosted-topology token %s.', $file->getPathname(), $token),
                    );
                }
            }
        }
    }
}
