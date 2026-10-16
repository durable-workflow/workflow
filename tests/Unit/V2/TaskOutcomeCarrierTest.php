<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use JsonSerializable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\TaskOutcomeCarrier;

final class TaskOutcomeCarrierTest extends TestCase
{
    public function testDuplicateKeepsTheFirstStoredBytesAndContext(): void
    {
        $task = self::task();
        TaskOutcomeCarrier::append($task, [self::entry()]);
        $stored = json_encode(TaskOutcomeCarrier::fromTask($task), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        $task->setRawAttributes(array_replace($task->getAttributes(), [
            'observability_context' => $stored,
        ]), true);
        $other = self::entry();
        $other['context']['carrier']['traceparent'] = '00-11111111111111111111111111111111-2222222222222222-01';
        TaskOutcomeCarrier::append($task, [$other]);
        self::assertSame($stored, $task->getAttributes()['observability_context']);
        self::assertSame(self::entry(), TaskOutcomeCarrier::fromTask($task)['outcomes'][0]);
        self::assertArrayNotHasKey('observability_context', $task->toArray());
    }

    public function testBoundedAppendRetainsCompleteEarlyEntries(): void
    {
        $task = self::task();
        $entries = [];
        for ($sequence = 1; $sequence <= 60; ++$sequence) {
            $entries[] = array_replace(self::entry(), [
                'event_id' => 'event-' . $sequence,
                'sequence' => $sequence,
            ]);
        }
        TaskOutcomeCarrier::append($task, $entries);
        $stored = TaskOutcomeCarrier::fromTask($task);
        self::assertNotEmpty($stored['outcomes']);
        self::assertLessThanOrEqual(32, count($stored['outcomes']));
        self::assertLessThanOrEqual(4096, strlen($task->getAttributes()['observability_context']));
        self::assertSame(array_slice($entries, 0, count($stored['outcomes'])), $stored['outcomes']);
    }

    public function testLegacyAndMalformedStorageRemainUnchanged(): void
    {
        $legacy = self::task();
        $attributes = $legacy->getAttributes();
        unset($attributes['observability_context']);
        $legacy->setRawAttributes($attributes, true);
        TaskOutcomeCarrier::append($legacy, [self::entry()]);
        self::assertSame($attributes, $legacy->getAttributes());
        $bad = self::task('broken{');
        self::assertNull(TaskOutcomeCarrier::fromTask($bad));
        TaskOutcomeCarrier::append($bad, [self::entry()]);
        self::assertSame('broken{', $bad->getAttributes()['observability_context']);
    }

    public static function invalidEntries(): iterable
    {
        yield 'missing' => [null];
        foreach ([
            'event_id' => '',
            'sequence' => '1',
            'event_type' => 'Other',
            'activity_attempt_id' => null,
        ] as $key => $value) {
            yield $key => [
                array_replace(self::entry(), [
                    $key => $value,
                ])];
        }
        yield 'payload' => [
            self::entry() + [
                'payload' => 'must-not-store',
            ]];
        $baggage = self::entry();
        $baggage['context']['carrier']['baggage'] = 'private=discard';
        yield 'baggage' => [$baggage];
        $invalid = self::entry();
        $invalid['context']['carrier']['traceparent'] = 'invalid';
        yield 'traceparent' => [$invalid];
        yield 'timer identity on activity' => [
            self::entry() + [
                'timer_id' => 'other',
            ]];
    }

    #[DataProvider('invalidEntries')]
    public function testInvalidEntriesNeverBecomeStoredOutcomes(mixed $entry): void
    {
        self::assertNull(TaskOutcomeCarrier::entry($entry));
        $task = self::task();
        TaskOutcomeCarrier::append($task, [$entry]);
        self::assertNull($task->getAttributes()['observability_context']);
    }

    public function testNamespaceRunAndTaskBindingsCannotBeReinterpreted(): void
    {
        $task = self::task();
        TaskOutcomeCarrier::append($task, [self::entry()]);
        $original = TaskOutcomeCarrier::fromTask($task);
        foreach (['namespace', 'run_id', 'task_id', 'schema', 'version'] as $field) {
            $wrong = array_replace($original, [
                $field => 'other',
            ]);
            $task->setRawAttributes(array_replace($task->getAttributes(), [
                'observability_context' => json_encode($wrong, JSON_THROW_ON_ERROR),
            ]), true);
            self::assertNull(TaskOutcomeCarrier::fromTask($task));
        }
        $event = new WorkflowHistoryEvent();
        $event->setRawAttributes([
            'id' => 'event-1',
            'sequence' => 1,
            'event_type' => 'ActivityCompleted',
            'payload' => json_encode([
                'activity_execution_id' => 'activity-1',
                'activity_attempt_id' => 'attempt-1',
            ]),
        ], true);
        self::assertTrue(TaskOutcomeCarrier::matchesEvent(self::entry(), $event));
        self::assertFalse(TaskOutcomeCarrier::matchesEvent(array_replace(self::entry(), [
            'sequence' => 2,
        ]), $event));
        self::assertFalse(TaskOutcomeCarrier::matchesEvent(array_replace(self::entry(), [
            'activity_attempt_id' => 'other',
        ]), $event));
    }

    public function testInspectionNeverExecutesObjectSerialization(): void
    {
        $object = new class() implements JsonSerializable {
            public int $calls = 0;

            public function jsonSerialize(): mixed
            {
                ++$this->calls;
                return 'must-not-execute';
            }
        };
        $entry = self::entry();
        $entry['context']['carrier']['traceparent'] = $object;
        self::assertNull(TaskOutcomeCarrier::entry($entry));
        self::assertNull(TaskOutcomeCarrier::entry(self::entry() + [
            'payload' => $object,
        ]));
        self::assertSame(0, $object->calls);
    }

    private static function task(mixed $context = null): WorkflowTask
    {
        $task = new WorkflowTask();
        $task->setRawAttributes([
            'id' => 'task-1',
            'workflow_run_id' => 'run-1',
            'namespace' => '0',
            'observability_context' => $context,
        ], true);
        return $task;
    }

    private static function entry(): array
    {
        return [
            'event_id' => 'event-1',
            'sequence' => 1,
            'event_type' => 'ActivityCompleted',
            'activity_id' => 'activity-1',
            'activity_attempt_id' => 'attempt-1',
            'context' => [
                'schema' => 'durable-workflow.trace-context',
                'version' => 1,
                'carrier' => [
                    'traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01',
                ],
            ],
        ];
    }
}
