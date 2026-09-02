<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use Workflow\WorkflowOptions;

final class RoutingOptionsContractTest extends TestCase
{
    public function testWorkflowOptionsWireShapeIsLimitedToConnectionAndQueue(): void
    {
        $properties = array_map(
            static fn (ReflectionProperty $property): string => $property->getName(),
            (new ReflectionClass(WorkflowOptions::class))->getProperties(),
        );

        sort($properties);

        $this->assertSame(['connection', 'queue'], $properties);
        $this->assertSame(
            [
                'connection' => 'redis',
                'queue' => 'billing',
            ],
            get_object_vars(new WorkflowOptions(connection: 'redis', queue: 'billing')),
        );
    }
}
