<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use Throwable;
use Workflow\Serializers\CodecRegistry;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\ChildRunHistory;
use Workflow\V2\Support\EmbeddedV2HistoryImport;
use Workflow\V2\Support\EmbeddedV2ImportContract;
use Workflow\V2\Support\HistoryExport;
use Workflow\V2\Support\ImportedLineageReferences;
use Workflow\V2\Support\RunDetailView;
use Workflow\V2\Support\RunLineageProjector;
use Workflow\V2\Support\RunLineageView;
use Workflow\V2\Support\WorkflowExecutor;
use Workflow\V2\WorkflowStub;

final class EmbeddedV2HistoryImportLineageTest extends TestCase
{
    private const TABLES = [
        'workflow_run_summaries', 'workflow_run_waits', 'workflow_run_timeline_entries',
        'workflow_run_timer_entries', 'workflow_run_lineage_entries', 'workflow_search_attributes',
        'workflow_memos', 'workflow_history_events', 'workflow_tasks', 'activity_attempts',
        'activity_executions', 'workflow_run_timers', 'workflow_failures', 'workflow_links',
        'workflow_signal_records', 'workflow_updates', 'workflow_commands', 'workflow_runs', 'workflow_instances',
    ];

    #[DataProvider('foreignEndpoints')]
    public function testImportRefusesForeignLineageWithoutChangingEitherNamespace(
        string $selected,
        ?string $foreign,
        bool $dryRun
    ): void {
        $bundles = $this->sourcePair();
        $this->clearState();
        $other = $selected === 'parent' ? 'child' : 'parent';
        $this->assertSame('imported', EmbeddedV2HistoryImport::import($bundles[$other], [
            'namespace' => $foreign,
        ])['status']);
        $before = $this->snapshot();

        $report = EmbeddedV2HistoryImport::import($bundles[$selected], [
            'namespace' => 'tenant-a',
            'dry_run' => $dryRun,
        ]);

        $this->assertSame('rejected', $report['status']);
        $this->assertContains(
            'references.lineage_namespace_conflict',
            array_column($report['eligibility']['errors'], 'rule')
        );
        $this->assertSame(0, array_sum($report['rows']));
        $this->assertSame($before, $this->snapshot());
    }

    public static function foreignEndpoints(): iterable
    {
        foreach (['parent', 'child'] as $selected) {
            foreach (['tenant-b', null] as $foreign) {
                foreach ([false, true] as $dryRun) {
                    yield $selected . '/' . ($foreign ?? 'null') . ($dryRun ? '/preview' : '/write') => [
                        $selected,
                        $foreign,
                        $dryRun,
                    ];
                }
            }
        }
    }

    #[DataProvider('importOrders')]
    public function testReciprocalBundlesMaterializeOneScopedEdgeInEitherOrder(string $first, ?string $namespace): void
    {
        $bundles = $this->sourcePair();
        $this->clearState();
        $second = $first === 'parent' ? 'child' : 'parent';

        $initial = EmbeddedV2HistoryImport::import($bundles[$first], [
            'namespace' => $namespace,
        ]);

        $this->assertSame('imported', $initial['status']);
        $this->assertSame(0, $initial['rows']['workflow_links']);
        $this->assertSame(0, WorkflowLink::query()->count());
        $this->assertSame('imported', EmbeddedV2HistoryImport::import($bundles[$second], [
            'namespace' => $namespace,
        ])['status']);
        $link = WorkflowLink::query()->sole();
        $this->assertSame($bundles['parent']['workflow']['run_id'], $link->parent_workflow_run_id);
        $this->assertSame($bundles['child']['workflow']['run_id'], $link->child_workflow_run_id);
        foreach ($bundles as $name => $bundle) {
            $run = WorkflowRun::query()->findOrFail($bundle['workflow']['run_id']);
            $this->assertSame($namespace, $run->namespace);
            $export = HistoryExport::forRun($run);
            $entries = $export['links'][$name === 'parent' ? 'children' : 'parents'];
            $this->assertCount(1, $entries);
            $this->assertSame('waiting', $entries[0]['status']);
            $this->assertSame('already_imported', EmbeddedV2HistoryImport::import($bundle, [
                'namespace' => $namespace,
            ])['status']);
        }
        $this->assertSame(1, WorkflowLink::query()->count());
    }

