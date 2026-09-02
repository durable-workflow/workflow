<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Tests\TestCase;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Enums\TimerStatus;
use Workflow\V2\Exceptions\HistoryEventShapeMismatchException;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\ActivityCall;
use Workflow\V2\Support\AllCall;
use Workflow\V2\Support\AwaitCall;
use Workflow\V2\Support\ChildWorkflowCall;
use Workflow\V2\Support\DurableOperationHandle;
use Workflow\V2\Support\ParallelChildGroup;
use Workflow\V2\Support\SelectCall;
use Workflow\V2\Support\SignalCall;
use Workflow\V2\Support\TimerCall;

final class ParallelChildGroupTest extends TestCase
{
    public function testMetadataRoundTripsNestedSelectionPathsAndFindsDurableIdentities(): void
    {
        $outer = ParallelChildGroup::groupEntry(10, 3, 0, 'mixed', 'select', 'nested', 0, 10, 2, 'group');
        $inner = ParallelChildGroup::groupEntry(10, 2, 1, 'activity');
        $payload = ParallelChildGroup::payloadForPath([$outer, ['ignored'], $inner]);

        $this->assertSame([$outer, $inner], $payload['parallel_group_path']);
        $this->assertSame($inner, ParallelChildGroup::metadataFromPayload($payload));
        $this->assertSame([$outer, $inner], ParallelChildGroup::metadataPathFromPayload($payload));
        $this->assertSame([10, 11], ParallelChildGroup::sequences($inner));
        $this->assertSame([], ParallelChildGroup::payloadForPath([]));
        $this->assertNull(ParallelChildGroup::metadataFromPayload([
            'parallel_group_id' => 'select-calls:10:3',
            'parallel_group_base_sequence' => 10,
            'parallel_group_size' => 3,
            'parallel_group_index' => 0,
        ]));

        $this->assertSame(
            'parallel-activities:2:2',
            ParallelChildGroup::itemMetadata(2, 2, 0, 'activity')['parallel_group_id']
        );
        $this->assertSame(
            'parallel-calls:2:2',
            ParallelChildGroup::itemMetadata(2, 2, 0, 'mixed')['parallel_group_id']
        );
        $this->assertSame(
            'parallel-timers:2:2',
            ParallelChildGroup::itemMetadata(2, 2, 0, 'timer')['parallel_group_id']
        );
        $this->assertSame('parallel-children:2:2', ParallelChildGroup::itemMetadata(2, 2, 0)['parallel_group_id']);

        $run = $this->runWithHistoryEvents([
            $this->historyEvent(HistoryEventType::ActivityScheduled, 1, [
                'activity_execution_id' => 'activity-10',
                'sequence' => 10,
                ...$payload,
            ]),
            $this->historyEvent(HistoryEventType::TimerScheduled, 2, [
                'timer_id' => 'timer-10',
                'sequence' => 10,
            ]),
            $this->historyEvent(HistoryEventType::SignalWaitOpened, 3, [
                'signal_wait_id' => 'signal-10',
                'sequence' => 10,
            ]),
            $this->historyEvent(HistoryEventType::ConditionWaitOpened, 4, [
                'condition_wait_id' => 'condition-11',
                'sequence' => 11,
            ]),
            $this->historyEvent(HistoryEventType::ChildWorkflowScheduled, 5, [
                'child_workflow_run_id' => 'child-12',
                'sequence' => 12,
            ]),
            $this->historyEvent(HistoryEventType::TimerScheduled, 6, [
                'timer_id' => 'timer-13',
                'sequence' => 13,
            ]),
            $this->historyEvent(HistoryEventType::ActivityCompleted, 7, [
                'activity_execution_id' => 'ignored-terminal',
                'sequence' => 14,
            ]),
            $this->historyEvent(HistoryEventType::ActivityScheduled, 8, [
                'activity_execution_id' => '',
                'sequence' => '15',
            ]),
        ]);

        $this->assertSame([$outer, $inner], ParallelChildGroup::metadataPathForSequence($run, 10));
        $this->assertSame($inner, ParallelChildGroup::metadataForSequence($run, 10));
        $this->assertSame([], ParallelChildGroup::metadataPathForSequence($run, 99));
        $this->assertNull(ParallelChildGroup::metadataForSequence($run, 99));
        $this->assertSame([
            10 => 'signal-10',
            11 => 'condition-11',
            12 => 'child-12',
            13 => 'timer-13',
        ], ParallelChildGroup::durableOperationIdentities($run));

        $handle = new DurableOperationHandle(
            'work',
            0,
            'activity',
            'activity-10',
            10,
            1,
            'select-calls:10:1',
            new ActivityCall('LookupActivity', []),
        );
        $cancellation = $this->historyEvent(HistoryEventType::SelectionOperationCancelled, 9, [
            'selection_group_id' => $handle->selectionGroupId,
            'member_key' => $handle->key,
            'member_index' => $handle->index,
            'member_base_sequence' => $handle->baseSequence,
            'member_size' => $handle->size,
            'operation_kind' => $handle->kind,
            'operation_identity' => $handle->identity,
        ]);
        $run->historyEvents->push($cancellation);

        $this->assertSame($cancellation, ParallelChildGroup::cancellationForHandle($run, $handle));
        $this->assertNull(ParallelChildGroup::cancellationForHandle($this->runWithHistoryEvents([]), $handle));

        $mismatchedHandle = new DurableOperationHandle(
            'forged-key',
            0,
            'activity',
            'activity-10',
            10,
            1,
            'select-calls:10:1',
            new ActivityCall('LookupActivity', []),
        );
        $this->expectException(HistoryEventShapeMismatchException::class);
        $this->expectExceptionMessage('Cancellation field [member_key]');

        ParallelChildGroup::cancellationForHandle($run, $mismatchedHandle);
    }

