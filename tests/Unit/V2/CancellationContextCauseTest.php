<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Workflow\V2\CancellationContext;
use Workflow\V2\Exceptions\WorkflowCancellationRequestedException;
use Workflow\V2\Exceptions\WorkflowTimeoutCleanupRequested;

/**
 * The optional top-level `cause` of a cancellation context (#863).
 */
final class CancellationContextCauseTest extends TestCase
{
    public function testAnOrdinaryContextIsUnchangedAndStaysACancellation(): void
    {
        $snapshot = self::snapshot();
        $context = CancellationContext::fromArray($snapshot);

        $this->assertFalse($context->isTimeout());
        $this->assertSame($snapshot, $context->toArray());
        $this->assertSame(
            WorkflowCancellationRequestedException::class,
            WorkflowCancellationRequestedException::forContext($context)::class,
        );
        // An explicit cancellation cause round-trips with exactly its own keys.
        $explicit = [
            ...$snapshot,
            'cause' => 'cancellation',
        ];
        $this->assertFalse(CancellationContext::fromArray($explicit)->isTimeout());
        $this->assertSame($explicit, CancellationContext::fromArray($explicit)->toArray());
    }

    public function testATimeoutCauseRoundTripsAndDeliversTheTimeoutSubtype(): void
    {
        $context = CancellationContext::fromArray([
            ...self::snapshot(),
            'cause' => 'timeout',
        ]);

        $this->assertTrue($context->isTimeout());
        $this->assertSame('timeout', $context->toArray()['cause']);
        $exception = WorkflowCancellationRequestedException::forContext($context);
        $this->assertInstanceOf(WorkflowTimeoutCleanupRequested::class, $exception);
        $this->assertInstanceOf(WorkflowCancellationRequestedException::class, $exception);
        $this->assertSame($context, $exception->cancellation);
    }

    public function testADescendantIsCancelledNotTimedOut(): void
    {
        $context = CancellationContext::fromArray([
            ...self::snapshot(),
            'cause' => 'timeout',
        ]);

        $this->assertFalse($context->forDescendant('req-child', 'child-instance', 'child-run')->isTimeout());
    }

    public function testAScopedDescendantOfATimeoutIsCancelledNotTimedOut(): void
    {
        $root = CancellationContext::fromArray([
            ...self::snapshot(),
            'cause' => 'timeout',
        ]);
        $origin = \Workflow\V2\ScopedCancellationContext::fromRunContext($root);
        $child = CancellationContext::fromScopeContext($origin, 'req-child', 'child-instance', 'child-run');

        $this->assertFalse($child->isTimeout());
        $this->assertArrayNotHasKey('cause', $child->toArray());
        // The child's snapshot round-trips: it is not required to carry the root's cause.
        $this->assertFalse(CancellationContext::fromArray($child->toArray())->isTimeout());
    }

    public function testAnUnknownCauseIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CancellationContext::fromArray([
            ...self::snapshot(),
            'cause' => 'deadline',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function snapshot(): array
    {
        return [
            'schema' => 'durable-workflow.cancellation-context/v1',
            'request_id' => 'req-1',
            'root_request_id' => 'req-1',
            'root_workflow_instance_id' => 'instance-1',
            'root_workflow_run_id' => 'run-1',
            'parent_request_id' => null,
            'reason' => 'work deadline passed',
            'requester' => [
                'type' => 'system',
                'id' => 'timeout_cleanup',
            ],
            'source' => 'system',
            'requested_at' => '2026-11-13T00:01:00.000000Z',
            'cleanup_deadline_at' => '2026-11-13T00:01:10.000000Z',
            'lineage' => [[
                'request_id' => 'req-1',
                'workflow_instance_id' => 'instance-1',
                'workflow_run_id' => 'run-1',
            ]],
        ];
    }
}
