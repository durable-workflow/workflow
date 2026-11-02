<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use WeakReference;
use Workflow\Providers\WorkflowServiceProvider;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunTimerEntry;
use Workflow\V2\Models\WorkflowRunWait;
use Workflow\V2\Support\RunTimerProjector;
use Workflow\V2\Support\RunWaitProjector;

final class ProjectionMemoryBoundsTest extends TestCase
{
    public static function projectors(): iterable
    {
        yield 'waits' => [RunWaitProjector::class, WorkflowRunWait::class];
        yield 'timers' => [RunTimerProjector::class, WorkflowRunTimerEntry::class];
    }

    /**
     * @param class-string<RunWaitProjector>|class-string<RunTimerProjector> $projector
     * @param class-string<WorkflowRunWait>|class-string<WorkflowRunTimerEntry> $model
     */
    #[DataProvider('projectors')]
    public function testPersistenceOnlyProjectionKeepsItsLiveModelInventoryBounded(
        string $projector,
        string $model,
    ): void {
        $instance = WorkflowInstance::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_class' => 'ProjectionMemoryFixture',
            'workflow_type' => 'projection-memory-fixture',
            'namespace' => 'default',
        ]);
        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => $instance->workflow_class,
            'workflow_type' => $instance->workflow_type,
            'status' => 'running',
        ]);
        $items = [];
        for ($index = 0; $index < 350; ++$index) {
            $items[] = [
                'id' => 'effect-' . $index,
                'kind' => 'timer',
                'sequence' => $index + 1,
                'status' => 'cancelled',
                'source_status' => 'cancelled',
                'opened_at' => now()
                    ->addSeconds($index),
                'fire_at' => now()
                    ->addHours(1),
                'history_authority' => 'typed_history',
            ];
        }
        $projector::project($run, $items);
        $references = [];
        $peak = 0;
        $watch = true;
        Event::listen('eloquent.retrieved: ' . $model, static function (Model $row) use (
            &$references,
            &$peak,
            &$watch
        ): void {
            if (! $watch) {
                return;
            }
            $references[] = WeakReference::create($row);
            $live = count(array_filter($references, static fn (WeakReference $reference): bool =>
                $reference->get() !== null));
            $peak = max($peak, $live);
        });

        $returned = $projector::project($run->fresh(), $items, false);
        $watch = false;
        $this->assertGreaterThan(0, $peak);
        $this->assertLessThanOrEqual(125, $peak, 'Persistence must not retain the whole projected model graph.');
        $this->assertSame([], $returned);
        $table = (new $model())->getTable();
        $stored = DB::table($table)->where('workflow_run_id', $run->id)->orderBy('position')->get();
        $this->assertCount(350, $stored);
        $this->assertSame(range(0, 349), $stored->pluck('position')->all());
        $this->assertSame(['cancelled'], $stored->pluck('status')->unique()->values()->all());

        $collected = $projector::project($run->fresh(), $items);
        $this->assertCount(350, $collected, 'Existing callers still receive every model by default.');
        $this->assertSame(
            range(0, 349),
            array_map(static fn (Model $row): int => (int) $row->getAttribute('position'), $collected)
        );
    }

    protected function getPackageProviders($app): array
    {
        return [WorkflowServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('queue.default', 'database');
        $app['config']->set('workflows.v1.enabled', false);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->artisan('migrate', [
            '--database' => 'testing',
            '--force' => true,
        ])->assertExitCode(0);
    }
}
