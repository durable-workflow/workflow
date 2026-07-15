<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\TestCase;
use Workflow\V2\Support\HostedControlPlaneContract;

final class ManagedCloudReadinessDocumentationTest extends TestCase
{
    public function testManagedCloudAuthorityIsMachineReadable(): void
    {
        $manifest = HostedControlPlaneContract::manifest();
        $encoded = json_encode($manifest, JSON_THROW_ON_ERROR);

        $this->assertSame($manifest, json_decode($encoded, true, 512, JSON_THROW_ON_ERROR));
        $this->assertSame(HostedControlPlaneContract::SCHEMA, $manifest['schema']);
        $this->assertSame(HostedControlPlaneContract::VERSION, $manifest['version']);
    }

    public function testManagedCloudDocumentationRoutesExist(): void
    {
        $root = dirname(__DIR__, 3);

        foreach ([
            'docs/workflow/plan.md',
            'docs/architecture/hosted-control-plane.md',
            'docs/deployment/ha-failover.md',
            'docs/deployment/multi-region.md',
        ] as $document) {
            $this->assertFileExists($root . '/' . $document);
        }
    }
}
