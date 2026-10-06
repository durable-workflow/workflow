<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\CancellationContext;
use Workflow\V2\ScopedCancellationContext;

final class ScopedRunCancellationContextTest extends TestCase
{
    public function testChildRetainsEveryLocalScopeAddressAndItsOriginalNarrowedBudget(): void
    {
        $origin = $this->origin();
        $child = CancellationContext::fromScopeContext($origin, 'child-request', 'child-instance', 'child-run');
        $this->assertSame('root-request', $child->rootRequestId);
        $this->assertSame('inner-request', $child->parentRequestId);
        $this->assertSame($origin->toArray(), $child->scopeOrigin->toArray());
        $this->assertSame('2026-10-04T00:00:00.000000Z', $child->requestedAt()->toISOString());
        $this->assertSame('2026-10-04T00:00:20.000000Z', $child->deadline()->toISOString());
        $this->assertSame('2026-10-04T00:00:30.000000Z', $child->scopeOrigin->rootDeadline()->toISOString());
        $this->assertSame(['root-request', 'child-request'], array_column($child->lineage, 'request_id'));
        $this->assertSame(['outer', 'inner'], array_column($child->scopeOrigin->lineage, 'scope_id'));
        $this->assertSame('maintenance', $child->reason);
        $this->assertSame([
            'type' => 'operator',
            'id' => 'operator-1',
        ], $child->requester);
        $this->assertSame('api', $child->source);
    }

    public function testColdAvroRoundTripAndRunScopeRunPropagationPreserveTheCompleteTree(): void
    {
        $origin = $this->origin();
        $child = CancellationContext::fromScopeContext($origin, 'child-request', 'child-instance', 'child-run');
        $decoded = Serializer::unserializeWithCodec('avro', Serializer::serializeWithCodec('avro', $child->toArray()));
        $cold = CancellationContext::fromArray($decoded);
        $this->assertSame($child->toArray(), $cold->toArray());
        $childScope = ScopedCancellationContext::fromRunContext($cold)
            ->forDescendant('child-scope-request', 'child-instance', 'child-run', 'child-scope');
        $grandchild = CancellationContext::fromScopeContext(
            $childScope,
            'grandchild-request',
            'grandchild-instance',
            'grandchild-run'
        );
        $this->assertSame('child-scope-request', $grandchild->parentRequestId);
        $this->assertSame($origin->lineage, array_slice($grandchild->scopeOrigin->lineage, 0, 2));
        $this->assertSame(['outer', 'inner', 'root', 'child-scope'], array_column(
            $grandchild->scopeOrigin->lineage,
            'scope_id'
        ));
        $this->assertSame($origin->deadline()->toISOString(), $grandchild->deadline()->toISOString());
        $this->assertSame(['root-request', 'child-scope-request', 'grandchild-request'], array_column(
            $grandchild->lineage,
            'request_id'
        ));
        $directGrandchild = $cold->forDescendant('other-child', 'other-instance', 'other-run');
        $this->assertSame($cold->requestId, $directGrandchild->parentRequestId);
        $this->assertSame(['outer', 'inner', 'root'], array_column(
            $directGrandchild->scopeOrigin->lineage,
            'scope_id'
        ));
        $this->assertSame($origin->rootContext->toArray(), $directGrandchild->scopeOrigin->rootContext->toArray());
    }

    public function testObjectKeyOrderingCannotAlterTheRecordedOrigin(): void
    {
        $context = CancellationContext::fromScopeContext($this->origin(), 'child', 'instance-child', 'run-child');
        $snapshot = $context->toArray();
        foreach ($snapshot['lineage'] as &$entry) {
            ksort($entry);
        }
        unset($entry);
        ksort($snapshot['requester']);
        $cold = CancellationContext::fromArray($snapshot);
        $this->assertSame($context->lineage, $cold->lineage);
        $this->assertSame($context->scopeOrigin->toArray(), $cold->scopeOrigin->toArray());
    }

    public function testEarlierParentAuthorityNarrowsChildBudgetWithoutChangingTheRootOrOrigin(): void
    {
        $origin = $this->origin();
        $child = CancellationContext::fromScopeContext(
            $origin,
            'child',
            'child-instance',
            'child-run',
            CarbonImmutable::parse('2026-10-04T00:00:10Z')
        );
        $this->assertSame($origin->toArray(), $child->scopeOrigin->toArray());
        $this->assertSame('2026-10-04T00:00:10.000000Z', $child->deadline()->toISOString());
        $this->assertSame($child->toArray(), CancellationContext::fromArray($child->toArray())->toArray());
        $grandchild = $child->forDescendant('grandchild', 'grandchild-instance', 'grandchild-run');
        $this->assertSame($child->deadline()->toISOString(), $grandchild->deadline()->toISOString());
        $this->assertSame($origin->rootContext->toArray(), $grandchild->scopeOrigin->rootContext->toArray());
        $this->expectException(InvalidArgumentException::class);
        CancellationContext::fromScopeContext(
            $origin,
            'wider',
            'other-instance',
            'other-run',
            CarbonImmutable::parse('2026-10-04T00:00:21Z')
        );
    }

