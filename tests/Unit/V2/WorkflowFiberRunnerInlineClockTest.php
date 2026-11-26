<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Support\WorkflowFiberRunner;
use Workflow\V2\Workflow;

final class WorkflowFiberRunnerInlineClockTest extends TestCase
{
    /**
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function inlineSteps(): iterable
    {
        yield 'side effect' => [
            'side_effect', [
                'event_type' => 'SideEffectRecorded',
                'payload' => [
                    'sequence' => 2,
                    'result' => Serializer::serializeWithCodec('avro', 'effect'),
                ],
            ]];
        yield 'memo upsert' => [
            'memo', [
                'event_type' => 'MemoUpserted',
                'payload' => [
                    'sequence' => 2,
                    'entries' => [
                        'inline' => 'clock',
                    ],
                ],
            ]];
        yield 'search attribute upsert' => [
            'search_attributes', [
                'event_type' => 'SearchAttributesUpserted',
                'payload' => [
                    'sequence' => 2,
                    'attributes' => [
                        'inline' => 'clock',
                    ],
                ],
            ]];
    }

    /**
     * An inline step has no Server-assigned time when the runner first executes
     * it, so replay must not move the clock to the time it was later recorded.
     *
     * @param array<string, mixed> $recorded
     */
    #[DataProvider('inlineSteps')]
    public function testInlineStepsReadTheSameTimeOnFirstExecutionAndReplay(string $kind, array $recorded): void
    {
        $prefix = [[
            'sequence' => 1,
            'event_type' => 'WorkflowStarted',
            'payload' => [],
            'recorded_at' => '2026-11-01T00:00:00.000000Z',
        ], [
            'sequence' => 2,
            'event_type' => 'ActivityScheduled',
            'payload' => [
                'sequence' => 1,
                'activity_execution_id' => 'a1',
                'activity_type' => 'demo.step',
            ],
            'recorded_at' => '2026-11-01T00:00:00.100000Z',
        ], [
            'sequence' => 3,
            'event_type' => 'ActivityCompleted',
            'payload' => [
                'sequence' => 1,
                'activity_execution_id' => 'a1',
                'activity_type' => 'demo.step',
                'result' => Serializer::serializeWithCodec('avro', 'done'),
                'payload_codec' => 'avro',
            ],
            'recorded_at' => '2026-11-01T00:00:03.000000Z',
        ]];
        $first = WorkflowFiberRunner::forClass(InlineClockWorkflow::class, 'w', 'r', [$kind], 'avro', $prefix)
            ->step();
        $this->assertTrue($first->completed);
        $this->assertSame('2026-11-01T00:00:03.000000+00:00', $first->result['after_inline']);
        // Record the inline step exactly as the first execution authored it.
        foreach ($first->commands as $command) {
            foreach (['entries', 'attributes'] as $field) {
                if (array_key_exists($field, $recorded['payload']) && array_key_exists($field, $command)) {
                    $recorded['payload'][$field] = $command[$field];
                }
            }
        }

        $replayed = WorkflowFiberRunner::forClass(
            InlineClockWorkflow::class,
            'w',
            'r',
            [$kind],
            'avro',
            [
                ...$prefix, [
                    'sequence' => 4,
                    ...$recorded,
                    'recorded_at' => '2026-11-01T00:00:07.000000Z',
                ]],
        )->step();
        $this->assertTrue($replayed->completed);
        $this->assertSame([], $replayed->commands === [] ? [] : array_values(array_filter(
            array_column($replayed->commands, 'type'),
            static fn (string $type): bool => $type !== 'complete_workflow',
        )));

        $this->assertSame($first->result, $replayed->result);
    }
}

final class InlineClockWorkflow extends Workflow
{
    /**
     * @return array<string, string>
     */
    public function handle(string $kind): array
    {
        Workflow::activity('demo.step');
        $before = Workflow::now()->format('Y-m-d\TH:i:s.uP');
        match ($kind) {
            'side_effect' => Workflow::sideEffect(static fn (): string => 'effect'),
            'memo' => Workflow::upsertMemo([
                'inline' => 'clock',
            ]),
            'search_attributes' => Workflow::upsertSearchAttributes([
                'inline' => 'clock',
            ]),
        };

        return [
            'before' => $before,
            'after_inline' => Workflow::now()->format('Y-m-d\TH:i:s.uP'),
        ];
    }
}
