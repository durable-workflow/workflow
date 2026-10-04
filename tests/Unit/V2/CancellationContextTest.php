<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Carbon\CarbonImmutable;
use Fiber;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use Workflow\V2\CancellationContext;
use Workflow\V2\Exceptions\WorkflowCancellationRequestedException;
use Workflow\V2\Support\WorkflowExecution;
use Workflow\V2\Support\WorkflowFiberContext;

final class CancellationContextTest extends TestCase
{
    public function testSnapshotAndDeadlineRemainImmutableWhenTheCallerChangesItsCopies(): void
    {
        $snapshot = $this->snapshot();
        $context = CancellationContext::fromArray($snapshot);
        $snapshot['reason'] = 'changed';
        $snapshot['requester']['id'] = 'changed';
        $snapshot['lineage'][0]['request_id'] = 'changed';
        $context->deadline()
            ->addHour();
        $context->requestedAt()
            ->addDay();

        $this->assertSame('maintenance', $context->reason);
        $this->assertSame('operator-1', $context->requester['id']);
        $this->assertSame('root-1', $context->lineage[0]['request_id']);
        $this->assertSame($this->snapshot(), $context->toArray());
        $this->assertSame($context->toArray(), CancellationContext::fromArray($context->toArray())->toArray());
    }

    public function testRemainingUsesRecordedWorkflowTimeDespiteAChangedHostClock(): void
    {
        $context = CancellationContext::fromArray($this->snapshot());
        CarbonImmutable::setTestNow('2040-01-01T00:00:00Z');
        try {
            $fiber = new Fiber(function () use ($context): void {
                WorkflowFiberContext::enter();
                try {
                    $current = Fiber::getCurrent();
                    $this->assertInstanceOf(Fiber::class, $current);
                    $context->bindToReplayFiber($current);
                    WorkflowFiberContext::startCancellationTime(
                        CarbonImmutable::parse('2026-10-01T00:00:03.500000Z'),
                        Fiber::getCurrent(),
                    );
                    WorkflowFiberContext::setTime(CarbonImmutable::parse('2026-10-01T00:00:03.500000Z'));
                    $this->assertSame(26.5, $context->remaining());
                    WorkflowFiberContext::setTime(CarbonImmutable::parse('2026-10-01T00:00:31Z'));
                    $this->assertSame(0.0, $context->remaining());
                } finally {
                    WorkflowFiberContext::leave();
                }
            });
            $fiber->start();
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function testRemainingOutsideAWorkflowRefusesWallClockArithmetic(): void
    {
        $this->expectException(LogicException::class);
        CancellationContext::fromArray($this->snapshot())->remaining();
    }

    public function testConsumedBudgetPreservesMicrosecondsAcrossSynchronousPersistenceAndClockSkew(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['cleanup_deadline_at'] = '2026-10-01T00:00:30.123456Z';
        $context = CancellationContext::fromArray($snapshot);
        $execution = WorkflowExecution::startCallback(static function () use ($context): array {
            try {
                Fiber::suspend('initial');
            } catch (WorkflowCancellationRequestedException) {
                // The real delivery arms the consumed-budget clock.
            }
            $initial = $context->remaining();
            Fiber::suspend('memo');
            $afterMemo = $context->remaining();
            Fiber::suspend('activity');
            $afterActivity = $context->remaining();
            Fiber::suspend('timer');

            return [
                $initial,
                $afterMemo,
                $afterActivity,
                $context->remaining(),
                WorkflowFiberContext::getRecordedTime()->format('H:i:s.u'),
            ];
        }, eventTime: CarbonImmutable::parse('2026-10-01T00:00:08Z'));

        $execution->throw(
            new WorkflowCancellationRequestedException(cancellation: $context),
            CarbonImmutable::parse('2026-10-01T00:00:08Z'),
        );
        $execution->send(null, CarbonImmutable::parse('2026-10-01T00:00:28Z'), advanceCancellationTime: false);
        $execution->send(null, CarbonImmutable::parse('2026-10-01T00:00:25Z'));
        $execution->send(null, CarbonImmutable::parse('2026-10-01T00:00:20Z'));

        $this->assertSame([22.123456, 22.123456, 5.123456, 5.123456, '00:00:20.000000'], $execution->getReturn());
    }

    public function testMissingBlockingResumeTimestampCannotReuseAnEarlierBudget(): void
    {
        $context = CancellationContext::fromArray($this->snapshot());
        $execution = WorkflowExecution::startCallback(static function () use ($context): float {
            try {
                Fiber::suspend('initial');
            } catch (WorkflowCancellationRequestedException) {
                // Continue into the durable cleanup wait.
            }
            Fiber::suspend('activity');

            return $context->remaining();
        }, eventTime: CarbonImmutable::parse('2026-10-01T00:00:08Z'));

        $execution->throw(
            new WorkflowCancellationRequestedException(cancellation: $context),
            CarbonImmutable::parse('2026-10-01T00:00:08Z'),
        );
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('recorded blocking boundary timestamp');
        $execution->send(null);
    }

    public function testRemainingInAnUnseededFiberRefusesWallClockArithmetic(): void
    {
        $context = CancellationContext::fromArray($this->snapshot());
        $fiber = new Fiber(static function () use ($context): void {
            WorkflowFiberContext::enter();
            try {
                $context->remaining();
            } finally {
                WorkflowFiberContext::leave();
            }
        });
        $this->expectException(LogicException::class);
        $fiber->start();
    }

    public function testDetachedMetadataCannotBorrowAnActiveDeliveryClock(): void
    {
        $context = CancellationContext::fromArray($this->snapshot());
        $execution = WorkflowExecution::startCallback(static function (): void {
            try {
                Fiber::suspend('delivery');
            } catch (WorkflowCancellationRequestedException $cancelled) {
                $delivered = $cancelled->cancellation;
                self::assertNotNull($delivered);
                self::assertSame(27.0, $delivered->remaining());
                $detached = CancellationContext::fromArray($delivered->toArray());

                $detached->remaining();
            }
        });
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('active workflow replay');
        $execution->throw(
            new WorkflowCancellationRequestedException(cancellation: $context),
            CarbonImmutable::parse('2026-10-01T00:00:03Z'),
        );
    }

    public function testDeliveredContextCannotBorrowAnotherFiberAndRetainsMetadataEquality(): void
    {
        $context = CancellationContext::fromArray($this->snapshot());
        $execution = WorkflowExecution::startCallback(static function (): void {
            try {
                Fiber::suspend('delivery');
            } catch (WorkflowCancellationRequestedException) {
                Fiber::suspend('cleanup');
            }
        });
        $execution->throw(
            new WorkflowCancellationRequestedException(cancellation: $context),
            CarbonImmutable::parse('2026-10-01T00:00:03Z')
        );
        $this->assertEquals(CancellationContext::fromArray($context->toArray()), $context);
        $other = WorkflowExecution::startCallback(static function () use ($context): void {
            try {
                Fiber::suspend('delivery');
            } catch (WorkflowCancellationRequestedException $cancelled) {
                self::assertNotNull($cancelled->cancellation);
                self::assertSame(26.0, $cancelled->cancellation->remaining());

                $context->remaining();
            }
        });
        try {
            $this->expectException(LogicException::class);
            $this->expectExceptionMessage('active workflow replay');
            $other->throw(
                new WorkflowCancellationRequestedException(cancellation: CancellationContext::fromArray(
                    $this->snapshot()
                )),
                CarbonImmutable::parse('2026-10-01T00:00:04Z')
            );
        } finally {
            $execution->send(null);
        }
    }

    public function testDescendantKeepsTheRootBudgetAndImmediateParentIdentity(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['request_id'] = 'child-1';
        $snapshot['parent_request_id'] = 'root-1';
        $snapshot['lineage'][] = [
            'request_id' => 'child-1',
            'workflow_instance_id' => 'child-instance',
            'workflow_run_id' => 'child-run',
        ];
        $context = CancellationContext::fromArray($snapshot);
        $this->assertSame('root-1', $context->rootRequestId);
        $this->assertSame('root-1', $context->parentRequestId);
        $this->assertSame($snapshot, $context->toArray());
    }

    public function testRequesterCannotCarryUnrelatedRequestOrAuthenticationMetadata(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['requester']['headers'] = 'unexpected';
        $this->expectException(InvalidArgumentException::class);
        CancellationContext::fromArray($snapshot);
    }

    public function testMismatchedLineageIsRefused(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['root_request_id'] = 'different-root';
        $this->expectException(InvalidArgumentException::class);
        CancellationContext::fromArray($snapshot);
    }

    public function testCyclicLineageIsRefused(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['parent_request_id'] = 'root-1';
        $snapshot['lineage'][] = $snapshot['lineage'][0];
        $this->expectException(InvalidArgumentException::class);
        CancellationContext::fromArray($snapshot);
    }

    public function testMalformedCalendarTimestampIsRefused(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['requested_at'] = '2026-02-30T00:00:00Z';
        $this->expectException(InvalidArgumentException::class);
        CancellationContext::fromArray($snapshot);
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(): array
    {
        return [
            'schema' => 'durable-workflow.cancellation-context/v1',
            'request_id' => 'root-1',
            'root_request_id' => 'root-1',
            'root_workflow_instance_id' => 'root-instance',
            'root_workflow_run_id' => 'root-run',
            'parent_request_id' => null,
            'reason' => 'maintenance',
            'requester' => [
                'type' => 'operator',
                'id' => 'operator-1',
            ],
            'source' => 'control_plane',
            'requested_at' => '2026-10-01T00:00:00.000000Z',
            'cleanup_deadline_at' => '2026-10-01T00:00:30.000000Z',
            'lineage' => [[
                'request_id' => 'root-1',
                'workflow_instance_id' => 'root-instance',
                'workflow_run_id' => 'root-run',
            ]],
        ];
    }
}
