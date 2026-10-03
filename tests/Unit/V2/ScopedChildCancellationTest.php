<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Database\Eloquent\Collection;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\ScopedChildCancellation;

final class ScopedChildCancellationTest extends TestCase
{
    public function testHistoryOnlyTargetsHaveStableOrderAndIgnoreApplicationPayloadBytes(): void
    {
        $run = $this->historyRun();
        $first = $this->event('scheduled-a', 3);
        $second = $this->event('scheduled-b', 4, [
            'sequence' => 5,
            'child_call_id' => 'call-b',
        ]);
        $run->setRelation('historyEvents', new Collection([$second, $first]));
        $original = ScopedChildCancellation::members($run, 'scope-a');
        $this->assertSame([4, 5], array_column($original, 'sequence'));
        $first->payload = [
            ...$first->payload,
            'arguments' => 'secret application bytes',
        ];
        $this->assertSame($original, ScopedChildCancellation::members($run, 'scope-a'));
        $this->assertSame($original, ScopedChildCancellation::normalizeMembers(array_map(
            static fn (array $member): array => array_reverse($member, true),
            $original
        )));
        $this->assertSame([], ScopedChildCancellation::members($run, 'scope-sibling'));
    }

    public function testHistoricalMissingPolicyRemainsAbandonAndTerminalHistoryDoesNotRetarget(): void
    {
        $scheduled = $this->event('scheduled', 3);
        $payload = $scheduled->payload;
        unset($payload['cancellation_policy']);
        $scheduled->payload = $payload;
        $terminal = $this->event('terminal', 6, [
            'child_workflow_run_id' => 'different-terminal-run',
        ]);
        $terminal->event_type = HistoryEventType::ChildRunCompleted;
        $run = $this->historyRun();
        $run->setRelation('historyEvents', new Collection([$scheduled, $terminal]));
        $member = ScopedChildCancellation::members($run, 'scope-a')[0];
        $this->assertSame('abandon', $member['cancellation_policy']);
        $this->assertSame('child-run', $member['child_workflow_run_id']);
    }

    #[DataProvider('invalidHistory')]
    public function testMalformedChildAuthorityCannotEnterPreparation(array $changes, bool $started = false): void
    {
        $scheduled = $this->event('scheduled', 3, $started ? [] : $changes);
        $history = [$scheduled];
        if ($started) {
            $row = $this->event('started', 4, $changes);
            $row->event_type = HistoryEventType::ChildRunStarted;
            $history[] = $row;
        }
        $run = $this->historyRun();
        $run->setRelation('historyEvents', new Collection($history));
        $this->expectException(LogicException::class);
        ScopedChildCancellation::members($run, 'scope-a');
    }

    public static function invalidHistory(): iterable
    {
        foreach ([
            'sequence' => 0,
            'child_call_id' => '',
            'child_workflow_instance_id' => false,
            'child_workflow_run_id' => 'parent-run',
            'cancellation_policy' => 'terminate',
            'child_workflow' => false,
        ] as $key => $value) {
            yield 'scheduled:' . $key => [[
                $key => $value,
            ]];
        }
        yield 'conflicting descriptor address' => [[
            'child_workflow' => [
                'cancellation_scope_id' => 'scope-b',
            ],
        ]];
        foreach ([
            'child_call_id' => 'different-call',
            'child_workflow_instance_id' => 'different-instance',
            'child_workflow_run_id' => null,
            'cancellation_scope_id' => 'scope-b',
            'cancellation_policy' => 'abandon',
        ] as $key => $value) {
            yield 'started:' . $key => [[
                $key => $value,
            ], true];
        }
    }

    public function testRepeatedChildCallIdentityCannotCreateAnotherCancellationTarget(): void
    {
        $run = $this->historyRun();
        $run->setRelation('historyEvents', new Collection([
            $this->event('first', 3), $this->event('duplicate', 4, [
                'sequence' => 5,
            ]),
        ]));
        $this->expectExceptionMessage('cancellation_scope_child_history_invalid');
        ScopedChildCancellation::members($run, 'scope-a');
    }

    #[DataProvider('invalidMembership')]
    public function testColdNormalizationRejectsMalformedSnapshots(mixed $snapshot): void
    {
        $this->expectExceptionMessage('cancellation_scope_preparation_history_invalid');
        ScopedChildCancellation::normalizeMembers($snapshot);
    }

    public static function invalidMembership(): iterable
    {
        yield 'not an array' => [null];
        yield 'not a list' => [[
            'member' => [],
        ]];
        yield 'not a member' => [[false]];
        $member = [
            'sequence' => 4,
            'child_call_id' => 'call-a',
            'child_workflow_instance_id' => 'child-instance',
            'child_workflow_run_id' => 'child-run',
            'cancellation_policy' => 'try_cancel',
            'descriptor_hash' => str_repeat('a', 64),
        ];
        foreach ([
            'sequence' => '4',
            'child_call_id' => null,
            'child_workflow_instance_id' => '',
            'child_workflow_run_id' => false,
            'cancellation_policy' => 'terminate',
            'descriptor_hash' => 'invalid',
        ] as $key => $value) {
            yield $key => [[[
                $key => $value,
            ] + $member]];
        }
    }

    public function testEmptyChildSetCanCrossTheBarrierButMembershipCannotPretendToBeAReceipt(): void
    {
        $preparation = new WorkflowHistoryEvent([
            'payload' => [
                'child_members' => [],
            ],
        ]);
        ScopedChildCancellation::assertReady($preparation);
        $run = $this->historyRun();
        $run->setRelation('historyEvents', new Collection([$this->event('scheduled', 3)]));
        $preparation->payload = [
            'child_members' => ScopedChildCancellation::members($run, 'scope-a'),
        ];
        $this->expectExceptionMessage('cancellation_scope_child_delivery_not_established');
        ScopedChildCancellation::assertReady($preparation);
    }

    private function historyRun(): WorkflowRun
    {
        $run = $this->createPartialMock(WorkflowRun::class, ['loadMissing']);
        $run->expects($this->atLeastOnce())
            ->method('loadMissing')
            ->willReturnSelf();
        $run->id = 'parent-run';
        return $run;
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function event(string $id, int $historySequence, array $changes = []): WorkflowHistoryEvent
    {
        return new WorkflowHistoryEvent([
            'id' => $id,
            'sequence' => $historySequence,
            'event_type' => HistoryEventType::ChildWorkflowScheduled,
            'payload' => [...[
                'sequence' => 4,
                'child_call_id' => 'call-a',
                'child_workflow_instance_id' => 'child-instance',
                'child_workflow_run_id' => 'child-run',
                'cancellation_policy' => 'try_cancel',
                'cancellation_scope_id' => 'scope-a',
            ], ...$changes],
        ]);
    }
}
