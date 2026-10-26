<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Fixtures\V2\TestRetainedSignalWritesWorkflow;
use Tests\TestCase;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunWait;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\RunWaitProjector;
use Workflow\V2\Support\RunWaitView;
use Workflow\V2\WorkflowStub;

final class RunWaitProjectionWritesTest extends TestCase
{
    public function testRealWorkflowProgressDoesNotRewriteResolvedWaits(): void
    {
        config()->set('workflows.v2.task_dispatch_mode', 'poll');
        Queue::fake();
        $this->travelTo(Carbon::parse('2026-10-26T12:00:00Z'));
        $workflow = WorkflowStub::make(TestRetainedSignalWritesWorkflow::class);
        $workflow->start(17);
        $run = $workflow->run();
        $this->assertInstanceOf(WorkflowRun::class, $run);
        $this->drainTasks($run);

        for ($step = 1; $step <= 17; $step++) {
            $before = $this->rows($run);
            $this->travel(10)
                ->seconds();
            $queries = $this->capture(function () use ($workflow, $step, $run): void {
                $workflow->signal('advance', $step);
                $this->drainTasks($run);
            });
            $after = $this->rows($run);
            $this->assertCount(min(17, $step + 1), $after);

            foreach ($before as $id => $row) {
                if ($row['status'] === 'resolved') {
                    $this->assertSame($row, $after[$id], 'Retained wait changed at signal ' . $step);
                }
            }

            if (in_array($step, [1, 8, 16, 17], true)) {
                // Signal acceptance and task completion update the selected
                // wait twice. The retained count must not increase the writes.
                $this->assertSame(2, $this->updateCount($queries));
                $snapshot = RunWaitProjector::snapshotForRun($run->fresh());
                $this->assertSame('workflow_run_waits', $snapshot['source']);
                $this->assertSame(
                    array_column(RunWaitView::forRun($run->fresh()), 'id'),
                    array_column($snapshot['waits'], 'id'),
                );
            }
        }

        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame(range(1, 17), $workflow->output());
    }

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