    #[DataProvider('invalidMutations')]
    public function testInvalidOriginCannotBeSilentlyDroppedOrGrantedANewBudget(string $mutation): void
    {
        $snapshot = CancellationContext::fromScopeContext($this->origin(), 'child', 'instance-child', 'run-child')
            ->toArray();
        switch ($mutation) {
            case 'legacy schema': $snapshot['schema'] = 'durable-workflow.cancellation-context/v1';
                break;
            case 'missing origin': unset($snapshot['scope_origin']);
                break;
            case 'invalid origin': $snapshot['scope_origin'] = [];
                break;
            case 'root': $snapshot['root_request_id'] = 'different';
                break;
            case 'parent': $snapshot['parent_request_id'] = 'root-request';
                break;
            case 'reason': $snapshot['reason'] = 'different';
                break;
            case 'source': $snapshot['source'] = 'different';
                break;
            case 'requester': $snapshot['requester']['id'] = 'different';
                break;
            case 'requested at': $snapshot['requested_at'] = '2026-10-04T00:00:01Z';
                break;
            case 'wider deadline': $snapshot['cleanup_deadline_at'] = '2026-10-04T00:00:30Z';
                break;
            case 'different deadline': $snapshot['cleanup_deadline_at'] = '2026-10-04T00:00:10Z';
                break;
            case 'discarded address': array_shift($snapshot['scope_origin']['lineage']);
                break;
            case 'reused request': $snapshot['request_id'] = 'inner-request';
                $snapshot['lineage'][1]['request_id'] = 'inner-request';
                break;
            case 'reused run': $snapshot['lineage'][1]['workflow_run_id'] = 'root-run';
                break;
            case 'altered lineage': $snapshot['lineage'][0]['workflow_instance_id'] = 'different';
                break;
        }
        $this->expectException(InvalidArgumentException::class);
        CancellationContext::fromArray($snapshot);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidMutations(): iterable
    {
        foreach (['legacy schema', 'missing origin', 'invalid origin', 'root', 'parent', 'reason', 'source',
            'requester', 'requested at', 'wider deadline', 'different deadline', 'discarded address',
            'reused request', 'reused run', 'altered lineage'] as $mutation) {
            yield $mutation => [$mutation];
        }
    }

    public function testLineageCannotReenterAnEarlierRunThroughANewScopeAddress(): void
    {
        $origin = $this->origin()
            ->forDescendant('child', 'child-instance', 'child-run', 'root');
        $this->expectException(InvalidArgumentException::class);
        $origin->forDescendant('cycle', 'root-instance', 'root-run', 'unused-scope');
    }

    private function origin(): ScopedCancellationContext
    {
        $root = CancellationContext::fromArray([
            'schema' => 'durable-workflow.cancellation-context/v1',
            'request_id' => 'root-request',
            'root_request_id' => 'root-request',
            'root_workflow_instance_id' => 'root-instance',
            'root_workflow_run_id' => 'root-run',
            'parent_request_id' => null,
            'reason' => 'maintenance',
            'requester' => [
                'type' => 'operator',
                'id' => 'operator-1',
            ],
            'source' => 'api',
            'requested_at' => '2026-10-04T00:00:00Z',
            'cleanup_deadline_at' => '2026-10-04T00:00:30Z',
            'lineage' => [[
                'request_id' => 'root-request',
                'workflow_instance_id' => 'root-instance',
                'workflow_run_id' => 'root-run',
            ]],
        ]);
        return ScopedCancellationContext::fromArray([
            'schema' => ScopedCancellationContext::SCHEMA,
            'root_context' => $root->toArray(),
            'lineage' => [[
                'request_id' => $root->requestId,
                'workflow_instance_id' => 'root-instance',
                'workflow_run_id' => 'root-run',
                'scope_id' => 'outer',
                'cleanup_deadline_at' => $root->deadline()
                    ->toISOString(),
            ]],
        ])->forDescendant(
            'inner-request',
            'root-instance',
            'root-run',
            'inner',
            CarbonImmutable::parse('2026-10-04T00:00:20Z')
        );
    }
}
