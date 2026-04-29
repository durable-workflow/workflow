<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Tests\TestCase;
use Workflow\V2\Support\RoleTopologySnapshot;

final class RoleTopologySnapshotTest extends TestCase
{
    public function testItDefaultsToTheEmbeddedApplicationProcessShape(): void
    {
        $snapshot = RoleTopologySnapshot::current();

        $this->assertSame(RoleTopologySnapshot::SCHEMA, $snapshot['schema']);
        $this->assertSame(RoleTopologySnapshot::VERSION, $snapshot['version']);
        $this->assertSame('embedded', $snapshot['current_shape']);
        $this->assertSame('application_process', $snapshot['current_process_class']);
        $this->assertSame(
            ['control_plane', 'matching', 'history_projection', 'scheduler', 'execution_plane'],
            $snapshot['current_roles'],
        );
        $this->assertSame('local_queue_worker', $snapshot['execution_mode']);
        $this->assertTrue($snapshot['role_catalog']['matching']['hosted_by_current_node']);
        $this->assertFalse($snapshot['role_catalog']['api_ingress']['hosted_by_current_node']);
        $this->assertSame(
            ['control_plane'],
            $snapshot['authority_surfaces']['workflow_instances']['mutations']['status_transitions']['owning_roles'],
        );
    }

    public function testItUsesServerTopologyOverridesWhenPresent(): void
    {
        config()->set('server.topology.shape', 'split_control_execution');
        config()
            ->set('server.topology.process_class', 'matching_node');
        config()
            ->set('server.mode', 'service');

        $snapshot = RoleTopologySnapshot::current();

        $this->assertSame('split_control_execution', $snapshot['current_shape']);
        $this->assertSame('matching_node', $snapshot['current_process_class']);
        $this->assertSame(['matching'], $snapshot['current_roles']);
        $this->assertSame('remote_worker_protocol', $snapshot['execution_mode']);
        $this->assertTrue($snapshot['role_catalog']['matching']['hosted_by_current_node']);
        $this->assertFalse($snapshot['role_catalog']['execution_plane']['hosted_by_current_node']);
        $this->assertSame(
            ['execution_plane'],
            $snapshot['supported_topologies']['split_control_execution']['process_classes']['execution_node']['roles'],
        );
    }
}
