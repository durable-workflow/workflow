<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Support\WorkflowFiberRunner;
use Workflow\V2\Workflow;

/**
 * The worker-protocol runner reads the task's WorkerReplayClock v1 carrier at
 * ordinary entry (.github#215). The embedded executor already uses the run's
 * started_at; the carrier carries the same instant to external workers.
 */
final class WorkflowFiberRunnerEntryCarrierTest extends TestCase
{
    private const RUN = 'entry-carrier-run';

    private const CARRIER_START = '2026-11-01T00:00:00.000000Z';

    public function testEntryUsesTheCarrierStartAndLaterBoundariesKeepTheirRecordedTimes(): void
    {
        $first = $this->replay(self::history(), self::carrier());

        $this->assertSame([
            'entry' => '2026-11-01T00:00:00.000000+00:00',
            'after_activity' => '2026-11-01T00:00:03.123456+00:00',
            'after_timer' => '2026-11-01T00:00:05.654321+00:00',
        ], $first);
        $this->assertSame($first, $this->replay(self::history(), self::carrier()));
    }

    public function testWithoutACarrierEntryKeepsTheWorkflowStartedTime(): void
    {
        $this->assertSame('2026-11-01T00:00:00.123456+00:00', $this->replay(self::history(), null)['entry']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function unprovedCarriers(): iterable
    {
        $carrier = self::carrier();

        yield 'extra key' => [[
            ...$carrier,
            'extra' => true,
        ]];
        yield 'missing source' => [
            array_diff_key($carrier, [
                'source' => true,
            ])];
        yield 'schema' => [[
            ...$carrier,
            'schema' => 'durable-workflow.worker-replay-clock/v2',
        ]];
        yield 'another run' => [[
            ...$carrier,
            'workflow_run_id' => 'another-run',
        ]];
        yield 'precision' => [[
            ...$carrier,
            'started_at' => '2026-11-01T00:00:00Z',
        ]];
        yield 'offset' => [[
            ...$carrier,
            'started_at' => '2026-11-01T00:00:00.000000+00:00',
        ]];
        yield 'calendar' => [[
            ...$carrier,
            'started_at' => '2026-02-30T00:00:00.000000Z',
        ]];
        yield 'source' => [[
            ...$carrier,
            'source' => 'recorded_at',
        ]];
        yield 'non-string source' => [[
            ...$carrier,
            'source' => ['run_started_at'],
        ]];
        yield 'redrive source on ordinary history' => [[
            ...$carrier,
            'source' => 'redrive_replayed_started_at',
        ]];
    }

    /**
     * @param array<string, mixed> $carrier
     */
    #[DataProvider('unprovedCarriers')]
    public function testUnprovedCarriersKeepTheWorkflowStartedTime(array $carrier): void
    {
        $this->assertSame('2026-11-01T00:00:00.123456+00:00', $this->replay(self::history(), $carrier)['entry']);
    }

    public function testAHistoryProvedRedriveAnchorIsNeverReplaced(): void
    {
        $history = self::history();
        $history[0]['payload'] = [
            'recovery_kind' => 'redrive',
            'replayed_started_at' => '2026-10-31T23:00:00.000000Z',
        ];

        foreach ([
            self::carrier(), [
                ...self::carrier(),
                'source' => 'redrive_replayed_started_at',
            ]] as $carrier) {
            $this->assertSame('2026-10-31T23:00:00.000000+00:00', $this->replay($history, $carrier)['entry']);
        }
    }

    /**
     * @param list<array<string, mixed>> $history
     * @param array<string, mixed>|null $carrier
     * @return array<string, string>
     */
    private function replay(array $history, ?array $carrier): array
    {
        $step = WorkflowFiberRunner::forClass(
            EntryCarrierRunnerWorkflow::class,
            'entry-carrier-workflow',
            self::RUN,
            [],
            'avro',
            $history,
            replayClock: $carrier,
        )->step();

        $this->assertTrue($step->completed);
        $this->assertIsArray($step->result);

        return $step->result;
    }

    /**
     * @return array<string, string>
     */
    private static function carrier(): array
    {
        return [
            'schema' => 'durable-workflow.worker-replay-clock/v1',
            'workflow_run_id' => self::RUN,
            'started_at' => self::CARRIER_START,
            'source' => 'run_started_at',
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function history(): array
    {
        return [[
            'sequence' => 1,
            'event_type' => 'WorkflowStarted',
            'payload' => [],
            'recorded_at' => '2026-11-01T00:00:00.123456Z',
        ], [
            'sequence' => 2,
            'event_type' => 'ActivityScheduled',
            'payload' => [
                'sequence' => 1,
                'activity_execution_id' => 'activity-1',
                'activity_type' => 'demo.step',
            ],
            'recorded_at' => '2026-11-01T00:00:00.200000Z',
        ], [
            'sequence' => 3,
            'event_type' => 'ActivityCompleted',
            'payload' => [
                'sequence' => 1,
                'activity_execution_id' => 'activity-1',
                'activity_type' => 'demo.step',
                'result' => Serializer::serializeWithCodec('avro', 'first'),
                'payload_codec' => 'avro',
            ],
            'recorded_at' => '2026-11-01T00:00:03.123456Z',
        ], [
            'sequence' => 4,
            'event_type' => 'TimerScheduled',
            'payload' => [
                'sequence' => 2,
                'timer_id' => 'timer-2',
                'delay_seconds' => 2,
            ],
            'recorded_at' => '2026-11-01T00:00:03.200000Z',
        ], [
            'sequence' => 5,
            'event_type' => 'TimerFired',
            'payload' => [
                'sequence' => 2,
                'timer_id' => 'timer-2',
                'delay_seconds' => 2,
            ],
            'recorded_at' => '2026-11-01T00:00:05.654321Z',
        ]];
    }
}

final class EntryCarrierRunnerWorkflow extends Workflow
{
    /**
     * @return array<string, string>
     */
    public function handle(): array
    {
        $entry = Workflow::now()->format('Y-m-d\TH:i:s.uP');
        Workflow::activity('demo.step');
        $afterActivity = Workflow::now()->format('Y-m-d\TH:i:s.uP');
        Workflow::timer(2);

        return [
            'entry' => $entry,
            'after_activity' => $afterActivity,
            'after_timer' => Workflow::now()->format('Y-m-d\TH:i:s.uP'),
        ];
    }
}