    public static function importOrders(): iterable
    {
        foreach (['parent', 'child'] as $first) {
            foreach (['tenant-a', null] as $namespace) {
                yield $first . '/' . ($namespace ?? 'null') => [$first, $namespace];
            }
        }
    }

    public function testConcurrentPostgresReciprocalImportsKeepTheirScopedEdge(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql' || ! function_exists('pcntl_fork')
            || ! function_exists('posix_kill') || ! function_exists('stream_socket_pair')) {
            $this->markTestSkipped('PostgreSQL and process control are required for this serialization race.');
        }
        $bundles = $this->sourcePair();
        $this->clearState();
        $defaultIsolation = DB::selectOne('SHOW transaction_isolation')->transaction_isolation;
        DB::purge();
        $actors = [];
        $prepared = [];
        $attempts = [];
        try {
            foreach ($bundles as $name => $bundle) {
                $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
                $this->assertIsArray($sockets);
                $pid = pcntl_fork();
                $this->assertNotSame(-1, $pid);
                if ($pid === 0) {
                    fclose($sockets[0]);
                    stream_set_timeout($sockets[1], 15);
                    try {
                        DB::reconnect();
                        $paused = false;
                        $beginnings = 0;
                        $this->app['events']->listen(TransactionBeginning::class, static function () use (
                            &$beginnings
                        ): void {
                            $beginnings++;
                        });
                        $this->app['events']->listen(TransactionCommitting::class, static function () use (
                            &$paused,
                            $sockets
                        ): void {
                            if ($paused || DB::transactionLevel() !== 1) {
                                return;
                            }
                            $paused = true;
                            fwrite($sockets[1], json_encode([
                                'connection' => (int) DB::selectOne('SELECT pg_backend_pid() AS id')->id,
                                'runs' => WorkflowRun::query()->count(),
                                'links' => WorkflowLink::query()->count(),
                            ], JSON_THROW_ON_ERROR) . "\n");
                            if (fgets($sockets[1]) !== "commit\n") {
                                throw new RuntimeException('Import commit barrier was not released.');
                            }
                        });
                        $report = EmbeddedV2HistoryImport::import($bundle, [
                            'namespace' => 'tenant-a',
                        ]);
                        fwrite($sockets[1], json_encode([
                            'status' => $report['status'],
                            'attempts' => $beginnings,
                            'errors' => $report['eligibility']['errors'],
                            'transaction_level' => DB::transactionLevel(),
                            'isolation' => DB::selectOne('SHOW transaction_isolation')->transaction_isolation,
                        ], JSON_THROW_ON_ERROR) . "\n");
                        DB::disconnect();
                        fclose($sockets[1]);
                        exit(0);
                    } catch (Throwable $error) {
                        fwrite($sockets[1], json_encode([
                            'error' => $error->getMessage(),
                        ]) . "\n");
                        exit(1);
                    }
                }
                fclose($sockets[1]);
                stream_set_timeout($sockets[0], 15);
                $actors[$name] = [
                    'pid' => $pid,
                    'socket' => $sockets[0],
                ];
                $message = fgets($sockets[0]);
                $this->assertIsString($message);
                $prepared[$name] = json_decode($message, true, flags: JSON_THROW_ON_ERROR);
                $this->assertSame(1, $prepared[$name]['runs'] ?? null, $message);
                $this->assertSame(0, $prepared[$name]['links'] ?? null, $message);
            }
            $this->assertNotSame($prepared['parent']['connection'], $prepared['child']['connection']);
            DB::reconnect();
            $this->assertSame(0, WorkflowRun::query()->count(), 'Both import transactions are still uncommitted.');
            foreach ($actors as $actor) {
                fwrite($actor['socket'], "commit\n");
            }
            foreach ($actors as $name => $actor) {
                $message = fgets($actor['socket']);
                $this->assertIsString($message);
                $report = json_decode($message, true, flags: JSON_THROW_ON_ERROR);
                $this->assertSame('imported', $report['status'] ?? null, $message);
                $this->assertSame(0, $report['transaction_level']);
                $this->assertSame($defaultIsolation, $report['isolation']);
                $attempts[$name] = $report['attempts'];
                pcntl_waitpid($actor['pid'], $status);
                $this->assertTrue(pcntl_wifexited($status));
                $this->assertSame(0, pcntl_wexitstatus($status));
                fclose($actor['socket']);
                unset($actors[$name]);
            }
            $this->assertGreaterThan(
                2,
                array_sum($attempts),
                'A serialization conflict must retry a complete import transaction.'
            );
            $this->assertSame(2, WorkflowRun::query()->count());
            $link = WorkflowLink::query()->sole();
            $this->assertSame($bundles['parent']['workflow']['run_id'], $link->parent_workflow_run_id);
            $this->assertSame($bundles['child']['workflow']['run_id'], $link->child_workflow_run_id);
            foreach ($bundles as $bundle) {
                $this->assertSame('already_imported', EmbeddedV2HistoryImport::import($bundle, [
                    'namespace' => 'tenant-a',
                ])['status']);
            }
            $this->assertSame(1, WorkflowLink::query()->count());
        } finally {
            foreach ($actors as $actor) {
                posix_kill($actor['pid'], SIGKILL);
                pcntl_waitpid($actor['pid'], $status);
                fclose($actor['socket']);
            }
            DB::purge();
            DB::reconnect();
        }
    }

    public function testPostgresWriteRefusesAnExistingTransactionWithoutAbortingIt(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The PostgreSQL transaction isolation boundary is required.');
        }
        $bundle = $this->sourcePair()['parent'];
        $this->clearState();
        DB::beginTransaction();
        try {
            $this->createRun('outer-owned', (string) Str::ulid(), 'tenant-a', 'outer-owned');
            $before = $this->snapshot();
            $report = EmbeddedV2HistoryImport::import($bundle, [
                'namespace' => 'tenant-a',
            ]);
            $this->assertSame('rejected', $report['status']);
            $this->assertContains(
                'target.transaction_in_progress',
                array_column($report['eligibility']['errors'], 'rule')
            );
            $this->assertSame(0, array_sum($report['rows']));
            $this->assertSame(1, DB::transactionLevel());
            $this->assertTrue(DB::connection()->getPdo()->inTransaction());
            $this->assertSame($before, $this->snapshot());
            $this->assertSame('dry_run', EmbeddedV2HistoryImport::import($bundle, [
                'namespace' => 'tenant-a',
                'dry_run' => true,
            ])['status']);
        } finally {
            DB::rollBack();
        }
        $this->assertSame(0, WorkflowRun::query()->count());
    }

    #[DataProvider('lateEndpoints')]
    public function testOmittedEdgeCannotCaptureAnUnrelatedLaterRun(string $selected, string $namespace): void
    {
        $bundles = $this->sourcePair();
        $this->clearState();
        $other = $selected === 'parent' ? 'child' : 'parent';
        $this->assertSame('imported', EmbeddedV2HistoryImport::import($bundles[$selected], [
            'namespace' => 'tenant-a',
        ])['status']);
        // Isolate the proposed edge-omission path from the old physical writer.
        // Typed history must not bypass the absence of a verified edge.
        WorkflowLink::query()->delete();
        $this->assertSame(0, WorkflowLink::query()->count());
        $opposite = $bundles[$other]['workflow'];
        $unrelated = $this->createRun($opposite['instance_id'], $opposite['run_id'], $namespace, 'unrelated-later');
        $before = $unrelated->getAttributes();
        $run = WorkflowRun::query()->findOrFail($bundles[$selected]['workflow']['run_id']);

        if ($selected === 'parent') {
            $this->assertNull(ChildRunHistory::childRunForSequence($run, 1));
        }
        $entries = $selected === 'parent' ? RunLineageView::continuedWorkflowsForRun(
            $run
        ) : RunLineageView::parentsForRun($run);
        $this->assertStringNotContainsString('unrelated-later', json_encode($entries, JSON_THROW_ON_ERROR));
        RunLineageProjector::project($run->fresh());
        foreach ([RunDetailView::forRun($run->fresh()), HistoryExport::forRun($run->fresh())] as $view) {
            $this->assertStringNotContainsString('unrelated-later', json_encode($view, JSON_THROW_ON_ERROR));
        }
        $this->assertSame(0, WorkflowLink::query()->count());
        $this->assertSame($before, $unrelated->fresh()->getAttributes());
    }

    public static function lateEndpoints(): iterable
    {
        foreach (['parent', 'child'] as $selected) {
            foreach (['tenant-a', 'tenant-b'] as $namespace) {
                yield $selected . '/' . $namespace => [$selected, $namespace];
            }
        }
    }

    #[DataProvider('selfLineageReferences')]
    public function testSelfLineageIsRejectedWithoutChangingTheStore(string $reference, bool $dryRun): void
    {
        $bundle = $this->sourcePair()['parent'];
        $ownRun = $bundle['workflow']['run_id'];
        $ownInstance = $bundle['workflow']['instance_id'];
        if (in_array($reference, ['parents', 'children'], true)) {
            $link = $bundle['links']['children'][0];
            foreach (['parent', 'child'] as $direction) {
                $link[$direction . '_workflow_run_id'] = $ownRun;
                $link[$direction . '_workflow_instance_id'] = $ownInstance;
            }
            $bundle['links'] = [
                'parents' => [],
                'children' => [],
            ];
            $bundle['links'][$reference] = [$link];
        } elseif ($reference === 'started-parent') {
            $bundle['history_events'][0]['payload']['parent_workflow_run_id'] = $ownRun;
            $bundle['history_events'][0]['payload']['parent_workflow_instance_id'] = $ownInstance;
        } elseif ($reference === 'continued-from') {
            $bundle['history_events'][0]['payload']['continued_from_run_id'] = $ownRun;
        } elseif (in_array($reference, ['scheduled-child', 'started-child'], true)) {
            $index = $reference === 'scheduled-child' ? 1 : 2;
            $bundle['history_events'][$index]['payload']['child_workflow_run_id'] = $ownRun;
            $bundle['history_events'][$index]['payload']['child_workflow_instance_id'] = $ownInstance;
        } else {
            $event = $bundle['history_events'][2];
            $event['id'] = (string) Str::ulid();
            $event['sequence']++;
            $event['type'] = $reference;
            $event['payload'] = $reference === 'WorkflowContinuedAsNew'
                ? [
                    'continued_to_run_id' => $ownRun,
                ]
                : [
                    ...$event['payload'],
                    'child_workflow_run_id' => $ownRun,
                    'child_workflow_instance_id' => $ownInstance,
                ];
            $bundle['history_events'][] = $event;
            $bundle['workflow']['last_history_sequence'] = $event['sequence'];
        }
        $bundle = $this->reseal($bundle);
        $this->clearState();
        $this->createRun('unrelated-existing', (string) Str::ulid(), 'tenant-b', 'unrelated-existing');
        $before = $this->snapshot();

        $report = EmbeddedV2HistoryImport::import($bundle, [
            'namespace' => 'tenant-a',
            'dry_run' => $dryRun,
        ]);

        $this->assertSame('rejected', $report['status']);
        $this->assertContains(
            'references.lineage_self_reference',
            array_column($report['eligibility']['errors'], 'rule')
        );
        $this->assertSame(0, array_sum($report['rows']));
        $this->assertSame($before, $this->snapshot());
    }

    public static function selfLineageReferences(): iterable
    {
        foreach (['parents', 'children', 'started-parent', 'continued-from', 'WorkflowContinuedAsNew',
            'scheduled-child', 'started-child', 'ChildRunCompleted', 'ChildRunFailed', 'ChildRunCancelled',
            'ChildRunTerminated'] as $reference) {
            foreach ([false, true] as $preview) {
                yield $reference . ($preview ? '/preview' : '/write') => [$reference, $preview];
            }
        }
    }

    public function testImportedLineageResolverDoesNotAuthorizeItsOwnRun(): void
    {
        $run = new WorkflowRun([
            'id' => (string) Str::ulid(),
            'workflow_instance_id' => 'self-instance',
            'namespace' => 'tenant-a',
            'import_source' => EmbeddedV2ImportContract::IMPORT_SOURCE,
        ]);
        $this->assertFalse(ImportedLineageReferences::allows($run, $run));
    }

    #[DataProvider('parentResumePaths')]
    public function testImportedChildClosureCannotResumeAnUnrelatedLaterParent(
        string $path,
        string $namespace,
        bool $counterpart
    ): void {
        Queue::fake();
        config([
            'queue.default' => 'database',
            'workflows.v2.task_dispatch_mode' => 'poll',
        ]);
        $bundles = $this->sourcePair();
        $this->clearState();
        $this->assertSame('imported', EmbeddedV2HistoryImport::import($bundles['child'], [
            'namespace' => 'tenant-a',
        ])['status']);
        if ($counterpart) {
            $this->assertSame('imported', EmbeddedV2HistoryImport::import($bundles['parent'], [
                'namespace' => 'tenant-a',
            ])['status']);
            $parent = WorkflowRun::query()->findOrFail($bundles['parent']['workflow']['run_id']);
            $observed = [$parent];
        } else {
            $identity = $bundles['parent']['workflow'];
            $parent = $this->createRun(
                $identity['instance_id'],
                $identity['run_id'],
                $namespace,
                $identity['workflow_type']
            );
            $otherChild = $this->createRun('unrelated-child', (string) Str::ulid(), $namespace, 'unrelated-child');
            $call = (string) Str::ulid();
            $payload = [
                'sequence' => 1,
                'child_call_id' => $call,
                'workflow_link_id' => $call,
                'child_workflow_instance_id' => $otherChild->workflow_instance_id,
                'child_workflow_run_id' => $otherChild->id,
                'child_workflow_type' => 'unrelated-child',
            ];
            WorkflowHistoryEvent::record($parent, HistoryEventType::WorkflowStarted, []);
            WorkflowHistoryEvent::record($parent, HistoryEventType::ChildWorkflowScheduled, $payload);
            WorkflowHistoryEvent::record($parent, HistoryEventType::ChildRunStarted, $payload);
            WorkflowLink::query()->create([
                'id' => $call,
                'link_type' => 'child_workflow',
                'sequence' => 1,
                'parent_workflow_instance_id' => $parent->workflow_instance_id,
                'parent_workflow_run_id' => $parent->id,
                'child_workflow_instance_id' => $otherChild->workflow_instance_id,
                'child_workflow_run_id' => $otherChild->id,
            ]);
            $observed = [$parent, $otherChild];
        }
        $child = WorkflowRun::query()->findOrFail($bundles['child']['workflow']['run_id']);
        $this->assertSame(
            $counterpart ? 1 : 0,
            WorkflowLink::query()->where('child_workflow_run_id', $child->id)->count()
        );
        $before = $this->ownedState($observed);

        if ($path === 'operator') {
            $this->assertTrue(WorkflowStub::loadRun($child->id)->attemptCancel('imported child closure')->accepted());
        } else {
            $child->forceFill([
                'run_deadline_at' => now()
                    ->subSecond(),
            ])->save();
            $task = WorkflowTask::query()->create([
                'workflow_run_id' => $child->id,
                'namespace' => $child->namespace,
                'task_type' => TaskType::Workflow,
                'status' => TaskStatus::Leased,
                'lease_owner' => 'imported-child-worker',
                'attempt_count' => 1,
                'lease_expires_at' => now()
                    ->addMinute(),
                'available_at' => now(),
                'payload' => [],
            ]);
            $this->assertTrue(
                DB::transaction(static fn (): bool => (new WorkflowExecutor())->timeoutIfDeadlineExpired(
                    $child->fresh(),
                    $task
                ))
            );
        }

        $this->assertTrue($child->fresh()->status->isTerminal());
        if ($counterpart) {
            $this->assertSame(1, $parent->historyEvents()->where('payload->child_workflow_run_id', $child->id)
                ->whereIn(
                    'event_type',
                    [HistoryEventType::ChildRunCancelled->value, HistoryEventType::ChildRunFailed->value]
                )->count());
            $this->assertSame(1, $parent->tasks()->where('task_type', TaskType::Workflow)->count());
        } else {
            $this->assertSame($before, $this->ownedState($observed));
        }
    }

    public static function parentResumePaths(): iterable
    {
        foreach (['operator', 'worker'] as $path) {
            foreach (['tenant-a', 'tenant-b'] as $namespace) {
                yield $path . '/' . $namespace . '/unrelated-later' => [$path, $namespace, false];
            }
            yield $path . '/counterpart-import' => [$path, 'tenant-a', true];
        }
    }

    /** @param list<WorkflowRun> $runs
     * @return array<string, mixed>
     */
    private function ownedState(array $runs): array
    {
        $ids = array_map(static fn (WorkflowRun $run): string => $run->id, $runs);
        $instances = array_map(static fn (WorkflowRun $run): string => $run->workflow_instance_id, $runs);
        $state = [];
        foreach (self::TABLES as $table) {
            $query = DB::table($table);
            if ($table === 'workflow_instances') {
                $query->whereIn('id', $instances);
            } elseif ($table === 'workflow_runs' || $table === 'workflow_run_summaries') {
                $query->whereIn('id', $ids);
            } elseif ($table === 'workflow_links') {
                $query->where(
                    static fn ($builder) => $builder->whereIn(
                        'parent_workflow_run_id',
                        $ids
                    )->orWhereIn('child_workflow_run_id', $ids)
                );
            } else {
                $query->whereIn('workflow_run_id', $ids);
            }
            $state[$table] = $query->get()->map(static fn (object $row): array => (array) $row)->all();
        }

        return $state;
    }

    /** @param array<string, mixed> $bundle
     * @return array<string, mixed>
     */
    private function reseal(array $bundle): array
    {
        unset($bundle['integrity']);
        $canonical = static function (mixed $value) use (&$canonical): mixed {
            if (! is_array($value)) {
                return $value;
            }
            foreach ($value as $key => $entry) {
                $value[$key] = $canonical($entry);
            }
            if (! array_is_list($value)) {
                ksort($value, SORT_STRING);
            }

            return $value;
        };
        $bundle['integrity'] = [
            'canonicalization' => 'json-recursive-ksort-v1',
            'checksum_algorithm' => 'sha256',
            'checksum' => hash(
                'sha256',
                json_encode(
                    $canonical($bundle),
                    JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
                )
            ),
            'signature_algorithm' => null,
            'signature' => null,
            'key_id' => null,
        ];

        return $bundle;
    }

    /**
     * @return array{parent: array<string, mixed>, child: array<string, mixed>}
     */
    private function sourcePair(): array
    {
        $fixture = json_decode(
            file_get_contents(
                __DIR__ . '/../../Fixtures/V2/ReplayRegression/imported-child-lineage-requires-owned-edge.json'
            ),
            true,
            flags: JSON_THROW_ON_ERROR
        );
        $identity = $fixture['history'][2]['payload'];
        $parent = $this->createRun(
            'parent-' . Str::ulid(),
            (string) Str::ulid(),
            'source',
            $fixture['workflow']['type']
        );
        $parent->forceFill([
            'arguments' => Serializer::serialize($fixture['workflow']['arguments']),
        ])->save();
        $child = $this->createRun(
            $identity['child_workflow_instance_id'],
            $identity['child_workflow_run_id'],
            'source',
            $fixture['history'][1]['payload']['child_workflow_type']
        );
        $linkId = $identity['child_call_id'];
        foreach ($fixture['history'] as $event) {
            WorkflowHistoryEvent::record($parent, HistoryEventType::from($event['event_type']), $event['payload']);
        }
        WorkflowHistoryEvent::record($child, HistoryEventType::WorkflowStarted, [
            'parent_workflow_instance_id' => $parent->workflow_instance_id,
            'parent_workflow_run_id' => $parent->id,
            'parent_sequence' => 1,
            'child_call_id' => $linkId,
        ]);
        WorkflowLink::query()->create([
            'id' => $linkId,
            'link_type' => 'child_workflow',
            'sequence' => 1,
            'parent_workflow_instance_id' => $parent->workflow_instance_id,
            'parent_workflow_run_id' => $parent->id,
            'child_workflow_instance_id' => $child->workflow_instance_id,
            'child_workflow_run_id' => $child->id,
            'is_primary_parent' => true,
        ]);
        $bundles = [
            'parent' => HistoryExport::forRun($parent->fresh()),
            'child' => HistoryExport::forRun($child->fresh()),
        ];
        $this->assertCount(1, $bundles['parent']['links']['children']);
        $this->assertCount(1, $bundles['child']['links']['parents']);

        return $bundles;
    }

    private function createRun(string $instanceId, string $runId, ?string $namespace, string $type): WorkflowRun
    {
        WorkflowInstance::query()->create([
            'id' => $instanceId,
            'workflow_type' => $type,
            'workflow_class' => $type,
            'namespace' => $namespace,
            'current_run_id' => $runId,
            'run_count' => 1,
        ]);

        return WorkflowRun::query()->create([
            'id' => $runId,
            'workflow_instance_id' => $instanceId,
            'run_number' => 1,
            'namespace' => $namespace,
            'workflow_type' => $type,
            'workflow_class' => $type,
            'status' => 'waiting',
            'payload_codec' => CodecRegistry::defaultCodec(),
            'arguments' => Serializer::serialize([]),
        ])->refresh();
    }

    private function clearState(): void
    {
        foreach (self::TABLES as $table) {
            DB::table($table)->delete();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(): array
    {
        $result = [];
        foreach (self::TABLES as $table) {
            $result[$table] = DB::table($table)->get()->map(static fn (object $row): array => (array) $row)->all();
        }

        return $result;
    }
}
