<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Database\Eloquent\Casts\Json;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use LogicException;
use Tests\Fixtures\V2\TestVersionWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\VersionUsageQuery;
use Workflow\V2\Support\WorkflowDefinition;
use Workflow\V2\Support\WorkflowDefinitionFingerprint;
use Workflow\V2\WorkflowStub;

final class V2VersionUsageQueryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()
            ->set('app.key', 'version-usage-feature-signing-key');
    }

    public function testCountsIncludeOldNewAndNoMarkerBeforeStateFilteringOrPagination(): void
    {
        $this->createRun(1, 1);
        $this->createRun(2, 2);
        $this->createRun(3, null);
        $this->createRun(4, -1);
        $query = new VersionUsageQuery();
        $first = $query->page('orders', 'shipment', 'shipping', 2, 1);
        $this->assertSame([
            'total' => 4,
            'legacy' => 2,
            'current' => 1,
            'unknown' => 1,
        ], $first['counts']);
        $this->assertSame('legacy', $first['runs'][0]['state']);
        $this->assertTrue($first['has_more']);
        $current = $query->page('orders', 'shipment', 'shipping', 2, 1, state: 'current');
        $this->assertSame($first['counts'], $current['counts']);
        $this->assertSame(2, $current['runs'][0]['recorded_version']);
        $this->assertFalse($current['has_more']);
        $unknown = $query->page('orders', 'shipment', 'shipping', 2, state: 'unknown');
        $this->assertSame('marker_absent', $unknown['runs'][0]['unknown_reason']);
        $this->assertNull($unknown['runs'][0]['recorded_version']);
    }

    public function testActualRecordedMarkerWithTaskSnapshotClassifiesItsDecision(): void
    {
        config()->set('queue.default', 'database');
        Queue::fake();
        $workflow = WorkflowStub::make(TestVersionWorkflow::class, 'actual-version-usage', 'orders');
        $workflow->start();
        $run = WorkflowRun::query()->findOrFail($workflow->runId());
        $this->assertSame(['StartAccepted', 'WorkflowStarted'], $run->historyEvents->pluck('event_type')->map(
            static fn (HistoryEventType $type): string => $type->value
        )->all());
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'namespace' => $run->namespace,
            'task_type' => 'workflow',
            'status' => 'ready',
            'available_at' => now(),
            'payload' => [],
        ]);
        $event = WorkflowHistoryEvent::record($run, HistoryEventType::VersionMarkerRecorded, $this->marker(2), $task);
        $this->assertSame($task->id, $event->payload['task']['id']);
        $page = (new VersionUsageQuery())->page('orders', 'test-version-workflow', 'shipping', 2);
        $this->assertSame('current', $page['runs'][0]['state']);
        $this->assertSame(2, $page['runs'][0]['recorded_version']);
        $this->assertNull($page['runs'][0]['unknown_reason']);
    }

    public function testIncompleteOrRepeatedStartPrefixesStayUnavailable(): void
    {
        $missingStarted = $this->createRun(1, 2);
        DB::table((new WorkflowHistoryEvent())->getTable())->where('workflow_run_id', $missingStarted->id)
            ->where('sequence', 1)
            ->update([
                'event_type' => 'StartAccepted',
            ]);
        $repeated = $this->createRun(2, 2);
        $this->event($repeated, 'WorkflowStarted', []);
        $page = (new VersionUsageQuery())->page('orders', 'shipment', 'shipping', 2);
        $this->assertSame(2, $page['counts']['unknown']);
        $this->assertSame(
            ['history_unavailable', 'history_unavailable'],
            array_column($page['runs'], 'unknown_reason')
        );
    }

    public function testPagingRequiresTheConfiguredApplicationSigningKey(): void
    {
        config()->set('app.key', '');
        $this->expectException(LogicException::class);
        (new VersionUsageQuery())->page('orders', 'shipment', 'shipping', 2);
    }

    public function testNamespacesWorkflowTypesAndChangeIdsUseExactIdentities(): void
    {
        foreach ([null, 'default', 'orders', 'ORDERS', 'orders '] as $index => $namespace) {
            $this->createRun($index + 1, 1, $namespace);
        }
        $this->createRun(6, 2, 'orders', 'Shipment');
        $this->createRun(7, 2, 'orders', 'shipment ');
        $query = new VersionUsageQuery();
        foreach ([null, 'default', 'orders', 'ORDERS', 'orders '] as $namespace) {
            $page = $query->page($namespace, 'shipment', 'shipping', 2);
            $this->assertSame(1, $page['counts']['total']);
            $this->assertSame(1, $page['counts']['legacy']);
            $this->assertSame($namespace, $page['namespace']);
        }
        $this->assertSame(1, $query->page('orders', 'Shipment', 'shipping', 2)['counts']['current']);
        $this->assertSame(1, $query->page('orders', 'shipment', 'Shipping', 2)['counts']['unknown']);
    }

    public function testClosedRunsAreExcludedButOpenArchivedRunsAreStillCounted(): void
    {
        $this->createRun(1, 1)
            ->update([
                'status' => 'completed',
            ]);
        $this->createRun(2, 1)
            ->update([
                'status' => 'failed',
            ]);
        $this->createRun(3, 1)
            ->update([
                'status' => 'cancelled',
            ]);
        $this->createRun(4, 1)
            ->update([
                'status' => 'terminated',
            ]);
        $this->createRun(5, null)
            ->update([
                'archived_at' => now(),
            ]);
        $page = (new VersionUsageQuery())->page('orders', 'shipment', 'shipping', 2);
        $this->assertSame([
            'total' => 1,
            'legacy' => 0,
            'current' => 0,
            'unknown' => 1,
        ], $page['counts']);
    }

    public function testPrunedMissingGappedAndUnsupportedHistoriesStayUnknownEvenWithNewMarkers(): void
    {
        $pruned = $this->createRun(1, 2);
        $pruned->update([
            'details_pruned_at' => now(),
        ]);
        $missing = $this->createRun(2, 2);
        WorkflowHistoryEvent::query()->where('workflow_run_id', $missing->id)->delete();
        $gap = $this->createRun(3, 2);
        WorkflowHistoryEvent::query()->where('workflow_run_id', $gap->id)->where('sequence', 1)->delete();
        $future = $this->createRun(4, 2);
        $this->event($future, 'FutureHistoryEvent', []);
        $caseVariant = $this->createRun(5, 2);
        DB::table((new WorkflowHistoryEvent())->getTable())->where('workflow_run_id', $caseVariant->id)
            ->where('sequence', 2)
            ->update([
                'event_type' => 'versionmarkerrecorded',
            ]);
        $page = (new VersionUsageQuery())->page('orders', 'shipment', 'shipping', 2);
        $this->assertSame([
            'total' => 5,
            'legacy' => 0,
            'current' => 0,
            'unknown' => 5,
        ], $page['counts']);
        $this->assertSame(
            ['history_unavailable'],
            array_values(array_unique(array_column($page['runs'], 'unknown_reason')))
        );
    }

    public function testNoncanonicalStatusSpellingsCannotEnterTheOpenRunCohort(): void
    {
        $this->createRun(1, 1);
        foreach ([
            2 => 'WAITING',
            3 => 'waiting ',
        ] as $number => $status) {
            $run = $this->createRun($number, 2);
            DB::table((new WorkflowRun())->getTable())->where('id', $run->id)->update([
                'status' => $status,
            ]);
        }
        $page = (new VersionUsageQuery())->page('orders', 'shipment', 'shipping', 2);
        $this->assertSame([
            'total' => 1,
            'legacy' => 1,
            'current' => 0,
            'unknown' => 0,
        ], $page['counts']);
        $this->assertSame(['waiting'], array_column($page['runs'], 'status'));
    }

    public function testUnavailableOwnershipAndUnrecognizedImportProvenanceStayInUnknownCounts(): void
    {
        $missing = $this->createRun(1, 2);
        WorkflowInstance::query()->whereKey($missing->workflow_instance_id)->delete();
        $foreign = $this->createRun(2, 2);
        WorkflowInstance::query()->whereKey($foreign->workflow_instance_id)->update([
            'namespace' => 'private',
        ]);
        $imported = $this->createRun(3, 2);
        $imported->update([
            'import_source' => 'unsupported-source',
        ]);
        $page = (new VersionUsageQuery())->page('orders', 'shipment', 'shipping', 2);
        $this->assertSame([
            'total' => 3,
            'legacy' => 0,
            'current' => 0,
            'unknown' => 3,
        ], $page['counts']);
        $this->assertSame(
            ['history_unavailable'],
            array_values(array_unique(array_column($page['runs'], 'unknown_reason')))
        );
    }

    public function testOversizedMarkerHistoryIsUnknownWithoutReadingAPartialDecision(): void
    {
        $run = $this->createRun(1, null);
        $events = [];
        for ($sequence = 1; $sequence <= 1001; ++$sequence) {
            $events[] = [
                'id' => (string) \Illuminate\Support\Str::ulid(),
                'workflow_run_id' => $run->id,
                'sequence' => $sequence + 1,
                'event_type' => 'VersionMarkerRecorded',
                'payload' => json_encode($this->marker(2, $sequence), JSON_THROW_ON_ERROR),
                'recorded_at' => now(),
            ];
        }
        DB::table((new WorkflowHistoryEvent())->getTable())->insert($events);
        $run->update([
            'last_history_sequence' => 1002,
        ]);
        $page = (new VersionUsageQuery())->page('orders', 'shipment', 'shipping', 2);
        $this->assertSame([
            'total' => 1,
            'legacy' => 0,
            'current' => 0,
            'unknown' => 1,
        ], $page['counts']);
        $this->assertSame('history_unavailable', $page['runs'][0]['unknown_reason']);
    }

    public function testConflictingAndMalformedMarkersNeverBecomeCurrent(): void
    {
        $conflict = $this->createRun(1, 2);
        $this->event($conflict, HistoryEventType::VersionMarkerRecorded->value, $this->marker(1, 2));
        $malformed = $this->createRun(2, 2);
        $this->event($malformed, HistoryEventType::VersionMarkerRecorded->value, array_replace($this->marker(2, 2), [
            'version' => '2',
        ]));
        $page = (new VersionUsageQuery())->page('orders', 'shipment', 'shipping', 2);
        $this->assertSame(2, $page['counts']['unknown']);
        $this->assertSame(['history_invalid', 'history_invalid'], array_column($page['runs'], 'unknown_reason'));
    }

    public function testMalformedReusedMarkersRemainUnknownThroughTheStoredQuery(): void
    {
        foreach ([[
            'reused_from_run_id' => [],
        ], [
            'reused_recorded_at' => '2026-11-14T01:02:03Z',
        ],
            [
                'reused_from_run_id' => '',
                'reused_recorded_at' => '2026-11-14T01:02:03Z',
            ],
            [
                'reused_from_run_id' => 'original',
                'reused_recorded_at' => '',
            ],
            [
                'reused_from_run_id' => 'original',
                'reused_recorded_at' => '2026-02-30T01:02:03Z',
            ],
        ] as $index => $reuse) {
            $run = $this->createRun($index + 1, null);
            $this->event($run, 'VersionMarkerRecorded', $this->marker(2) + $reuse);
        }
        $page = (new VersionUsageQuery())->page('orders', 'shipment', 'shipping', 2);
        $this->assertSame([
            'total' => 5,
            'legacy' => 0,
            'current' => 0,
            'unknown' => 5,
        ], $page['counts']);
        $this->assertSame(
            ['history_invalid'],
            array_values(array_unique(array_column($page['runs'], 'unknown_reason')))
        );
    }

    public function testCaseVariantHistoryCannotContaminateOrCompleteAnotherRunsPrefix(): void
    {
        foreach ([
            1 => 'Case-A',
            2 => 'Gap-A',
        ] as $number => $id) {
            $run = $this->createRun($number, 2);
            DB::table((new WorkflowHistoryEvent())->getTable())->where('workflow_run_id', $run->id)->update([
                'workflow_run_id' => $id,
            ]);
            DB::table((new WorkflowRun())->getTable())->where('id', $run->id)->update([
                'id' => $id,
                'last_history_sequence' => $number === 2 ? 3 : 2,
            ]);
            DB::table((new WorkflowHistoryEvent())->getTable())->insert([
                'id' => (string) \Illuminate\Support\Str::ulid(),
                'workflow_run_id' => strtolower($id),
                'sequence' => 3,
                'event_type' => 'VersionMarkerRecorded',
                'payload' => json_encode($this->marker(1, 2), JSON_THROW_ON_ERROR),
                'recorded_at' => now(),
            ]);
        }
        $page = (new VersionUsageQuery())->page('orders', 'shipment', 'shipping', 2);
        $this->assertSame([
            'total' => 2,
            'legacy' => 0,
            'current' => 1,
            'unknown' => 1,
        ], $page['counts']);
        $this->assertSame(['Case-A', 'Gap-A'], array_column($page['runs'], 'run_id'));
        $this->assertSame('current', $page['runs'][0]['state']);
        $this->assertSame('history_unavailable', $page['runs'][1]['unknown_reason']);
    }

    public function testValidMarkerEvidenceIsCompleteAcrossMarkerReadBatches(): void
    {
        $run = $this->createRun(1, null);
        $events = [];
        for ($sequence = 1; $sequence <= 251; ++$sequence) {
            $events[] = [
                'id' => (string) \Illuminate\Support\Str::ulid(),
                'workflow_run_id' => $run->id,
                'sequence' => $sequence + 1,
                'event_type' => 'VersionMarkerRecorded',
                'payload' => json_encode($this->marker(2, $sequence), JSON_THROW_ON_ERROR),
                'recorded_at' => now(),
            ];
        }
        DB::table((new WorkflowHistoryEvent())->getTable())->insert($events);
        $run->update([
            'last_history_sequence' => 252,
        ]);
        $page = (new VersionUsageQuery())->page('orders', 'shipment', 'shipping', 2);
        $this->assertSame([
            'total' => 1,
            'legacy' => 0,
            'current' => 1,
            'unknown' => 0,
        ], $page['counts']);
    }

    public function testPagingHasAStableUpperBoundAndCannotCrossTenantOrFilterScope(): void
    {
        $one = $this->createRun(1, 1);
        $two = $this->createRun(2, 2);
        $three = $this->createRun(3, null);
        $query = new VersionUsageQuery();
        $first = $query->page('orders', 'shipment', 'shipping', 2, 1);
        $this->createRun(4, 1);
        $two->update([
            'status' => 'completed',
        ]);
        $next = $query->page('orders', 'shipment', 'shipping', 2, 1, $first['next_cursor']);
        $this->assertSame([$three->id], array_column($next['runs'], 'run_id'));
        $this->assertSame($first['through_run_id'], $next['through_run_id']);
        $this->assertSame(2, $next['counts']['total']);
        $this->assertFalse($next['has_more']);
        $this->assertNotContains($one->id, array_column($next['runs'], 'run_id'));
        foreach ([['ORDERS', 'shipment', 'shipping', 2, null],
            ['orders', 'shipment', 'shipping', 3, null],
            ['orders', 'shipment', 'shipping', 2, 'legacy'],
        ] as [$namespace, $type, $change, $minimum, $state]) {
            try {
                $query->page($namespace, $type, $change, $minimum, 1, $first['next_cursor'], $state);
                $this->fail('A cursor crossed its query identity.');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    public function testRunIdKeysetsUseByteOrderOnEveryStorageCollation(): void
    {
        foreach ([[1, 'b', 1], [2, 'C', 2]] as [$number, $id, $version]) {
            $run = $this->createRun($number, $version);
            DB::table((new WorkflowHistoryEvent())->getTable())->where('workflow_run_id', $run->id)
                ->update([
                    'workflow_run_id' => $id,
                ]);
            $run->update([
                'id' => $id,
            ]);
        }
        $query = new VersionUsageQuery();
        $first = $query->page('orders', 'shipment', 'shipping', 2, 1);
        $this->assertSame(['C'], array_column($first['runs'], 'run_id'));
        $this->assertSame('b', $first['through_run_id']);
        $second = $query->page('orders', 'shipment', 'shipping', 2, 1, $first['next_cursor']);
        $this->assertSame(['b'], array_column($second['runs'], 'run_id'));
        $this->assertFalse($second['has_more']);
        $this->assertSame(2, $second['counts']['total']);
    }

    public function testCurrentDefinitionFingerprintDoesNotReplaceAnUnreachedMarker(): void
    {
        $run = $this->createRun(1, null, 'orders', 'test-version-workflow');
        $run->update([
            'workflow_class' => TestVersionWorkflow::class,
        ]);
        DB::table((new WorkflowHistoryEvent())->getTable())->where('workflow_run_id', $run->id)
            ->where('sequence', 1)
            ->update([
                'payload' => json_encode([
                    'workflow_definition_fingerprint' => WorkflowDefinition::fingerprint(TestVersionWorkflow::class),
                ], JSON_THROW_ON_ERROR),
            ]);
        $this->assertTrue(WorkflowDefinitionFingerprint::matchesCurrent($run));
        $page = (new VersionUsageQuery())->page('orders', $run->workflow_type, 'shipping', 2);
        $this->assertSame([
            'total' => 1,
            'legacy' => 0,
            'current' => 0,
            'unknown' => 1,
        ], $page['counts']);
        $this->assertSame('marker_absent', $page['runs'][0]['unknown_reason']);
    }

    public function testPruningDuringAnObservationCannotLeaveAnOptimisticCurrentResult(): void
    {
        $run = $this->createRun(1, 2);
        $pruned = false;
        DB::listen(static function (QueryExecuted $event) use ($run, &$pruned): void {
            if (! $pruned && str_contains($event->sql, 'COUNT(*) AS event_count')) {
                $pruned = true;
                WorkflowRun::query()->whereKey($run->id)->update([
                    'details_pruned_at' => now(),
                ]);
            }
        });
        $page = (new VersionUsageQuery())->page('orders', 'shipment', 'shipping', 2);
        $this->assertTrue($pruned);
        $this->assertSame([
            'total' => 1,
            'legacy' => 0,
            'current' => 0,
            'unknown' => 1,
        ], $page['counts']);
        $this->assertSame('history_unavailable', $page['runs'][0]['unknown_reason']);
    }

    public function testReadDoesNotReplayDecodeApplicationPayloadsOrWriteProjections(): void
    {
        $this->createRun(1, 2);
        $before = WorkflowHistoryEvent::query()->count();
        $statements = [];
        DB::listen(static function (QueryExecuted $event) use (&$statements): void {
            $statements[] = $event->sql;
        });
        Json::decodeUsing(static fn () => throw new LogicException('Application decoder must not run.'));
        try {
            $page = (new VersionUsageQuery())->page('orders', 'shipment', 'shipping', 2);
            $this->assertSame(1, $page['counts']['current']);
        } finally {
            Json::decodeUsing(null);
        }
        $this->assertSame($before, WorkflowHistoryEvent::query()->count());
        $this->assertNotEmpty($statements);
        foreach ($statements as $statement) {
            $this->assertStringStartsWith('select', strtolower(ltrim($statement)));
        }
    }

    public function testEmptyCohortIsExplicitAndInvalidRequestsAreRefused(): void
    {
        $query = new VersionUsageQuery();
        $page = $query->page(null, 'shipment', 'shipping', 2);
        $this->assertSame([
            'total' => 0,
            'legacy' => 0,
            'current' => 0,
            'unknown' => 0,
        ], $page['counts']);
        $this->assertSame([], $page['runs']);
        $this->assertNull($page['through_run_id']);
        $this->assertNull($page['next_cursor']);
        $this->assertArrayNotHasKey('safe_to_remove', $page);
        $this->expectException(InvalidArgumentException::class);
        $query->page('orders', 'shipment', 'shipping', 2147483648);
    }

    public function testCountsAndPagesTraverseMoreThanOneStorageBatch(): void
    {
        for ($number = 1; $number <= 205; ++$number) {
            $this->createRun($number, $number % 3 === 0 ? null : ($number % 2 === 0 ? 2 : 1));
        }
        $query = new VersionUsageQuery();
        $ids = [];
        $cursor = null;
        do {
            $page = $query->page('orders', 'shipment', 'shipping', 2, 70, $cursor);
            $this->assertSame(205, $page['counts']['total']);
            $ids = [...$ids, ...array_column($page['runs'], 'run_id')];
            $cursor = $page['next_cursor'];
        } while ($cursor !== null);
        $this->assertCount(205, $ids);
        $this->assertCount(205, array_unique($ids));
        $sorted = $ids;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $ids);
    }

    public function testConfiguredModelsConnectionAndPrefixAreUsedWithoutReadingDefaultTables(): void
    {
        $connection = 'version_usage_fixture';
        config()
            ->set('database.connections.' . $connection, DB::connection()->getConfig() + [
                'prefix' => '',
            ]);
        config()
            ->set('database.connections.' . $connection . '.prefix', 'usage_fixture_');
        $database = DB::connection($connection);
        $schema = $database->getSchemaBuilder();
        $schema->create('runs', static function (Blueprint $table): void {
            $table->string('id')
                ->primary();
            $table->string('workflow_instance_id');
            $table->string('namespace')
                ->nullable();
            $table->string('workflow_type');
            $table->string('status');
            $table->timestamp('details_pruned_at')
                ->nullable();
            $table->integer('last_history_sequence');
            $table->string('import_source')
                ->nullable();
        });
        $schema->create('instances', static function (Blueprint $table): void {
            $table->string('id')
                ->primary();
            $table->string('namespace')
                ->nullable();
        });
        $schema->create('history', static function (Blueprint $table): void {
            $table->string('id')
                ->primary();
            $table->string('workflow_run_id');
            $table->integer('sequence');
            $table->string('event_type');
            $table->text('payload');
        });
        try {
            $this->createRun(1, 1);
            config()
                ->set('workflows.v2.run_model', VersionUsageCustomRun::class);
            config()
                ->set('workflows.v2.history_event_model', VersionUsageCustomHistory::class);
            config()
                ->set('workflows.v2.instance_model', VersionUsageCustomInstance::class);
            $database->table('instances')
                ->insert([
                    'id' => 'custom-instance',
                    'namespace' => 'orders',
                ]);
            $database->table('runs')
                ->insert([
                    'id' => 'custom-1',
                    'workflow_instance_id' => 'custom-instance',
                    'namespace' => 'orders',
                    'workflow_type' => 'shipment',
                    'status' => 'waiting',
                    'last_history_sequence' => 2,
                ]);
            $database->table('history')
                ->insert([
                    [
                        'id' => 'event-1',
                        'workflow_run_id' => 'custom-1',
                        'sequence' => 1,
                        'event_type' => 'WorkflowStarted',
                        'payload' => '{}',
                    ],
                    [
                        'id' => 'event-2',
                        'workflow_run_id' => 'custom-1',
                        'sequence' => 2,
                        'event_type' => 'VersionMarkerRecorded',
                        'payload' => json_encode($this->marker(2), JSON_THROW_ON_ERROR),
                    ],
                ]);
            $page = (new VersionUsageQuery())->page('orders', 'shipment', 'shipping', 2);
            $this->assertSame([
                'total' => 1,
                'legacy' => 0,
                'current' => 1,
                'unknown' => 0,
            ], $page['counts']);
            $this->assertSame('custom-1', $page['runs'][0]['run_id']);
        } finally {
            config()->set('workflows.v2.run_model', WorkflowRun::class);
            config()
                ->set('workflows.v2.history_event_model', WorkflowHistoryEvent::class);
            config()
                ->set('workflows.v2.instance_model', WorkflowInstance::class);
            $schema->dropIfExists('history');
            $schema->dropIfExists('instances');
            $schema->dropIfExists('runs');
            DB::purge($connection);
        }
    }

    private function createRun(
        int $number,
        ?int $version,
        ?string $namespace = 'orders',
        string $type = 'shipment'
    ): WorkflowRun {
        $id = str_pad((string) $number, 26, '0', STR_PAD_LEFT);
        $instance = WorkflowInstance::query()->create([
            'id' => 'usage-instance-' . $number,
            'namespace' => $namespace,
            'workflow_type' => $type,
            'workflow_class' => 'NotLoadedByVersionUsage',
        ]);
        $run = WorkflowRun::query()->create([
            'id' => $id,
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'namespace' => $namespace,
            'workflow_type' => $type,
            'workflow_class' => 'NotLoadedByVersionUsage',
            'status' => 'waiting',
        ]);
        $this->event($run, HistoryEventType::WorkflowStarted->value, []);
        if ($version !== null) {
            $this->event($run, HistoryEventType::VersionMarkerRecorded->value, $this->marker($version));
        }
        return $run;
    }

    private function event(WorkflowRun $run, string $kind, array $payload): void
    {
        $sequence = (int) $run->last_history_sequence + 1;
        DB::table((new WorkflowHistoryEvent())->getTable())->insert([
            'id' => (string) \Illuminate\Support\Str::ulid(),
            'workflow_run_id' => $run->id,
            'sequence' => $sequence,
            'event_type' => $kind,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'recorded_at' => now(),
        ]);
        $run->update([
            'last_history_sequence' => $sequence,
        ]);
    }

    private function marker(int $version, int $sequence = 1): array
    {
        return [
            'sequence' => $sequence,
            'change_id' => 'shipping',
            'version' => $version,
            'min_supported' => -1,
            'max_supported' => 3,
        ];
    }
}

final class VersionUsageCustomRun extends WorkflowRun
{
    protected $table = 'runs';

    protected $connection = 'version_usage_fixture';
}

final class VersionUsageCustomHistory extends WorkflowHistoryEvent
{
    protected $table = 'history';
}

final class VersionUsageCustomInstance extends WorkflowInstance
{
    protected $table = 'instances';

    protected $connection = 'version_usage_fixture';

    public function getForeignKey(): string
    {
        return 'workflow_instance_id';
    }
}