    public function testMalformedMetadataPathsAreIgnoredOrFallBackToValidRootMetadata(): void
    {
        $root = ParallelChildGroup::groupEntry(7, 2, 1, 'timer');

        $this->assertSame([], ParallelChildGroup::payloadForPath(['malformed', $root]));
        $this->assertSame([$root], ParallelChildGroup::metadataPathFromPayload([
            ...$root,
            'parallel_group_path' => [
                'malformed',
                [
                    'parallel_group_id' => 'unknown:7:2',
                    'parallel_group_base_sequence' => 7,
                    'parallel_group_size' => 2,
                    'parallel_group_index' => 1,
                ],
                [
                    'parallel_group_id' => 'parallel-timers:7:0',
                    'parallel_group_base_sequence' => 7,
                    'parallel_group_size' => 0,
                    'parallel_group_index' => 1,
                ],
            ],
        ]));
        $this->assertSame([], ParallelChildGroup::metadataPathFromPayload([
            'parallel_group_path' => 'malformed',
        ]));
        $this->assertNull(ParallelChildGroup::metadataFromPayload([
            'parallel_group_id' => 'select-calls:7:2',
            'parallel_group_base_sequence' => 7,
            'parallel_group_size' => 2,
            'parallel_group_index' => 1,
            'selection_member_key' => -1,
        ]));
    }

