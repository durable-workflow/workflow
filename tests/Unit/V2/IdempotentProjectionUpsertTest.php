<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use PDOException;
use Tests\TestCase;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTimelineEntry;
use Workflow\V2\Support\IdempotentProjectionUpsert;

final class IdempotentProjectionUpsertTest extends TestCase
{
    public function testInsertsRowWhenNoConflictExists(): void
    {
        $run = $this->seedRun();
        $projectionId = hash('sha256', $run->id . '|happy-path');

        $row = IdempotentProjectionUpsert::upsert(
            WorkflowTimelineEntry::class,
            ['id' => $projectionId],
            $this->timelineAttributes($run, 'happy-path', 'first-pass'),
        );

        $this->assertSame($projectionId, $row->id);
        $this->assertSame('first-pass', $row->summary);
        $this->assertDatabaseHas('workflow_run_timeline_entries', [
            'id' => $projectionId,
            'summary' => 'first-pass',
        ]);
    }

    public function testUpdatesExistingRowWhenItAlreadyExists(): void
    {
        $run = $this->seedRun();
        $projectionId = hash('sha256', $run->id . '|already-exists');

        WorkflowTimelineEntry::query()->create(
            ['id' => $projectionId] + $this->timelineAttributes($run, 'already-exists', 'old-summary'),
        );

        $row = IdempotentProjectionUpsert::upsert(
            WorkflowTimelineEntry::class,
            ['id' => $projectionId],
            $this->timelineAttributes($run, 'already-exists', 'new-summary'),
        );

        $this->assertSame('new-summary', $row->summary);
        $this->assertSame(1, WorkflowTimelineEntry::query()->where('id', $projectionId)->count());
    }

    /**
     * Simulates the #438 race: two workers both observe no row, both INSERT.
     * The second INSERT collides on the primary key and raises a real
     * SQLSTATE 23000 unique-key error from the underlying driver. The helper
     * must retry, observe the row that the racing writer just persisted, and
     * fall through to UPDATE without bubbling the unique-key violation.
     */
    public function testRecoversWhenAConcurrentWriterInsertsBetweenSelectAndInsert(): void
    {
        $run = $this->seedRun();
        $projectionId = hash('sha256', $run->id . '|raced');

        // Pre-encode payload + recorded_at because the raw INSERT inside the
        // listener bypasses Eloquent casts.
        $raceRowAttributes = ['id' => $projectionId] + $this->timelineAttributes($run, 'raced', 'racing-writer');
        $raceRowAttributes['payload'] = json_encode($raceRowAttributes['payload']);
        $raceRowAttributes['recorded_at'] = $raceRowAttributes['recorded_at']->format('Y-m-d H:i:s.u');
        $raceFired = false;

        // saving() fires after firstOrNew has decided "no row" and is about to
        // INSERT. We use the hook to insert a competing row from a "different
        // worker," which makes the in-flight INSERT fail with a duplicate-key
        // error. The helper must catch that, retry updateOrCreate, find the
        // row this hook wrote, and UPDATE it.
        WorkflowTimelineEntry::saving(static function (WorkflowTimelineEntry $entry) use ($raceRowAttributes, &$raceFired): void {
            if ($entry->exists || $entry->id !== $raceRowAttributes['id'] || $raceFired) {
                return;
            }

            $raceFired = true;
            WorkflowTimelineEntry::query()->insert($raceRowAttributes);
        });

        try {
            $row = IdempotentProjectionUpsert::upsert(
                WorkflowTimelineEntry::class,
                ['id' => $projectionId],
                $this->timelineAttributes($run, 'raced', 'final-writer'),
            );
        } finally {
            WorkflowTimelineEntry::flushEventListeners();
        }

        $this->assertTrue($raceFired, 'Racing-writer hook must have run to reproduce the duplicate-key collision.');
        $this->assertSame('final-writer', $row->summary);
        $this->assertSame(1, WorkflowTimelineEntry::query()->where('id', $projectionId)->count());
    }

    public function testRethrowsNonUniqueQueryExceptions(): void
    {
        $run = $this->seedRun();

        $expected = new QueryException(
            'mysql',
            'select * from broken',
            [],
            new class('not a unique violation') extends PDOException {
                public function __construct(string $message)
                {
                    parent::__construct($message, 0);
                    $this->errorInfo = ['HY000', 1234, 'something else broke'];
                }
            },
        );

        WorkflowTimelineEntry::saving(static function () use ($expected): void {
            throw $expected;
        });

        try {
            $caught = null;
            try {
                IdempotentProjectionUpsert::upsert(
                    WorkflowTimelineEntry::class,
                    ['id' => hash('sha256', $run->id . '|rethrow')],
                    $this->timelineAttributes($run, 'rethrow', 'will-not-persist'),
                );
            } catch (QueryException $e) {
                $caught = $e;
            }

            $this->assertSame($expected, $caught);
        } finally {
            WorkflowTimelineEntry::flushEventListeners();
        }
    }

    private function seedRun(): WorkflowRun
    {
        $instance = WorkflowInstance::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_class' => 'App\\Fake\\Workflow',
            'workflow_type' => 'App\\Fake\\Workflow',
            'business_key' => null,
            'namespace' => 'default',
        ]);

        return WorkflowRun::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => $instance->workflow_class,
            'workflow_type' => $instance->workflow_type,
            'status' => 'pending',
            'connection' => null,
            'queue' => null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function timelineAttributes(WorkflowRun $run, string $historyEventId, string $summary): array
    {
        return [
            'workflow_run_id' => $run->id,
            'workflow_instance_id' => $run->workflow_instance_id,
            'history_event_id' => $historyEventId,
            'sequence' => 0,
            'type' => 'TestEvent',
            'kind' => 'workflow',
            'entry_kind' => 'point',
            'source_kind' => null,
            'source_id' => null,
            'summary' => $summary,
            'recorded_at' => now(),
            'payload' => [],
        ];
    }
}
