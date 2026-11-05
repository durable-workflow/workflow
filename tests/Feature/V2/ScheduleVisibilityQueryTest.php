<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Orchestra\Testbench\TestCase;
use Workflow\V2\Models\WorkflowSchedule;
use Workflow\V2\Support\ScheduleVisibilityQuery;
use Workflow\V2\Support\ScheduleVisibilityQueryException;

final class ScheduleVisibilityQueryTest extends TestCase
{
    public function testPredicatesAndNamespaceApplyBeforeCountsAndPagination(): void
    {
        for ($index = 0; $index < 51; $index++) {
            $this->schedule('match-' . $index, 'ops', 'orders');
        }
        $this->schedule('other-type', 'ops', 'payments');
        $this->schedule('other-namespace', 'private', 'orders');
        $this->schedule('deleted', 'ops', 'orders', 'deleted');
        $query = new ScheduleVisibilityQuery();
        $filter = 'WorkflowType = "orders" AND Status = "active" AND TaskQueue = "slow"';
        $this->assertSame(51, $query->query('ops', $filter)->count());
        $first = $query->query('ops', $filter)
            ->orderBy('schedule_id')
            ->paginate(50, ['*'], 'page', 1);
        $second = $query->query('ops', $filter)
            ->orderBy('schedule_id')
            ->paginate(50, ['*'], 'page', 2);
        $this->assertSame(51, $first->total());
        $this->assertCount(50, $first->items());
        $this->assertCount(1, $second->items());
        foreach ([...$first->items(), ...$second->items()] as $schedule) {
            $this->assertSame('ops', $schedule->namespace);
            $this->assertSame('orders', $schedule->workflow_type);
        }
    }

    public function testLiteralSqlAndQuotedKeywordsStayBoundValues(): void
    {
        $literal = "x' OR 1=1; -- AND y";
        $schedule = $this->schedule('literal', 'ops', 'orders');
        $schedule->update([
            'note' => $literal,
        ]);
        $this->schedule('unrelated', 'private', 'orders');
        $filter = 'Note = ' . json_encode($literal, JSON_THROW_ON_ERROR);
        $query = (new ScheduleVisibilityQuery())->query('ops', $filter);
        $this->assertContains($literal, $query->getBindings());
        $this->assertStringNotContainsString($literal, $query->toSql());
        $this->assertSame(['literal'], $query->pluck('schedule_id')->all());
        $this->assertSame(2, WorkflowSchedule::query()->count());
    }

    public function testUnsupportedAttributesRefuseInsteadOfReturningAnEmptyPage(): void
    {
        $this->schedule('retained', 'ops', 'orders')
            ->update([
                'search_attributes' => [
                    'Team' => 'gold',
                ],
            ]);
        try {
            (new ScheduleVisibilityQuery())->query('ops', 'Team = "gold"')
                ->count();
            $this->fail('An unsupported attribute must not become an empty or unfiltered list.');
        } catch (ScheduleVisibilityQueryException $error) {
            $this->assertSame('unsupported_schedule_visibility_field', $error->reason);
        }
        $this->assertSame(1, WorkflowSchedule::query()->count());
    }

    public function testAnEmptyNamespaceCannotCreateAnUnscopedQuery(): void
    {
        $this->expectException(ScheduleVisibilityQueryException::class);
        (new ScheduleVisibilityQuery())->query(' ', 'Status = "active"');
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('cache.default', 'array');
    }

    protected function defineDatabaseMigrations(): void
    {
        $migration = require __DIR__ . '/../../../src/migrations/2026_04_14_000157_create_workflow_schedules_table.php';
        $migration->up();
    }

    private function schedule(string $id, string $namespace, string $type, string $status = 'active'): WorkflowSchedule
    {
        return WorkflowSchedule::query()->create([
            'namespace' => $namespace,
            'schedule_id' => $id,
            'status' => $status,
            'spec' => [
                'intervals' => [[
                    'every' => 'PT1H',
                ]],
                'timezone' => 'UTC',
            ],
            'action' => [
                'workflow_type' => $type,
                'task_queue' => 'slow',
            ],
            'overlap_policy' => 'skip',
            'jitter_seconds' => 0,
        ]);
    }
}