    public function testEquivalenceKeepsNullsAndConfiguredCastSemantics(): void
    {
        $row = new WorkflowRunWait();
        $row->setRawAttributes([
            'payload' => null,
        ], true);
        $row->payload = [];
        $this->assertTrue($row->isDirty('payload'));
        $row->payload = null;
        $this->assertFalse($row->isDirty('payload'));
        $row->mergeCasts([
            'payload' => 'string',
            'opened_at' => 'string',
        ]);
        $row->setRawAttributes([
            'payload' => '{"a":1,"b":2}',
            'opened_at' => '2026-10-26 12:00:00',
        ], true);
        $row->payload = '{"b":2,"a":1}';
        $row->opened_at = '2026-10-26 12:00:00.000000';
        $this->assertTrue($row->isDirty('payload'));
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
            $run->fresh()
                ->waits->pluck('wait_id')
                ->all()
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

    public function testLegacyPositionsRemainReadableBeforeAndAfterReprojection(): void
    {
        $run = $this->runFixture();
        $resolved = $this->wait('signal:resolved', 0, 'resolved');
        $open = $this->wait('signal:open', 1, 'open');
        $cancelled = $this->wait('signal:cancelled', 2, 'cancelled');
        $waits = [$open, $resolved, $cancelled];
        RunWaitProjector::project($run, $waits);

        // Published versions store the display rank, including status priority.
        foreach ($waits as $position => $wait) {
            WorkflowRunWait::query()->where('workflow_run_id', $run->id)
                ->where('wait_id', $wait['id'])
                ->update([
                    'position' => $position,
                ]);
        }
        $before = $this->rows($run);
        $queries = $this->capture(fn () => $this->assertSame(
            array_column($waits, 'id'),
            $run->fresh()
                ->waits->pluck('wait_id')
                ->all(),
        ));
        $this->assertSame(0, $this->updateCount($queries));
        $this->assertSame($before, $this->rows($run));

        RunWaitProjector::project($run->fresh(), $waits);
        $queries = $this->capture(static fn () => RunWaitProjector::project($run->fresh(), $waits));
        $this->assertSame(0, $this->updateCount($queries));
        $this->assertSame(array_column($waits, 'id'), $run->fresh()->waits->pluck('wait_id')->all());
    }

    public function testDisplayOrderingPreservesTimestampTiesAndMissingTimes(): void
    {
        $run = $this->runFixture();
        $a = $this->wait('signal:a', 0, 'resolved');
        $z = $this->wait('signal:z', 0, 'resolved');
        $a['opened_at'] = Carbon::parse('2026-10-26T10:00:00.000400Z');
        $z['opened_at'] = Carbon::parse('2026-10-26T10:00:00.000100Z');
        $this->assertSame($a['opened_at']->getTimestampMs(), $z['opened_at']->getTimestampMs());
        $missing = $this->wait('signal:missing', 1, 'resolved');
        $missing['opened_at'] = null;
        $open = $this->wait('signal:open', 2, 'open');
        RunWaitProjector::project($run, [$open, $a, $z, $missing]);

        // The canonical view uses millisecond precision, then the wait id.
        $this->assertSame(
            ['signal:open', 'signal:a', 'signal:z', 'signal:missing'],
            $run->fresh()
                ->waits->pluck('wait_id')
                ->all(),
        );
        $this->assertSame(
            ['signal:a', 'signal:z', 'signal:open', 'signal:missing'],
            WorkflowRunWait::query()->where('workflow_run_id', $run->id)
                ->orderBy('position')
                ->pluck('wait_id')
                ->all(),
        );
    }

    public function testConcurrentInsertFromAnotherConnectionRetainsWaitIdentityAndChanges(): void
    {
        $run = $this->runFixture();
        $waits = [$this->wait('signal:raced', 1, 'open')];
        $id = hash('sha256', $run->id . '|signal:raced');
        config()
            ->set('database.connections.wait-writer', $run->getConnection()->getConfig());
        $raced = false;
        WorkflowRunWait::saving(static function (WorkflowRunWait $row) use ($id, &$raced): void {
            if ($row->exists || $row->id !== $id || $raced) {
                return;
            }

            $raced = true;
            // The candidate has already observed absence. An independent
            // connection commits the competing insert before its save.
            DB::connection('wait-writer')->table($row->getTable())->insert(
                array_replace($row->getAttributes(), [
                    'summary' => 'Competing writer',
                    'created_at' => now()
                        ->format('Y-m-d H:i:s.u'),
                    'updated_at' => now()
                        ->format('Y-m-d H:i:s.u'),
                ]),
            );
        });

        try {
            $row = RunWaitProjector::project($run, $waits)[0];
        } finally {
            WorkflowRunWait::flushEventListeners();
            DB::purge('wait-writer');
        }

        $this->assertTrue($raced);
        $this->assertSame($id, $row->id);
        $this->assertSame($waits[0]['summary'], $row->fresh()->summary);
        $this->assertSame(1, WorkflowRunWait::query()->where('workflow_run_id', $run->id)->count());
        $queries = $this->capture(static fn () => RunWaitProjector::project($run->fresh(), $waits));
        $this->assertSame(0, $this->updateCount($queries));
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

    public function testNoopAndChangedWritesUseTheConfiguredStorageConnection(): void
    {
        $run = $this->runFixture();
        $storage = $run->getConnection();
        $default = DB::getDefaultConnection();
        $previousStorage = config('workflows.storage.connection');
        config()
            ->set('database.connections.wait-application', [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ]);
        config()
            ->set('workflows.storage.connection', $storage->getName());
        DB::setDefaultConnection('wait-application');

        try {
            $this->assertFalse(DB::connection()->getSchemaBuilder()->hasTable('workflow_run_waits'));
            $waits = [$this->wait('signal:stored', 1, 'resolved')];
            RunWaitProjector::project($run, $waits);
            $before = $this->rows($run);
            $queries = $this->capture(static fn () => RunWaitProjector::project($run->fresh(), $waits), $storage);
            $this->assertSame(0, $this->updateCount($queries));
            $this->assertSame($before, $this->rows($run));
            $storage->beginTransaction();
            try {
                $waits[0]['summary'] = 'Changed on storage connection';
                $queries = $this->capture(
                    static fn () => RunWaitProjector::project($run->fresh(), $waits),
                    $storage,
                );
                $this->assertSame(1, $this->updateCount($queries));
                $this->assertSame($waits[0]['summary'], $run->fresh()->waits->first()->summary);
            } finally {
                $storage->rollBack();
            }
            $this->assertSame($before, $this->rows($run));
        } finally {
            DB::setDefaultConnection($default);
            config()
                ->set('workflows.storage.connection', $previousStorage);
            DB::purge('wait-application');
        }
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

    private function drainTasks(WorkflowRun $run): void
    {
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $task = WorkflowTask::query()->where('workflow_run_id', $run->id)
                ->where('status', 'ready')
                ->first();
            if ($task === null) {
                return;
            }

            app()
                ->call([new RunWorkflowTask($task->id), 'handle']);
        }

        $this->fail('Workflow tasks exceeded the fixture drain bound.');
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
    private function capture(callable $operation, ?Connection $connection = null): array
    {
        $connection ??= DB::connection();
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
