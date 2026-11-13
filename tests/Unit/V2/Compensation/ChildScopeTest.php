<?php

declare(strict_types=1);

namespace Tests\Unit\V2\Compensation;

use Illuminate\Database\Events\QueryExecuted;
use LogicException;
use Tests\TestCase;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\Persistence\ChildScopes;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\Support\ConfiguredV2Models;

final class ChildScopeTest extends TestCase
{
    public function testChildParticipantIsExactlyAdoptedAndBoundToItsGeneration(): void
    {
        $parent = $this->scope('order');
        $child = $this->scope('shipment');
        $children = new ChildScopes();
        $id = $children->register($parent, 'origin', 'delivery', $child);
        $this->assertSame($id, $children->register($parent, 'origin', 'delivery', $child));
        $this->expectException(LogicException::class);
        $children->register($parent, 'origin', 'delivery', new ScopeIdentity('shop', 'client-a', 'shipment', '2'));
    }

    public function testExistingGraphIsLockedBeforeAnyDuplicateInsert(): void
    {
        $parent = $this->scope('order');
        $child = $this->scope('shipment');
        $children = new ChildScopes();
        $children->register($parent, 'origin', 'delivery', $child);
        $queries = [];
        ConfiguredV2Models::connection()->listen(static function (QueryExecuted $query) use (&$queries): void {
            if (str_contains($query->sql, 'workflow_compensation_graphs')) {
                $queries[] = strtolower($query->sql);
            }
        });
        $children->register($parent, 'origin', 'delivery', $child);
        $this->assertStringStartsWith('select', $queries[0]);
        $this->assertSame([], array_filter($queries, static fn (string $sql): bool => str_starts_with($sql, 'insert')));
    }

    public function testCycleAndSecondNestingLevelAreRefused(): void
    {
        $children = new ChildScopes();
        $children->register($this->scope('order'), 'order-run', 'delivery', $this->scope('shipment'));
        foreach ([[$this->scope('shipment'), $this->scope('order')],
            [$this->scope('shipment'), $this->scope('return')]] as [$parent, $child]) {
            try {
                $children->register($parent, 'shipment-run', 'child', $child);
                $this->fail('Unsupported or cyclic scope nesting was admitted.');
            } catch (LogicException) {
                $this->assertSame(1, count((new ScopeRepository())->inspect($this->scope('order'))['obligations']));
            }
        }
    }

    public function testForeignNamespaceCannotBeAChild(): void
    {
        $this->expectException(LogicException::class);
        (new ChildScopes())->register(
            $this->scope('order'),
            'order-run',
            'delivery',
            new ScopeIdentity('shop', 'client-b', 'shipment', '1')
        );
    }

    public function testChildFanOutLimitCannotLeaveAPartialParticipant(): void
    {
        $children = new ChildScopes();
        $parent = $this->scope('order');
        for ($index = 0; $index < ChildScopes::MAX_CHILDREN; ++$index) {
            $children->register($parent, 'order-run', 'delivery-' . $index, $this->scope('shipment-' . $index));
        }
        try {
            $children->register($parent, 'order-run', 'overflow', $this->scope('overflow'));
            $this->fail('The child fan-out limit was exceeded.');
        } catch (LogicException) {
            $this->assertCount(ChildScopes::MAX_CHILDREN, (new ScopeRepository())->inspect($parent)['obligations']);
            $this->assertSame(ChildScopes::MAX_CHILDREN, \Workflow\V2\Support\ConfiguredV2Models::connection()
                ->table('workflow_compensation_children')
                ->count());
        }
    }

    private function scope(string $key): ScopeIdentity
    {
        return new ScopeIdentity('shop', 'client-a', $key, '1');
    }
}
