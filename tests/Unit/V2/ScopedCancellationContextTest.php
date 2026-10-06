<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Workflow\V2\CancellationContext;
use Workflow\V2\ScopedCancellationContext;

final class ScopedCancellationContextTest extends TestCase
{
    public function testSeveralScopesInOneRunPreserveOriginalRootAndDeadlineThroughColdSerialization(): void
    {
        $root = $this->root();
        $context = ScopedCancellationContext::fromRunContext($root)
            ->forDescendant('scope-request-a', 'instance-root', 'run-root', 'scope-a')
            ->forDescendant('scope-request-b', 'instance-root', 'run-root', 'scope-b')
            ->forDescendant('child-request', 'instance-child', 'run-child', 'root');
        $snapshot = $context->toArray();
        CarbonImmutable::setTestNow('2040-01-01T00:00:00Z');
        try {
            $reloaded = ScopedCancellationContext::fromArray(json_decode(
                json_encode($snapshot, JSON_THROW_ON_ERROR),
                true,
                flags: JSON_THROW_ON_ERROR,
            ));
            $this->assertSame($snapshot, $reloaded->toArray());
            $this->assertSame($root->toArray(), $reloaded->rootContext->toArray());
            $this->assertSame('child-request', $reloaded->requestId);
            $this->assertSame('scope-request-b', $reloaded->parentRequestId);
            $this->assertSame('instance-child', $reloaded->workflowInstanceId);
            $this->assertSame('run-child', $reloaded->workflowRunId);
            $this->assertSame('root', $reloaded->scopeId);
            $this->assertSame('root', $reloaded->rootScopeId);
            $this->assertCount(4, $reloaded->lineage);
            $this->assertSame('2026-10-03T00:00:00.000000Z', $reloaded->requestedAt()->toISOString());
            $this->assertSame('2026-10-03T00:00:30.123456Z', $reloaded->deadline()->toISOString());
            $this->assertSame($reloaded->rootDeadline(), $reloaded->rootContext->deadline());
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function testStricterAcceptedScopeBudgetDoesNotRewriteOriginalMetadataOrGrantDescendantsMoreTime(): void
    {
        $original = ScopedCancellationContext::fromRunContext($this->root());
        $before = $original->toArray();
        $scope = $original->forDescendant(
            'scope-request-a',
            'instance-root',
            'run-root',
            'scope-a',
            CarbonImmutable::parse('2026-10-03T00:00:20.000001Z'),
        );
        $child = $scope->forDescendant('child-request', 'instance-child', 'run-child', 'root');
        $this->assertSame($before, $original->toArray());
        $this->assertSame($before['root_context'], $child->toArray()['root_context']);
        $this->assertSame('2026-10-03T00:00:30.123456Z', $child->rootDeadline()->toISOString());
        $this->assertSame('2026-10-03T00:00:20.000001Z', $child->deadline()->toISOString());
        $this->assertSame('maintenance', $child->rootContext->reason);
        $this->assertSame([
            'type' => 'operator',
            'id' => 'operator-1',
        ], $child->rootContext->requester);
        $this->assertSame('api', $child->rootContext->source);
        $copy = $child->toArray();
        $copy['lineage'][1]['scope_id'] = 'changed';
        $child->deadline()
            ->addHour();
        $child->rootDeadline()
            ->addHour();
        $this->assertSame('scope-a', $child->lineage[1]['scope_id']);
        $this->assertSame('2026-10-03T00:00:20.000001Z', $child->deadline()->toISOString());
        $this->expectException(InvalidArgumentException::class);
        $scope->forDescendant(
            'longer-child',
            'instance-child',
            'run-child',
            'root',
            CarbonImmutable::parse('2026-10-03T00:00:21Z'),
        );
    }

    public function testLegacyRunLineageIsAdaptedWithoutChangingItsV1Snapshot(): void
    {
        $root = $this->root();
        $legacy = $root->forDescendant('child-request', 'instance-child', 'run-child');
        $before = $legacy->toArray();
        $context = ScopedCancellationContext::fromRunContext($legacy);
        $this->assertSame($root->toArray(), $context->rootContext->toArray());
        $this->assertSame($before, $legacy->toArray());
        $this->assertSame(['root', 'root'], array_column($context->lineage, 'scope_id'));
        $this->assertSame(['root-request', 'child-request'], array_column($context->lineage, 'request_id'));
        $this->assertSame('child-request', $context->requestId);
        $this->assertSame('root-request', $context->parentRequestId);
        $this->assertSame($legacy->deadline()->toISOString(), $context->deadline()->toISOString());
    }

    public function testDirectScopeRootHasItsOwnOriginalAddressWithoutChangingV1RootMetadata(): void
    {
        $snapshot = ScopedCancellationContext::fromRunContext($this->root())->toArray();
        $snapshot['lineage'][0]['scope_id'] = 'direct-root-scope';
        $context = ScopedCancellationContext::fromArray($snapshot);
        $this->assertSame('direct-root-scope', $context->rootScopeId);
        $this->assertSame('direct-root-scope', $context->scopeId);
        $this->assertNull($context->parentRequestId);
        $this->assertSame($this->root()->toArray(), $context->rootContext->toArray());
    }

    public function testLegacyParserStillRejectsRepeatedRunsAndDoesNotAcceptTheNewSchema(): void
    {
        try {
            $this->root()
                ->forDescendant('scope-request', 'instance-root', 'run-root');
            $this->fail('Legacy v1 parser accepted multiple addresses inside one run.');
        } catch (InvalidArgumentException $error) {
            $this->assertStringContainsString('cycle', $error->getMessage());
        }
        $this->expectExceptionMessage('Unsupported cancellation context schema.');
        CancellationContext::fromArray(ScopedCancellationContext::fromRunContext($this->root())->toArray());
    }

    public function testAddressComparisonCannotConfuseDelimiterCharactersWithRunScopeBoundaries(): void
    {
        $context = ScopedCancellationContext::fromRunContext($this->root())
            ->forDescendant('first', 'instance-first', 'run:first', 'scope')
            ->forDescendant('second', 'instance-second', 'run', 'first:scope');
        $this->assertCount(3, $context->lineage);
        $this->assertSame('second', $context->requestId);
    }

    public function testDatabaseObjectKeyReorderingDoesNotChangeCanonicalScopeAddresses(): void
    {
        $context = ScopedCancellationContext::fromRunContext($this->root())
            ->forDescendant('scope-request', 'instance-root', 'run-root', 'scope-a');
        $snapshot = $context->toArray();
        foreach ($snapshot['lineage'] as &$entry) {
            ksort($entry);
        }
        unset($entry);
        $this->assertSame($context->toArray(), ScopedCancellationContext::fromArray($snapshot)->toArray());
    }

    #[DataProvider('invalidSnapshots')]
    public function testMalformedOrExpandedMetadataCannotChangeAnAcceptedContext(string $mutation): void
    {
        $original = ScopedCancellationContext::fromRunContext($this->root())
            ->forDescendant(
                'scope-request-a',
                'instance-root',
                'run-root',
                'scope-a',
                CarbonImmutable::parse('2026-10-03T00:00:20Z')
            )
            ->forDescendant('scope-request-b', 'instance-root', 'run-root', 'scope-b');
        $snapshot = $original->toArray();
        $before = $snapshot;
        switch ($mutation) {
            case 'schema': $snapshot['schema'] = 'durable-workflow.scoped-cancellation-context/v2';
                break;
            case 'extra top field': $snapshot['authority'] = true;
                break;
            case 'missing root': unset($snapshot['root_context']);
                break;
            case 'empty lineage': $snapshot['lineage'] = [];
                break;
            case 'non-list lineage': $snapshot['lineage'] = [
                'root' => $snapshot['lineage'][0],
            ];
                break;
            case 'non-array entry': $snapshot['lineage'][1] = 'scope-a';
                break;
            case 'extra address field': $snapshot['lineage'][1]['shield_parent'] = false;
                break;
            case 'missing address field': unset($snapshot['lineage'][1]['scope_id']);
                break;
            case 'wrong root request': $snapshot['lineage'][0]['request_id'] = 'other-root';
                break;
            case 'wrong root run': $snapshot['lineage'][0]['workflow_run_id'] = 'other-run';
                break;
            case 'duplicate request': $snapshot['lineage'][2]['request_id'] = 'scope-request-a';
                break;
            case 'repeated address': $snapshot['lineage'][2]['scope_id'] = 'scope-a';
                break;
            case 'contradictory instance': $snapshot['lineage'][2]['workflow_instance_id'] = 'other-instance';
                break;
            case 'longer descendant': $snapshot['lineage'][2]['cleanup_deadline_at'] = '2026-10-03T00:00:21Z';
                break;
            case 'changed root deadline': $snapshot['lineage'][0]['cleanup_deadline_at'] = '2026-10-03T00:00:29Z';
                break;
            case 'malformed deadline': $snapshot['lineage'][2]['cleanup_deadline_at'] = 'tomorrow';
                break;
            case 'expired initial budget': $snapshot['lineage'][2]['cleanup_deadline_at'] = '2026-10-03T00:00:00Z';
                break;
            case 'empty scope': $snapshot['lineage'][2]['scope_id'] = ' ';
                break;
            case 'numeric request': $snapshot['lineage'][2]['request_id'] = 17;
                break;
            case 'extra root metadata': $snapshot['root_context']['scope_id'] = 'forged';
                break;
            case 'descendant used as root': $snapshot['root_context'] = $this->root()
                ->forDescendant('child-request', 'instance-child', 'run-child')
                ->toArray();
                break;
        }
        try {
            ScopedCancellationContext::fromArray($snapshot);
            $this->fail('Invalid scoped metadata was accepted: ' . $mutation);
        } catch (InvalidArgumentException) {
            $this->assertSame($before, $original->toArray());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidSnapshots(): iterable
    {
        foreach ([
            'schema', 'extra top field', 'missing root', 'empty lineage', 'non-list lineage',
            'non-array entry', 'extra address field', 'missing address field', 'wrong root request',
            'wrong root run', 'duplicate request', 'repeated address', 'contradictory instance',
            'longer descendant', 'changed root deadline', 'malformed deadline', 'expired initial budget',
            'empty scope', 'numeric request', 'extra root metadata', 'descendant used as root',
        ] as $mutation) {
            yield $mutation => [$mutation];
        }
    }

    private function root(): CancellationContext
    {
        return CancellationContext::fromArray([
            'schema' => 'durable-workflow.cancellation-context/v1',
            'request_id' => 'root-request',
            'root_request_id' => 'root-request',
            'root_workflow_instance_id' => 'instance-root',
            'root_workflow_run_id' => 'run-root',
            'parent_request_id' => null,
            'reason' => 'maintenance',
            'requester' => [
                'type' => 'operator',
                'id' => 'operator-1',
            ],
            'source' => 'api',
            'requested_at' => '2026-10-03T00:00:00Z',
            'cleanup_deadline_at' => '2026-10-03T00:00:30.123456Z',
            'lineage' => [[
                'request_id' => 'root-request',
                'workflow_instance_id' => 'instance-root',
                'workflow_run_id' => 'run-root',
            ]],
        ]);
    }
}
