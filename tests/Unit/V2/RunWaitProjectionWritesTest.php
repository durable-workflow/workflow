<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunWait;
use Workflow\V2\Support\RunWaitProjector;

final class RunWaitProjectionWritesTest extends TestCase
{
    public function testJsonObjectKeyOrderDoesNotDirtyAnUnchangedWait(): void
    {
        $row = new WorkflowRunWait();
        $row->setRawAttributes([
            'payload' => '{"kind":"signal","extra":{"a":1,"b":[1,2]},"id":"signal:1"}',
        ], true);
        $row->payload = [
            'id' => 'signal:1',
            'extra' => [
                'b' => [1, 2],
                'a' => 1,
            ],
            'kind' => 'signal',
        ];

        $this->assertFalse($row->isDirty('payload'));

        $row->payload = [
            'id' => 'signal:1',
            'extra' => [
                'b' => [2, 1],
                'a' => 1,
            ],
            'kind' => 'signal',
        ];
        $this->assertTrue($row->isDirty('payload'));
        $row->payload = [
            'id' => 'signal:1',
            'extra' => [
                'b' => [1, 2],
                'a' => '1',
            ],
            'kind' => 'signal',
        ];
        $this->assertTrue($row->isDirty('payload'));
        $row->payload = [
            'id' => 'signal:1',
            'extra' => [
                'b' => [1, 2],
                'a' => 1,
                'value' => null,
            ],
            'kind' => 'signal',
        ];
        $this->assertTrue($row->isDirty('payload'));
    }

    public function testTimestampPrecisionDoesNotDirtyTheSameInstant(): void
    {
        $row = new WorkflowRunWait();
        $row->setRawAttributes([
            'opened_at' => '2026-10-26 12:00:00',
            'deadline_at' => '2026-10-26 12:00:01.123456',
            'resolved_at' => null,
        ], true);
        $row->opened_at = Carbon::parse('2026-10-26T14:00:00+02:00');
        $row->deadline_at = Carbon::parse('2026-10-26T12:00:01.123456Z');
        $row->resolved_at = null;

        $this->assertFalse($row->isDirty());

        $row->deadline_at = Carbon::parse('2026-10-26T12:00:01.123457Z');
        $this->assertTrue($row->isDirty('deadline_at'));
        $row->resolved_at = Carbon::parse('2026-10-26T12:00:02Z');
        $this->assertTrue($row->isDirty('resolved_at'));
        $row->opened_at = null;
        $this->assertTrue($row->isDirty('opened_at'));
    }

    public function testUnchangedRetainedRowsIssueNoUpdatesAfterStorageReordersJson(): void
    {
        $this->travelTo(Carbon::parse('2026-10-26T12:00:00Z'));
        $run = $this->runFixture();
        $waits = [];
        for ($index = 0; $index < 64; $index++) {
            $waits[] = $this->wait('signal:' . $index, $index, 'resolved');
        }
        RunWaitProjector::project($run, $waits);
        foreach (WorkflowRunWait::query()->where('workflow_run_id', $run->id)->get() as $row) {
            $row->getConnection()
                ->table($row->getTable())
                ->where('id', $row->id)
                ->update([
                    'payload' => json_encode(array_reverse($row->payload, true), JSON_THROW_ON_ERROR),
                ]);
        }
        $before = $this->rows($run);
        $this->travel(10)
            ->seconds();

        $queries = $this->capture(static fn () => RunWaitProjector::project($run->fresh(), $waits));

        $this->assertSame(0, $this->updateCount($queries));
        $this->assertSame($before, $this->rows($run));

        $waits[32]['summary'] = 'A genuine changed wait';
        $queries = $this->capture(static fn () => RunWaitProjector::project($run->fresh(), $waits));
        $this->assertSame(1, $this->updateCount($queries));
        $this->assertSame(
            'A genuine changed wait',
            $this->rows($run)[hash('sha256', $run->id . '|signal:32')]['summary']
        );
    }

