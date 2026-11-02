<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Fixtures\V2\TestGoldenReplayWorkflow;
use Tests\TestCase;
use Workflow\Serializers\CodecRegistry;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Exceptions\HistoryEventShapeMismatchException;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowSignal;
use Workflow\V2\Support\HistoryExport;
use Workflow\V2\Support\HistoryTimeline;
use Workflow\V2\Support\QueryStateReplayer;
use Workflow\V2\Support\WorkflowReplayer;
use Workflow\V2\Support\WorkflowStepHistory;

final class QueryStateReplayerBoundaryTest extends TestCase
{
    private const GOLDEN_HISTORY = __DIR__ . '/../../Fixtures/V2/GoldenHistory/php-workflow-2.0.0-alpha.7.json';

    public function testRejectedOutcomePreservesColdAndExportedReplayWithoutBecomingABusinessStep(): void
    {
        foreach ($this->goldenCases() as $caseIndex => $case) {
            $original = $this->createRun(
                $case['scenario'],
                $case['history'],
                "original-{$caseIndex}",
                RunStatus::Completed,
            );
            $originalState = (new QueryStateReplayer())->replayState($original->fresh());
            $originalNext = WorkflowStepHistory::nextDurableCommandSequence($original->fresh());
            $history = $case['history'];
            array_splice($history, 1, 0, [[
                'event_type' => 'ActivityOutcomeRejected',
                'payload' => $this->rejectedOutcomePayload(),
            ]]);
            $run = $this->createRun($case['scenario'], $history, "diagnostic-{$caseIndex}", RunStatus::Completed);
            $run = $run->fresh();
            $before = $run->getRawOriginal();
            $rowCounts = $this->runtimeRowCounts($run);

            $cold = (new QueryStateReplayer())->replayState($run->fresh());
            $bundle = HistoryExport::forRun($run->fresh());
            $exported = (new WorkflowReplayer())->replayExport($bundle);
            $timeline = HistoryTimeline::fromHistory($run->fresh());
            $point = collect($timeline)
                ->firstWhere('type', 'ActivityOutcomeRejected');

            $this->assertNull($cold->current);
            $this->assertNull($exported->current);
            $this->assertSame($originalState->sequence, $cold->sequence);
            $this->assertSame($originalState->sequence, $exported->sequence);
            $this->assertSame($case['expected_state'], $cold->workflow->currentState());
            $this->assertSame($case['expected_state'], $exported->workflow->currentState());
            $this->assertSame($originalNext, WorkflowStepHistory::nextDurableCommandSequence($run->fresh()));
            $this->assertSame('activity', $point['kind']);
            $this->assertSame('original-activity', $point['source_id']);
            $this->assertStringContainsString('lease_expired', $point['summary']);
            $this->assertSame($before, $run->fresh()->getRawOriginal());
            $this->assertSame($rowCounts, $this->runtimeRowCounts($run));
        }
    }

    public function testRejectedCompletionCannotResolveAnUnfinishedActivity(): void
    {
        $case = $this->goldenCases()[0];
        $history = [
            $case['history'][0],
            [
                'event_type' => 'ActivityOutcomeRejected',
                'payload' => $this->rejectedOutcomePayload(),
            ],
        ];
        $run = $this->createRun('single-activity', $history, 'rejection-is-not-completion', RunStatus::Waiting);
        $before = $this->runtimeRowCounts($run);

        $cold = (new QueryStateReplayer())->replayState($run->fresh());
        $exported = (new WorkflowReplayer())->replayExport(HistoryExport::forRun($run->fresh()));

        $this->assertNotNull($cold->current);
        $this->assertNotNull($exported->current);
        $this->assertSame($cold->current::class, $exported->current::class);
        $this->assertSame($cold->sequence, $exported->sequence);
        $this->assertSame($before, $this->runtimeRowCounts($run));
        $this->assertSame(RunStatus::Waiting, $run->fresh()->status);
        $this->assertNull($run->fresh()->output);
    }

    public function testEveryGoldenHistoryPrefixReplaysDeterministicallyWithoutWritingRuntimeState(): void
    {
        Carbon::setTestNow('2026-08-29 12:00:00');
        $this->beforeApplicationDestroyed(static function (): void {
            Carbon::setTestNow();
        });

        foreach ($this->goldenCases() as $caseIndex => $case) {
            $history = $case['history'];

            for ($prefixLength = 0; $prefixLength < count($history); $prefixLength++) {
                $run = $this->createRun(
                    $case['scenario'],
                    array_slice($history, 0, $prefixLength),
                    "prefix-{$caseIndex}-{$prefixLength}",
                    RunStatus::Waiting,
                );
                $before = $this->runtimeRowCounts($run);

                $first = (new QueryStateReplayer())->replayState($run->fresh());
                $second = (new QueryStateReplayer())->replayState($run->fresh());

                $this->assertNotNull($first->current, "{$case['name']} prefix {$prefixLength} advanced past history.");
                $this->assertSame($first->sequence, $second->sequence);
                $this->assertSame($first->current::class, $second->current::class);
                $this->assertSame($first->workflow->currentState(), $second->workflow->currentState());
                $this->assertSame($before, $this->runtimeRowCounts($run));
            }
        }
    }

