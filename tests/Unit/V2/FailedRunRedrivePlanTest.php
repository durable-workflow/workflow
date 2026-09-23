<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\FailedRunRedrivePlan;

final class FailedRunRedrivePlanTest extends TestCase
{
    private Container $previousContainer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousContainer = Container::getInstance();
        $application = new Application(dirname(__DIR__, 3));
        $application->instance('config', new Repository([]));
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);

        parent::tearDown();
    }

    public function testAcceptsCompletedLinearPrefixAndRecordedFailedActivity(): void
    {
        $plan = FailedRunRedrivePlan::forRun($this->runWithHistory($this->linearFailureHistory()));

        self::assertTrue($plan['eligible']);
        self::assertSame(2, $plan['resume_step_sequence']);
        self::assertCount(1, $plan['completed']);
        self::assertSame(1, $plan['completed'][0]['sequence']);
    }

    public function testRefusesFailureWithoutAnAuthoritativeBoundary(): void
    {
        $history = $this->linearFailureHistory();
        unset($history[5]['payload']['failed_step_sequence']);

        $plan = FailedRunRedrivePlan::forRun($this->runWithHistory($history));

        self::assertFalse($plan['eligible']);
        self::assertSame('unrecorded_failure_boundary', $plan['reason']);
    }

    public function testRefusesUnsupportedSideEffectInCompletedPrefix(): void
    {
        $history = $this->linearFailureHistory();
        array_splice($history, 3, 0, [[
            'event_type' => HistoryEventType::SideEffectRecorded,
            'payload' => [
                'sequence' => 1,
            ],
        ]]);

        $plan = FailedRunRedrivePlan::forRun($this->runWithHistory($history));

        self::assertFalse($plan['eligible']);
        self::assertSame('unsupported_history', $plan['reason']);
    }

    public function testRefusesCompletedLocalActivityThatWouldRunAgain(): void
    {
        $history = $this->linearFailureHistory();
        $history[3]['payload']['execution_mode'] = 'local';
        $history[3]['payload']['local_activity'] = true;

        $plan = FailedRunRedrivePlan::forRun($this->runWithHistory($history));

        self::assertFalse($plan['eligible']);
        self::assertSame('unsupported_local_activity', $plan['reason']);
    }

    public function testRefusesMissingOrCorruptCompletedResult(): void
    {
        $history = $this->linearFailureHistory();
        $history[3]['payload']['result'] = 'invalid-avro';

        $plan = FailedRunRedrivePlan::forRun($this->runWithHistory($history));

        self::assertFalse($plan['eligible']);
        self::assertSame('unavailable_activity_result', $plan['reason']);
    }

    public function testRefusesDuplicateCompletedActivity(): void
    {
        $history = $this->linearFailureHistory();
        array_splice($history, 4, 0, [$history[3]]);

        $plan = FailedRunRedrivePlan::forRun($this->runWithHistory($history));

        self::assertFalse($plan['eligible']);
        self::assertSame('duplicate_activity_completion', $plan['reason']);
    }

    public function testRefusesExternalCompletedResultWithoutIndependentRetention(): void
    {
        $history = $this->linearFailureHistory();
        $history[3]['payload']['result'] = [
            'codec' => 'avro',
            'external_storage' => [
                'key' => 'source-run-only',
            ],
        ];

        $plan = FailedRunRedrivePlan::forRun($this->runWithHistory($history));

        self::assertFalse($plan['eligible']);
        self::assertSame('external_activity_result_not_supported', $plan['reason']);
    }

    public function testRefusesActivitySequenceGap(): void
    {
        $history = $this->linearFailureHistory();
        $history[4]['payload']['sequence'] = 3;

        $plan = FailedRunRedrivePlan::forRun($this->runWithHistory($history));

        self::assertFalse($plan['eligible']);
        self::assertSame('activity_sequence_gap', $plan['reason']);
    }

    public function testRefusesNonFailedRun(): void
    {
        $run = $this->runWithHistory($this->linearFailureHistory(), RunStatus::Completed);

        $plan = FailedRunRedrivePlan::forRun($run);

        self::assertFalse($plan['eligible']);
        self::assertSame('run_not_failed', $plan['reason']);
    }

    /**
     * @return list<array{event_type: HistoryEventType, payload: array<string, mixed>}>
     */
    private function linearFailureHistory(): array
    {
        return [
            [
                'event_type' => HistoryEventType::StartAccepted,
                'payload' => [],
            ],
            [
                'event_type' => HistoryEventType::WorkflowStarted,
                'payload' => [],
            ],
            [
                'event_type' => HistoryEventType::ActivityScheduled,
                'payload' => [
                    'sequence' => 1,
                    'activity_type' => 'first',
                ],
            ],
            [
                'event_type' => HistoryEventType::ActivityCompleted,
                'payload' => [
                    'sequence' => 1,
                    'activity_type' => 'first',
                    'payload_codec' => 'avro',
                    'result' => Serializer::serializeWithCodec('avro', 'done'),
                ],
            ],
            [
                'event_type' => HistoryEventType::ActivityFailed,
                'payload' => [
                    'sequence' => 2,
                    'activity_type' => 'second',
                ],
            ],
            [
                'event_type' => HistoryEventType::WorkflowFailed,
                'payload' => [
                    'failed_step_sequence' => 2,
                    'failed_step_kind' => 'activity',
                ],
            ],
        ];
    }

    /**
     * @param list<array{event_type: HistoryEventType, payload: array<string, mixed>}> $history
     */
    private function runWithHistory(array $history, RunStatus $status = RunStatus::Failed): WorkflowRun
    {
        $run = new WorkflowRun([
            'status' => $status,
            'payload_codec' => 'avro',
        ]);
        $run->setRelation('historyEvents', new Collection(array_map(
            static fn (array $entry, int $index): WorkflowHistoryEvent => new WorkflowHistoryEvent([
                'sequence' => $index + 1,
                'event_type' => $entry['event_type'],
                'payload' => $entry['payload'],
            ]),
            $history,
            array_keys($history),
        )));

        return $run;
    }
}