    public function testStatusChangesPreserveDisplayOrderWithoutRepositioningRetainedRows(): void
    {
        $this->travelTo(Carbon::parse('2026-10-26T12:00:00Z'));
        $run = $this->runFixture();
        $old = $this->wait('signal:old', 0, 'resolved');
        $current = $this->wait('signal:current', 1, 'open');
        $cancelled = $this->wait('signal:cancelled', 2, 'cancelled');
        RunWaitProjector::project($run, [$current, $old, $cancelled]);
        $before = $this->rows($run);
        $this->assertSame(
            ['signal:current', 'signal:old', 'signal:cancelled'],
            $run->fresh()->waits->pluck('wait_id')->all()
        );
        $this->travel(10)
            ->seconds();
        $current['status'] = 'resolved';
        $current['source_status'] = 'received';
        $current['resolved_at'] = now();
        $next = $this->wait('signal:next', 3, 'open');

        $queries = $this->capture(
            static fn () => RunWaitProjector::project($run->fresh(), [$next, $old, $current, $cancelled])
        );

        $this->assertSame(1, $this->updateCount($queries));
        $after = $this->rows($run);
        foreach (['signal:old', 'signal:cancelled'] as $id) {
            $key = hash('sha256', $run->id . '|' . $id);
            $this->assertSame($before[$key], $after[$key]);
        }
        $this->assertSame(
            ['signal:next', 'signal:old', 'signal:current', 'signal:cancelled'],
            $run->fresh()
                ->waits->pluck('wait_id')
                ->all(),
        );
    }

    public function testProjectionStillRunsModelHooksAndPersistsTheirChanges(): void
    {
        $run = $this->runFixture();
        $waits = [$this->wait('signal:hook', 1, 'resolved')];
        RunWaitProjector::project($run, $waits);
        $calls = 0;
        WorkflowRunWait::saving(static function (WorkflowRunWait $row) use (&$calls): void {
            $calls++;
            $row->summary = 'Model hook changed this field';
        });

        try {
            $queries = $this->capture(static fn () => RunWaitProjector::project($run->fresh(), $waits));
        } finally {
            WorkflowRunWait::flushEventListeners();
        }

        $this->assertSame(1, $calls);
        $this->assertSame(1, $this->updateCount($queries));
        $this->assertSame('Model hook changed this field', $run->fresh()->waits->first()->summary);
    }

    public function testMissingRowsAreRecreatedAndRealChangesRollBack(): void
    {
        $run = $this->runFixture();
        $waits = [$this->wait('signal:one', 0, 'resolved'), $this->wait('signal:two', 1, 'open')];
        RunWaitProjector::project($run, $waits);
        $missing = hash('sha256', $run->id . '|signal:one');
        WorkflowRunWait::query()->whereKey($missing)->delete();
        RunWaitProjector::project($run->fresh(), $waits);
        $this->assertSame(2, $run->fresh()->waits()->count());
        $this->assertNotNull(WorkflowRunWait::query()->find($missing));
        $before = $this->rows($run);
        $connection = $run->getConnection();
        $connection->beginTransaction();

        try {
            $waits[1]['summary'] = 'Rolled back transition';
            RunWaitProjector::project($run->fresh(), $waits);
        } finally {
            $connection->rollBack();
        }

        $this->assertSame($before, $this->rows($run));
    }

    private function runFixture(): WorkflowRun
    {
        $instance = WorkflowInstance::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_class' => 'App\\WaitWriteFixture',
            'workflow_type' => 'App\\WaitWriteFixture',
            'namespace' => 'default',
        ]);

        return WorkflowRun::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => $instance->workflow_class,
            'workflow_type' => $instance->workflow_type,
            'status' => 'running',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function wait(string $id, int $sequence, string $status): array
    {
        return [
            'id' => $id,
            'kind' => 'signal',
            'sequence' => $sequence,
            'status' => $status,
            'source_status' => $status === 'open' ? 'waiting' : 'received',
            'summary' => 'Signal wait ' . $id,
            'opened_at' => Carbon::parse('2026-10-26T10:00:00Z')->addSeconds($sequence),
            'resolved_at' => $status === 'open' ? null : Carbon::parse('2026-10-26T11:00:00Z')->addSeconds($sequence),
            'task_backed' => false,
            'external_only' => true,
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function rows(WorkflowRun $run): array
    {
        return $run->getConnection()
            ->table('workflow_run_waits')
            ->where('workflow_run_id', $run->id)
            ->orderBy('id')
            ->get()
            ->mapWithKeys(static fn ($row): array => [
                $row->id => (array) $row,
            ])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function capture(callable $operation): array
    {
        $connection = DB::connection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();
        try {
            $operation();

            return $connection->getQueryLog();
        } finally {
            $connection->disableQueryLog();
        }
    }

    /**
     * @param list<array<string, mixed>> $queries
     */
    private function updateCount(array $queries): int
    {
        return count(array_filter($queries, static fn (array $query): bool =>
            str_starts_with(strtolower(ltrim($query['query'])), 'update ')
            && str_contains($query['query'], 'workflow_run_waits')));
    }
}