    public function testCompleteGoldenHistoriesRestoreQueryStateWithoutReexecutingDurableOperations(): void
    {
        foreach ($this->goldenCases() as $caseIndex => $case) {
            $run = $this->createRun(
                $case['scenario'],
                $case['history'],
                "complete-{$caseIndex}",
                RunStatus::Completed,
            );
            $before = $this->runtimeRowCounts($run);

            $state = (new QueryStateReplayer())->replayState($run->fresh());
            $queried = (new QueryStateReplayer())->query($run->fresh(), 'currentState');

            $this->assertNull($state->current);
            $this->assertSame($case['expected_state'], $state->workflow->currentState());
            $this->assertSame($case['expected_state'], $queried);
            $this->assertSame($before, $this->runtimeRowCounts($run));
        }
    }

    public function testReplayRejectsAnActivityCompletionForTheWrongAuthoredActivity(): void
    {
        $case = $this->goldenCases()[0];
        $case['history'][1]['payload']['activity_type'] = 'Tests\\Fixtures\\V2\\UnexpectedActivity';
        $run = $this->createRun('single-activity', $case['history'], 'activity-drift', RunStatus::Waiting);

        $this->expectException(HistoryEventShapeMismatchException::class);
        $this->expectExceptionMessage('activity_type');

        (new QueryStateReplayer())->replayState($run->fresh());
    }

    private function rejectedOutcomePayload(): array
    {
        return [
            'activity_execution_id' => 'original-activity',
            'activity_attempt_id' => 'original-attempt',
            'attempt_number' => 1,
            'lease_owner' => 'original-worker',
            'outcome' => 'completed',
            'reason' => 'lease_expired',
            'observed_at' => '2026-10-24T01:00:00.000000Z',
        ];
    }

    /**
     * @return list<array{
     *     name: string,
     *     scenario: string,
     *     history: list<array{event_type: string, payload: array<string, mixed>}>,
     *     expected_state: array<string, mixed>
     * }>
     */
    private function goldenCases(): array
    {
        $fixture = json_decode((string) file_get_contents(self::GOLDEN_HISTORY), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('durable-workflow.golden-history.v1', $fixture['fixture_schema'] ?? null);
        $this->assertIsArray($fixture['cases'] ?? null);

        return $fixture['cases'];
    }

    /**
     * @param list<array{event_type: string, payload: array<string, mixed>}> $history
     */
    private function createRun(
        string $scenario,
        array $history,
        string $identity,
        RunStatus $status,
    ): WorkflowRun {
        $codec = CodecRegistry::defaultCodec();
        $instanceId = "query-replay-boundary-{$identity}-" . strtolower((string) Str::ulid());
        $instance = WorkflowInstance::query()->create([
            'id' => $instanceId,
            'workflow_class' => TestGoldenReplayWorkflow::class,
            'workflow_type' => 'test-golden-replay-workflow',
            'run_count' => 1,
        ]);
        $run = WorkflowRun::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestGoldenReplayWorkflow::class,
            'workflow_type' => 'test-golden-replay-workflow',
            'status' => $status->value,
            'closed_reason' => $status->isTerminal() ? $status->value : null,
            'payload_codec' => $codec,
            'arguments' => Serializer::serializeWithCodec($codec, [$scenario]),
            'output' => null,
            'connection' => 'redis',
            'queue' => 'workflow',
            'started_at' => now()
                ->subMinute(),
            'closed_at' => $status->isTerminal() ? now() : null,
            'last_progress_at' => now(),
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        foreach ($history as $index => $event) {
            WorkflowHistoryEvent::query()->create([
                'workflow_run_id' => $run->id,
                'sequence' => $index + 1,
                'event_type' => HistoryEventType::from($event['event_type'])->value,
                'payload' => $this->normalizePayload($event['payload'], $codec),
                'recorded_at' => now()
                    ->subSeconds(count($history) - $index),
            ]);
        }

        $run->forceFill([
            'last_history_sequence' => count($history),
        ])->save();

        return $run;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function normalizePayload(array $payload, string $codec): array
    {
        foreach (['result', 'value', 'arguments'] as $field) {
            $valueField = "{$field}_value";
            if (! array_key_exists($valueField, $payload)) {
                continue;
            }

            $payload[$field] = Serializer::serializeWithCodec($codec, $payload[$valueField]);
            $payload['payload_codec'] ??= $codec;
            unset($payload[$valueField]);
        }

        return $payload;
    }

    /**
     * @return array{history: int, commands: int, signals: int}
     */
    private function runtimeRowCounts(WorkflowRun $run): array
    {
        return [
            'history' => WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)->count(),
            'commands' => WorkflowCommand::query()->where('workflow_run_id', $run->id)->count(),
            'signals' => WorkflowSignal::query()->where('workflow_run_id', $run->id)->count(),
        ];
    }
}