    public function testRecordedSelectionResolutionsBindEveryDurableMemberKindToItsTerminalEvent(): void
    {
        $cases = [
            'activity' => [
                new ActivityCall('LookupActivity', []),
                HistoryEventType::ActivityScheduled,
                HistoryEventType::ActivityCompleted,
                'activity_execution_id',
            ],
            'child' => [
                new ChildWorkflowCall('ChildWorkflow', []),
                HistoryEventType::ChildWorkflowScheduled,
                HistoryEventType::ChildRunCompleted,
                'child_workflow_run_id',
            ],
            'timer' => [
                new TimerCall(5),
                HistoryEventType::TimerScheduled,
                HistoryEventType::TimerFired,
                'timer_id',
            ],
            'signal' => [
                new SignalCall('approved'),
                HistoryEventType::SignalWaitOpened,
                HistoryEventType::SignalApplied,
                'signal_wait_id',
            ],
            'condition' => [
                new AwaitCall(static fn (): bool => true, 'ready'),
                HistoryEventType::ConditionWaitOpened,
                HistoryEventType::ConditionWaitSatisfied,
                'condition_wait_id',
            ],
        ];

        foreach ($cases as $kind => [$call, $openingType, $resolutionType, $identityField]) {
            $identity = "{$kind}-11";
            $opening = $this->historyEvent($openingType, 1, [
                $identityField => $identity,
                'sequence' => 11,
            ], "{$kind}-opening");
            $resolution = $this->historyEvent($resolutionType, 2, [
                $identityField => $identity,
                'sequence' => 11,
            ], "{$kind}-resolution");
            $marker = $this->historyEvent(HistoryEventType::SelectionResolved, 3, [
                'selection_group_id' => 'select-calls:10:2',
                'selection_group_base_sequence' => 10,
                'selection_group_size' => 2,
                'member_key' => 'winner',
                'member_index' => 1,
                'member_base_sequence' => 11,
                'member_size' => 1,
                'operation_kind' => $kind,
                'operation_identity' => $identity,
                'outcome' => 'completed',
                'resolution_event_id' => $resolution->id,
                'resolution_event_type' => $resolutionType->value,
            ], "{$kind}-marker");
            $run = $this->runWithHistoryEvents([$opening, $resolution, $marker]);
            $select = new SelectCall([
                'skipped' => new ActivityCall('SkippedActivity', []),
                'winner' => $call,
            ]);

            $validated = ParallelChildGroup::validatedSelectionResolution($run, $select, 10, $marker);

            $this->assertSame(11, $validated['_resolution_sequence']);
            $this->assertSame($identity, $validated['operation_identity']);
            $this->assertTrue(ParallelChildGroup::selectionMemberIsTerminal($run, 11, 1, $kind));
        }

        $failure = $this->historyEvent(HistoryEventType::ActivityFailed, 2, [
            'activity_execution_id' => 'activity-failed',
            'sequence' => 20,
        ], 'activity-failure');
        $failureMarker = $this->historyEvent(HistoryEventType::SelectionResolved, 3, [
            'selection_group_id' => 'select-calls:20:1',
            'selection_group_base_sequence' => 20,
            'selection_group_size' => 1,
            'member_key' => 'work',
            'member_index' => 0,
            'member_base_sequence' => 20,
            'member_size' => 1,
            'operation_kind' => 'activity',
            'operation_identity' => 'activity-failed',
            'outcome' => 'failed',
            'resolution_event_id' => $failure->id,
            'resolution_event_type' => HistoryEventType::ActivityFailed->value,
        ], 'failure-marker');
        $failedRun = $this->runWithHistoryEvents([
            $this->historyEvent(HistoryEventType::ActivityScheduled, 1, [
                'activity_execution_id' => 'activity-failed',
                'sequence' => 20,
            ], 'failure-opening'),
            $failure,
            $failureMarker,
        ]);

        $validatedFailure = ParallelChildGroup::validatedSelectionResolution(
            $failedRun,
            new SelectCall([
                'work' => new ActivityCall('FailingActivity', []),
            ]),
            20,
            $failureMarker,
        );

        $this->assertSame('failed', $validatedFailure['outcome']);
        $this->assertSame($failure, ParallelChildGroup::memberFailureResolution($failedRun, 20, 1));
    }

    public function testMalformedRecordedSelectionWinnerMetadataIsRejected(): void
    {
        $opening = $this->historyEvent(HistoryEventType::TimerScheduled, 1, [
            'timer_id' => 'timer-4',
            'sequence' => 4,
        ], 'timer-opening');
        $resolution = $this->historyEvent(HistoryEventType::TimerFired, 2, [
            'timer_id' => 'timer-4',
            'sequence' => 4,
        ], 'timer-resolution');
        $validPayload = [
            'selection_group_id' => 'select-calls:4:1',
            'selection_group_base_sequence' => 4,
            'selection_group_size' => 1,
            'member_key' => 'deadline',
            'member_index' => 0,
            'member_base_sequence' => 4,
            'member_size' => 1,
            'operation_kind' => 'timer',
            'operation_identity' => 'timer-4',
            'outcome' => 'completed',
            'resolution_event_id' => $resolution->id,
            'resolution_event_type' => HistoryEventType::TimerFired->value,
        ];
        $mutations = [
            ['selection_group_id', 'select-calls:wrong'],
            ['member_index', 9],
            ['operation_identity', 'timer-forged'],
            ['outcome', 'pending'],
            ['resolution_event_type', HistoryEventType::TimerCancelled->value],
        ];

        foreach ($mutations as $index => [$field, $value]) {
            $payload = $validPayload;
            $payload[$field] = $value;
            $marker = $this->historyEvent(
                HistoryEventType::SelectionResolved,
                3,
                $payload,
                "invalid-marker-{$index}",
            );
            $run = $this->runWithHistoryEvents([$opening, $resolution, $marker]);

            try {
                ParallelChildGroup::validatedSelectionResolution(
                    $run,
                    new SelectCall([
                        'deadline' => new TimerCall(5),
                    ]),
                    4,
                    $marker,
                );
                $this->fail("Malformed winner field [{$field}] was accepted.");
            } catch (HistoryEventShapeMismatchException $exception) {
                $this->assertStringContainsString('selection', strtolower($exception->getMessage()));
            }
        }
    }

