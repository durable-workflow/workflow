<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Workflow\V2\Models\WorkflowSchedule;
use Workflow\V2\Support\ScheduleVisibilityQuery;

final class ScheduleVisibilityQueryNativeStorageTest extends TestCase
{
    public function testNamespaceIdentityDoesNotFoldCaseOrTrailingSpaces(): void
    {
        foreach (['ops', 'OPS', 'ops '] as $index => $namespace) {
            WorkflowSchedule::query()->create([
                'namespace' => $namespace,
                'schedule_id' => 'namespace-' . $index,
                'status' => 'active',
                'action' => [
                    'workflow_type' => 'orders',
                ],
            ]);
        }
        $query = new ScheduleVisibilityQuery();
        $this->assertSame(
            ['namespace-0'],
            $query->query('ops', 'WorkflowType = "orders"')->pluck('schedule_id')->all()
        );
        $this->assertSame(
            ['namespace-1'],
            $query->query('OPS', 'WorkflowType = "orders"')->pluck('schedule_id')->all()
        );
        $this->assertSame(
            ['namespace-2'],
            $query->query('ops ', 'WorkflowType = "orders"')->pluck('schedule_id')->all()
        );
    }

    public function testConfiguredScheduleTableAndConnectionAreAuthoritative(): void
    {
        $connection = 'visibility_query_fixture';
        $table = 'visibility_query_schedules';
        config()
            ->set('database.connections.' . $connection, DB::connection()->getConfig());
        $database = DB::connection($connection);
        $schema = $database->getSchemaBuilder();
        $schema->create($table, static function (Blueprint $blueprint): void {
            $blueprint->string('id')
                ->primary();
            $blueprint->string('schedule_id');
            $blueprint->string('namespace');
            $blueprint->string('status');
            $blueprint->json('action');
        });
        try {
            $database->table($table)
                ->insert([
                    [
                        'id' => 'custom',
                        'schedule_id' => 'custom',
                        'namespace' => 'ops',
                        'status' => 'active',
                        'action' => json_encode([
                            'workflow_type' => 'orders',
                        ], JSON_THROW_ON_ERROR),
                    ],
                    [
                        'id' => 'private',
                        'schedule_id' => 'private',
                        'namespace' => 'private',
                        'status' => 'active',
                        'action' => json_encode([
                            'workflow_type' => 'orders',
                        ], JSON_THROW_ON_ERROR),
                    ],
                ]);
            WorkflowSchedule::query()->create([
                'namespace' => 'ops',
                'schedule_id' => 'wrong-default',
                'status' => 'active',
                'action' => [
                    'workflow_type' => 'orders',
                ],
            ]);
            config()
                ->set('workflows.v2.schedule_model', VisibilityQueryCustomSchedule::class);
            $builder = (new ScheduleVisibilityQuery())->query('ops', 'WorkflowType = "orders"');
            $this->assertInstanceOf(VisibilityQueryCustomSchedule::class, $builder->getModel());
            $this->assertSame($connection, $builder->getModel()->getConnectionName());
            $this->assertSame(['custom'], $builder->pluck('schedule_id')->all());
        } finally {
            config()->set('workflows.v2.schedule_model', WorkflowSchedule::class);
            $schema->dropIfExists($table);
            DB::purge($connection);
        }
    }
}

final class VisibilityQueryCustomSchedule extends WorkflowSchedule
{
    protected $table = 'visibility_query_schedules';

    protected $connection = 'visibility_query_fixture';
}
