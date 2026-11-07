<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\V2\TestRuntimeProfileProbeWorkflow;
use Tests\SchemaTestCase;
use Throwable;
use Workflow\Serializers\Serializer;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\DefaultWorkflowTaskBridge;
use Workflow\V2\Support\QueryStateReplayer;
use Workflow\V2\Support\WorkflowExecutor;

/** Official database-bound consumer; copied as test support into the base tree. */
final class RuntimeProfileCorpusConsumerTest extends SchemaTestCase
{
    #[DataProvider('guardScenarios')]
    public function testTypedRuntimeProfileFixtureRefusesBeforeEffects(?array $fixture, string $entryPoint): void
    {
        if ($fixture === null) {
            $this->assertSame('baseline-without-new-evidence', $entryPoint);
            return;
        }
        $guard = $fixture['runtime_profile_guard'];
        $this->assertSame(1, $guard['version']);
        $this->assertSame('runtime_profile_unsupported', $guard['expected']['reason']);
        $scope = $guard['expected']['unchanged'];
        sort($scope);
        $this->assertSame(['application-invocations', 'cache', 'durable-rows', 'queue'], $scope);
        $row = $guard['retained_run'];
        // Minimal same-schema setup only. Never install a candidate migration,
        // model, guard, executor or job into the merge-base runtime.
        if (! Schema::hasColumn('workflow_runs', 'runtime_profile')) {
            Schema::table('workflow_runs', static function (Blueprint $table): void {
                $table->string('runtime_profile', 64)
                    ->nullable();
            });
        }
        config()
            ->set('cache.default', 'array');
        Cache::store('array')->flush();
        Cache::store('array')->forever('runtime-profile-corpus-sentinel', 'held');
        Queue::fake();
        TestRuntimeProfileProbeWorkflow::$invocations = [];
        $this->assertSame(TestRuntimeProfileProbeWorkflow::class, $fixture['workflow']['type']);
        DB::table('workflow_instances')->insert([
            'id' => $row['instance_id'],
            'namespace' => $row['namespace'],
            'workflow_class' => $fixture['workflow']['type'],
            'workflow_type' => $fixture['workflow']['type'],
            'current_run_id' => $row['run_id'],
            'run_count' => 1,
        ]);
        DB::table('workflow_runs')->insert([
            'id' => $row['run_id'],
            'workflow_instance_id' => $row['instance_id'],
            'run_number' => 1,
            'namespace' => $row['namespace'],
            'runtime_profile' => $row['profile'],
            'workflow_class' => $fixture['workflow']['type'],
            'workflow_type' => $fixture['workflow']['type'],
            'status' => 'waiting',
            'payload_codec' => $fixture['workflow']['payload_codec'],
            'arguments' => Serializer::serializeWithCodec(
                $fixture['workflow']['payload_codec'],
                $fixture['workflow']['arguments']
            ),
            'last_history_sequence' => count($fixture['history']),
        ]);
        DB::table('workflow_tasks')->insert([
            'id' => $row['task_id'],
            'workflow_run_id' => $row['run_id'],
            'namespace' => $row['namespace'],
            'task_type' => 'workflow',
            'status' => 'ready',
            'payload' => '{}',
        ]);
        foreach ($fixture['history'] as $event) {
            DB::table('workflow_history_events')->insert([
                'id' => $row['task_id'],
                'workflow_run_id' => $row['run_id'],
                'sequence' => $event['sequence'],
                'event_type' => $event['event_type'],
                'recorded_at' => $event['recorded_at'],
                'payload' => json_encode($event['payload'], JSON_THROW_ON_ERROR),
            ]);
        }
        $before = $this->durableRows();
        $cache = serialize(Cache::store('array')->getStore());
        $observed = null;
        try {
            if ($entryPoint === 'workflow-task-claim') {
                (new DefaultWorkflowTaskBridge(new WorkflowExecutor()))->claimStatus(
                    $row['task_id'],
                    'runtime-profile-corpus-worker'
                );
            } elseif ($entryPoint === 'query-state-replay') {
                // Resolve the authoritative row within this path, never reuse
                // the other path's refusal or a test-only projected model.
                $run = WorkflowRun::query()->findOrFail($row['run_id']);
                (new QueryStateReplayer())->query($run, 'inspect');
            } else {
                $this->fail('Unknown typed runtime-profile entry point.');
            }
        } catch (Throwable $error) {
            $observed = $error;
        }
        $this->assertNotNull($observed, 'The product runtime permitted the guarded action.');
        $this->assertSame('Workflow\\V2\\Exceptions\\RuntimeProfileRefusal', $observed::class);
        $this->assertSame($guard['expected']['reason'], $observed->reason);
        $this->assertSame($before, $this->durableRows());
        $this->assertSame($cache, serialize(Cache::store('array')->getStore()));
        $this->assertSame([], TestRuntimeProfileProbeWorkflow::$invocations);
        Queue::assertNothingPushed();
    }

    /**
     * @return iterable<string, array{?array, string}>
     */
    public static function guardScenarios(): iterable
    {
        $found = false;
        foreach (glob(__DIR__ . '/../../Fixtures/V2/ReplayRegression/*.json') ?: [] as $path) {
            $fixture = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            $declared = in_array('embedded-runtime-profile-guard', $fixture['consumers'] ?? [], true);
            $present = array_key_exists('runtime_profile_guard', $fixture);
            self::assertSame($declared, $present, 'The guard object and official consumer must be bound together.');
            if (! $declared) {
                continue;
            }
            $found = true;
            $entries = $fixture['runtime_profile_guard']['entry_points'];
            self::assertSame($entries, array_values(array_unique($entries)));
            $selected = $entries;
            sort($selected);
            self::assertSame(['query-state-replay', 'workflow-task-claim'], $selected);
            foreach ($entries as $entry) {
                yield $fixture['id'] . ':' . $entry => [$fixture, $entry];
            }
        }
        if (! $found) {
            yield 'base without new evidence' => [null, 'baseline-without-new-evidence'];
        }
    }

    /**
     * @return array<string, list<string>>
     */
    private function durableRows(): array
    {
        $connection = DB::connection();
        $grammar = $connection->getQueryGrammar();
        $rows = [];
        foreach ($connection->getSchemaBuilder()->getTableListing() as $table) {
            $quoted = implode('.', array_map($grammar->wrap(...), explode('.', $table)));
            $values = array_map(
                static fn ($row): string => serialize((array) $row),
                $connection->select('SELECT * FROM ' . $quoted)
            );
            sort($values);
            $rows[$table] = $values;
        }
        ksort($rows);
        return $rows;
    }
}