    public function testNestedSelectionWinnerMustUseTheCanonicalEventAndACompletedBarrier(): void
    {
        $call = new SelectCall([
            'batch' => new AllCall([
                new ActivityCall('FirstActivity', []),
                new ActivityCall('SecondActivity', []),
            ]),
        ]);
        $first = $this->historyEvent(HistoryEventType::ActivityCompleted, 1, [
            'activity_execution_id' => 'activity-10',
            'sequence' => 10,
        ], 'first-completion');
        $second = $this->historyEvent(HistoryEventType::ActivityCompleted, 2, [
            'activity_execution_id' => 'activity-11',
            'sequence' => 11,
        ], 'second-completion');
        $payload = [
            'selection_group_id' => 'select-calls:10:2',
            'selection_group_base_sequence' => 10,
            'selection_group_size' => 2,
            'member_key' => 'batch',
            'member_index' => 0,
            'member_base_sequence' => 10,
            'member_size' => 2,
            'operation_kind' => 'group',
            'operation_identity' => 'group:10:2',
            'outcome' => 'completed',
            'resolution_event_id' => $first->id,
            'resolution_event_type' => HistoryEventType::ActivityCompleted->value,
        ];
        $nonCanonicalMarker = $this->historyEvent(
            HistoryEventType::SelectionResolved,
            3,
            $payload,
            'non-canonical-marker',
        );

        try {
            ParallelChildGroup::validatedSelectionResolution(
                $this->runWithHistoryEvents([$first, $second, $nonCanonicalMarker]),
                $call,
                10,
                $nonCanonicalMarker,
            );
            $this->fail('A nested winner bound to a non-canonical terminal event was accepted.');
        } catch (HistoryEventShapeMismatchException $exception) {
            $this->assertStringContainsString('event that made the authored member terminal', $exception->getMessage());
        }

        $incompleteMarker = $this->historyEvent(
            HistoryEventType::SelectionResolved,
            2,
            $payload,
            'incomplete-marker',
        );

        try {
            ParallelChildGroup::validatedSelectionResolution(
                $this->runWithHistoryEvents([$first, $incompleteMarker]),
                $call,
                10,
                $incompleteMarker,
            );
            $this->fail('An incomplete nested durable barrier was accepted as a completed winner.');
        } catch (HistoryEventShapeMismatchException $exception) {
            $this->assertStringContainsString('fully completed durable barrier', $exception->getMessage());
        }
    }

    public function testClaimSelectionWinnerPersistsTheFirstEligibleWaitResolution(): void
    {
        $run = $this->createRun();
        $entry = ParallelChildGroup::groupEntry(1, 1, 0, 'signal', 'select', 'approval', 0, 1, 1, 'signal');
        $metadata = ParallelChildGroup::payloadForPath([$entry]);
        $this->record($run, HistoryEventType::SignalWaitOpened, [
            'signal_wait_id' => 'signal-wait-1',
            'signal_name' => 'approved',
            'sequence' => 1,
            ...$metadata,
        ]);
        $resolution = $this->record($run, HistoryEventType::SignalApplied, [
            'signal_wait_id' => 'signal-wait-1',
            'signal_name' => 'approved',
            'sequence' => 1,
            ...$metadata,
        ]);
        $run = $run->fresh(['historyEvents']);
        $resolution = WorkflowHistoryEvent::query()->findOrFail($resolution->id);

        $this->assertTrue(ParallelChildGroup::claimSelectionWinner(
            $run,
            $metadata['parallel_group_path'],
            'signal',
            $resolution,
        ));
        $this->assertFalse(ParallelChildGroup::claimSelectionWinner(
            $run,
            $metadata['parallel_group_path'],
            'signal',
            $resolution,
        ));

        $winner = ParallelChildGroup::selectionResolution($run, 'select-calls:1:1');
        $this->assertNotNull($winner);
        $this->assertSame('approval', $winner->payload['member_key']);
        $this->assertSame('signal', $winner->payload['operation_kind']);
        $this->assertSame('signal-wait-1', $winner->payload['operation_identity']);
        $this->assertTrue(ParallelChildGroup::selectionMemberIsTerminal($run, 1, 1, 'signal'));

        $validated = ParallelChildGroup::validatedSelectionResolution(
            $run,
            new SelectCall([
                'approval' => new SignalCall('approved'),
            ]),
            1,
            $winner,
        );
        $this->assertSame($resolution->id, $validated['resolution_event_id']);

        $handle = new DurableOperationHandle(
            'approval',
            0,
            'signal',
            'signal-wait-1',
            1,
            1,
            'select-calls:1:1',
            new SignalCall('approved'),
        );
        $cancellation = $this->record($run, HistoryEventType::SelectionOperationCancelled, [
            'selection_group_id' => $handle->selectionGroupId,
            'member_key' => $handle->key,
            'member_index' => $handle->index,
            'member_base_sequence' => $handle->baseSequence,
            'member_size' => $handle->size,
            'operation_kind' => $handle->kind,
            'operation_identity' => $handle->identity,
        ]);

        $this->assertSame(
            $cancellation->id,
            ParallelChildGroup::cancellationForHandle($run->fresh(['historyEvents']), $handle)?->id,
        );
    }

    public function testNestedAllBarrierClaimsASelectionWinnerOnlyAfterEveryLeafCompletes(): void
    {
        $run = $this->createRun();
        $outer = ParallelChildGroup::groupEntry(1, 3, 0, 'mixed', 'select', 'nested', 0, 1, 2, 'group');
        $innerFirst = ParallelChildGroup::groupEntry(1, 2, 0, 'activity');
        $innerSecond = ParallelChildGroup::groupEntry(1, 2, 1, 'activity');
        $firstMetadata = ParallelChildGroup::payloadForPath([$outer, $innerFirst]);
        $secondMetadata = ParallelChildGroup::payloadForPath([$outer, $innerSecond]);

        $this->record($run, HistoryEventType::ActivityScheduled, [
            'activity_execution_id' => 'activity-1',
            'activity_type' => 'FirstActivity',
            'sequence' => 1,
            ...$firstMetadata,
        ]);
        $this->record($run, HistoryEventType::ActivityScheduled, [
            'activity_execution_id' => 'activity-2',
            'activity_type' => 'SecondActivity',
            'sequence' => 2,
            ...$secondMetadata,
        ]);
        $this->record($run, HistoryEventType::ActivityCompleted, [
            'activity_execution_id' => 'activity-1',
            'activity_type' => 'FirstActivity',
            'sequence' => 1,
            ...$firstMetadata,
        ]);

        $this->assertFalse(ParallelChildGroup::shouldWakeParentOnActivityClosure(
            $run->fresh(['historyEvents']),
            $firstMetadata['parallel_group_path'],
            ActivityStatus::Completed,
        ));

        $this->record($run, HistoryEventType::ActivityCompleted, [
            'activity_execution_id' => 'activity-2',
            'activity_type' => 'SecondActivity',
            'sequence' => 2,
            ...$secondMetadata,
        ]);
        $run = $run->fresh(['historyEvents']);

        $this->assertTrue(ParallelChildGroup::shouldWakeParentOnActivityClosure(
            $run,
            $secondMetadata['parallel_group_path'],
            ActivityStatus::Completed,
        ));

        $winner = ParallelChildGroup::selectionResolution($run, 'select-calls:1:3');
        $this->assertNotNull($winner);
        $this->assertSame('nested', $winner->payload['member_key']);
        $this->assertSame('group', $winner->payload['operation_kind']);
        $this->assertSame('group:1:2', $winner->payload['operation_identity']);
        $this->assertTrue(ParallelChildGroup::selectionMemberIsTerminal($run, 1, 2, 'group'));

        $select = new SelectCall([
            'nested' => new AllCall([
                new ActivityCall('FirstActivity', []),
                new ActivityCall('SecondActivity', []),
            ]),
            'deadline' => new TimerCall(30),
        ]);
        $validated = ParallelChildGroup::validatedSelectionResolution($run, $select, 1, $winner);
        $this->assertSame(2, $validated['_resolution_sequence']);

        $this->assertTrue(ParallelChildGroup::shouldWakeParentOnActivityClosure(
            $run,
            $secondMetadata['parallel_group_path'],
            ActivityStatus::Completed,
        ));

        WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now(),
            'payload' => [],
            'connection' => 'sync',
            'queue' => 'default',
        ]);

        $this->assertFalse(ParallelChildGroup::shouldWakeParentOnActivityClosure(
            $run,
            $secondMetadata['parallel_group_path'],
            ActivityStatus::Completed,
        ));
        $this->assertTrue(ParallelChildGroup::shouldWakeParentOnActivityClosure(
            $run,
            [$innerSecond],
            ActivityStatus::Failed,
        ));
    }

    public function testClaimSelectionWinnerRejectsAnIncompleteNestedBarrier(): void
    {
        $run = $this->createRun();
        $outer = ParallelChildGroup::groupEntry(1, 2, 0, 'mixed', 'select', 'batch', 0, 1, 2, 'group');
        $innerFirst = ParallelChildGroup::groupEntry(1, 2, 0, 'activity');
        $innerSecond = ParallelChildGroup::groupEntry(1, 2, 1, 'activity');
        $this->record($run, HistoryEventType::ActivityScheduled, [
            'activity_execution_id' => 'activity-1',
            'activity_type' => 'FirstActivity',
            'sequence' => 1,
        ]);
        $this->record($run, HistoryEventType::ActivityScheduled, [
            'activity_execution_id' => 'activity-2',
            'activity_type' => 'SecondActivity',
            'sequence' => 2,
        ]);
        $first = $this->record($run, HistoryEventType::ActivityCompleted, [
            'activity_execution_id' => 'activity-1',
            'activity_type' => 'FirstActivity',
            'sequence' => 1,
        ]);

        $this->assertFalse(ParallelChildGroup::claimSelectionWinner(
            $run->fresh(['historyEvents']),
            [$outer, $innerFirst],
            'activity',
            $first,
        ));

        $second = $this->record($run, HistoryEventType::ActivityCompleted, [
            'activity_execution_id' => 'activity-2',
            'activity_type' => 'SecondActivity',
            'sequence' => 2,
        ]);
        $run = $run->fresh(['historyEvents']);

        $this->assertTrue(ParallelChildGroup::claimSelectionWinner(
            $run,
            [$outer, $innerSecond],
            'activity',
            $second,
        ));
        $this->assertFalse(ParallelChildGroup::claimSelectionWinner($run, [$innerSecond], 'activity', $second));
        $this->assertSame(
            $second->id,
            ParallelChildGroup::selectionResolution($run, 'select-calls:1:2')?->payload['resolution_event_id'],
        );
    }

    public function testMixedAllBarrierWaitsForActivitySignalConditionTimerAndChildCompletion(): void
    {
        $run = $this->createRun();
        $group = ParallelChildGroup::groupEntry(1, 5, 4, 'mixed');
        $path = [$group];

        $this->record($run, HistoryEventType::ActivityScheduled, [
            'activity_execution_id' => 'activity-1',
            'activity_type' => 'LookupActivity',
            'sequence' => 1,
        ]);
        $this->record($run, HistoryEventType::ActivityCompleted, [
            'activity_execution_id' => 'activity-1',
            'activity_type' => 'LookupActivity',
            'sequence' => 1,
        ]);
        $this->record($run, HistoryEventType::SignalWaitOpened, [
            'signal_wait_id' => 'signal-2',
            'signal_name' => 'approved',
            'sequence' => 2,
        ]);

        $this->assertFalse(ParallelChildGroup::shouldWakeParentOnChildClosure(
            $run->fresh(['historyEvents']),
            $path,
            RunStatus::Completed,
            true,
        ));

        $this->record($run, HistoryEventType::SignalApplied, [
            'signal_wait_id' => 'signal-2',
            'signal_name' => 'approved',
            'sequence' => 2,
        ]);
        $this->record($run, HistoryEventType::ConditionWaitOpened, [
            'condition_wait_id' => 'condition-3',
            'condition_key' => 'ready',
            'sequence' => 3,
        ]);
        $this->record($run, HistoryEventType::ConditionWaitTimedOut, [
            'condition_wait_id' => 'condition-3',
            'condition_key' => 'ready',
            'sequence' => 3,
        ]);
        $this->record($run, HistoryEventType::TimerScheduled, [
            'timer_id' => 'timer-4',
            'delay_seconds' => 5,
            'sequence' => 4,
        ]);
        $this->record($run, HistoryEventType::TimerFired, [
            'timer_id' => 'timer-4',
            'delay_seconds' => 5,
            'sequence' => 4,
        ]);
        $this->record($run, HistoryEventType::ChildWorkflowScheduled, [
            'child_workflow_run_id' => 'child-run-5',
            'child_workflow_type' => 'ChildWorkflow',
            'sequence' => 5,
        ]);
        $this->record($run, HistoryEventType::ChildRunCompleted, [
            'child_workflow_run_id' => 'child-run-5',
            'child_workflow_type' => 'ChildWorkflow',
            'child_status' => RunStatus::Completed->value,
            'sequence' => 5,
        ]);

        $run = $run->fresh(['historyEvents']);
        $this->assertTrue(ParallelChildGroup::shouldWakeParentOnChildClosure(
            $run,
            $path,
            RunStatus::Completed,
            true,
        ));
        $this->assertTrue(ParallelChildGroup::shouldWakeParentOnTimerClosure($run, [], TimerStatus::Cancelled));
        $this->assertTrue(ParallelChildGroup::shouldWakeParentOnChildClosure($run, [], RunStatus::Failed));
        $this->assertFalse(ParallelChildGroup::selectionMemberIsTerminal($run, 99, 1, 'timer'));
    }

    public function testAllBarrierRejectsPendingAndFailedDurableMemberStates(): void
    {
        $activityGroup = [ParallelChildGroup::groupEntry(1, 1, 0, 'activity')];
        $pendingActivityRun = $this->createRun();
        $this->record($pendingActivityRun, HistoryEventType::ActivityScheduled, [
            'activity_execution_id' => 'pending-activity',
            'activity_type' => 'PendingActivity',
            'sequence' => 1,
        ]);
        $this->assertFalse(ParallelChildGroup::shouldWakeParentOnActivityClosure(
            $pendingActivityRun->fresh(['historyEvents']),
            $activityGroup,
            ActivityStatus::Completed,
        ));

        $failedActivityRun = $this->createRun();
        $this->record($failedActivityRun, HistoryEventType::ActivityScheduled, [
            'activity_execution_id' => 'failed-activity',
            'activity_type' => 'FailedActivity',
            'sequence' => 1,
        ]);
        $this->record($failedActivityRun, HistoryEventType::ActivityFailed, [
            'activity_execution_id' => 'failed-activity',
            'activity_type' => 'FailedActivity',
            'sequence' => 1,
        ]);
        $this->assertFalse(ParallelChildGroup::shouldWakeParentOnActivityClosure(
            $failedActivityRun->fresh(['historyEvents']),
            $activityGroup,
            ActivityStatus::Completed,
        ));

        $timerGroup = [ParallelChildGroup::groupEntry(1, 1, 0, 'timer')];
        $pendingTimerRun = $this->createRun();
        $this->record($pendingTimerRun, HistoryEventType::TimerScheduled, [
            'timer_id' => 'pending-timer',
            'delay_seconds' => 5,
            'sequence' => 1,
        ]);
        $this->assertFalse(ParallelChildGroup::shouldWakeParentOnTimerClosure(
            $pendingTimerRun->fresh(['historyEvents']),
            $timerGroup,
            TimerStatus::Fired,
        ));

        $childGroup = [ParallelChildGroup::groupEntry(1, 1, 0)];
        $unresolvedChildRun = $this->createRun();
        $this->record($unresolvedChildRun, HistoryEventType::ChildWorkflowScheduled, [
            'child_workflow_run_id' => 'missing-child-run',
            'child_workflow_type' => 'MissingChildWorkflow',
            'sequence' => 1,
        ]);
        $this->assertFalse(ParallelChildGroup::shouldWakeParentOnChildClosure(
            $unresolvedChildRun->fresh(['historyEvents']),
            $childGroup,
            RunStatus::Completed,
        ));

        $pendingChild = $this->createRun();
        $pendingChild->forceFill([
            'status' => RunStatus::Pending,
        ])->save();
        $pendingChildRun = $this->createRun();
        $this->record($pendingChildRun, HistoryEventType::ChildWorkflowScheduled, [
            'child_workflow_instance_id' => $pendingChild->workflow_instance_id,
            'child_workflow_run_id' => $pendingChild->id,
            'child_workflow_type' => 'PendingChildWorkflow',
            'sequence' => 1,
        ]);
        $this->record($pendingChildRun, HistoryEventType::ChildRunStarted, [
            'child_workflow_instance_id' => $pendingChild->workflow_instance_id,
            'child_workflow_run_id' => $pendingChild->id,
            'child_workflow_type' => 'PendingChildWorkflow',
            'sequence' => 1,
        ]);
        $this->assertFalse(ParallelChildGroup::shouldWakeParentOnChildClosure(
            $pendingChildRun->fresh(['historyEvents']),
            $childGroup,
            RunStatus::Completed,
        ));

        $failedChildRun = $this->createRun();
        $this->record($failedChildRun, HistoryEventType::ChildWorkflowScheduled, [
            'child_workflow_run_id' => 'failed-child-run',
            'child_workflow_type' => 'FailedChildWorkflow',
            'sequence' => 1,
        ]);
        $this->record($failedChildRun, HistoryEventType::ChildRunFailed, [
            'child_workflow_run_id' => 'failed-child-run',
            'child_workflow_type' => 'FailedChildWorkflow',
            'child_status' => RunStatus::Failed->value,
            'sequence' => 1,
        ]);
        $this->assertFalse(ParallelChildGroup::shouldWakeParentOnChildClosure(
            $failedChildRun->fresh(['historyEvents']),
            $childGroup,
            RunStatus::Completed,
        ));
    }

    public function testSelectionWinnerUsesTerminalIdentityFallbackAndRejectsMissingIdentity(): void
    {
        $entry = ParallelChildGroup::groupEntry(1, 1, 0, 'timer', 'select', 'deadline', 0, 1, 1, 'timer');

        $run = $this->createRun();
        $resolution = $this->record($run, HistoryEventType::TimerFired, [
            'timer_id' => 'terminal-timer',
            'sequence' => 1,
        ]);
        $this->assertTrue(ParallelChildGroup::claimSelectionWinner(
            $run->fresh(['historyEvents']),
            [$entry],
            'timer',
            $resolution,
        ));
        $this->assertSame(
            'terminal-timer',
            ParallelChildGroup::selectionResolution($run, 'select-calls:1:1')?->payload['operation_identity'],
        );

        $failedRun = $this->createRun();
        $failure = $this->record($failedRun, HistoryEventType::ActivityFailed, [
            'activity_execution_id' => 'failed-activity',
            'sequence' => 3,
        ]);
        $failedRun->unsetRelation('historyEvents');
        $this->assertSame($failure->id, ParallelChildGroup::memberFailureResolution($failedRun, 3, 1)?->id);
        $this->assertTrue(ParallelChildGroup::selectionMemberIsTerminal($failedRun, 3, 1, 'activity'));

        $lockedRun = $this->createRun();
        $lockedEntry = ParallelChildGroup::groupEntry(
            1,
            1,
            0,
            'child',
            'select',
            'child',
            0,
            selectionMemberKind: 'child',
        );
        $this->record($lockedRun, HistoryEventType::ChildWorkflowScheduled, [
            'child_workflow_run_id' => 'completed-child-run',
            'child_workflow_type' => 'CompletedChildWorkflow',
            'sequence' => 1,
        ]);
        $this->record($lockedRun, HistoryEventType::ChildRunCompleted, [
            'child_workflow_run_id' => 'completed-child-run',
            'child_workflow_type' => 'CompletedChildWorkflow',
            'child_status' => RunStatus::Completed->value,
            'sequence' => 1,
        ]);
        $this->assertTrue(ParallelChildGroup::shouldWakeParentOnChildClosure(
            $lockedRun->fresh(['historyEvents']),
            [$lockedEntry],
            RunStatus::Completed,
            true,
        ));
        $this->assertTrue(ParallelChildGroup::selectionMemberIsTerminal($lockedRun, 1, 0, 'group'));

        $missingIdentityRun = $this->createRun();
        $missingIdentityResolution = $this->record($missingIdentityRun, HistoryEventType::TimerFired, [
            'sequence' => 1,
        ]);

        $this->expectException(HistoryEventShapeMismatchException::class);
        $this->expectExceptionMessage('has no durable scheduled or open operation identity');

        ParallelChildGroup::claimSelectionWinner(
            $missingIdentityRun->fresh(['historyEvents']),
            [$entry],
            'timer',
            $missingIdentityResolution,
        );
    }

    private function createRun(): WorkflowRun
    {
        $instance = WorkflowInstance::query()->create([
            'workflow_type' => 'parallel-child-group-test',
            'workflow_class' => self::class,
            'run_count' => 1,
            'reserved_at' => now(),
            'started_at' => now(),
        ]);

        return WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_type' => 'parallel-child-group-test',
            'workflow_class' => self::class,
            'status' => RunStatus::Waiting->value,
            'connection' => 'sync',
            'queue' => 'default',
            'started_at' => now(),
            'last_progress_at' => now(),
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function record(WorkflowRun $run, HistoryEventType $eventType, array $payload): WorkflowHistoryEvent
    {
        return WorkflowHistoryEvent::record($run, $eventType, $payload);
    }

    /**
     * @param list<WorkflowHistoryEvent> $events
     */
    private function runWithHistoryEvents(array $events): WorkflowRun
    {
        $run = new WorkflowRun();
        $run->setRelation('historyEvents', new EloquentCollection($events));

        return $run;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function historyEvent(
        HistoryEventType $eventType,
        int $sequence,
        array $payload,
        ?string $id = null,
    ): WorkflowHistoryEvent {
        $event = new WorkflowHistoryEvent();
        $event->forceFill([
            'id' => $id ?? "event-{$sequence}",
            'sequence' => $sequence,
            'event_type' => $eventType->value,
            'payload' => $payload,
            'recorded_at' => now(),
        ]);

        return $event;
    }
}
