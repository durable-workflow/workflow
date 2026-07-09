<?php

declare(strict_types=1);

namespace Tests\Unit\Commands;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Workflow\Commands\V1ListCommand;

final class V1ListCommandTest extends TestCase
{
    public function testItRendersIntegerIdsAndOnlyListsActiveWorkflows(): void
    {
        $activeId = DB::table('workflows')->insertGetId([
            'class' => 'App\\ActiveIntegerWorkflow',
            'status' => 'running',
            'created_at' => '2026-07-09 10:00:00',
            'updated_at' => '2026-07-09 10:00:00',
        ]);

        foreach (['completed', 'failed', 'cancelled'] as $status) {
            DB::table('workflows')->insert([
                'class' => 'App\\Terminal' . ucfirst($status) . 'Workflow',
                'status' => $status,
                'created_at' => '2026-07-09 09:00:00',
                'updated_at' => '2026-07-09 09:00:00',
            ]);
        }

        $this->artisan('workflow:v1:list')
            ->expectsOutputToContain((string) $activeId)
            ->expectsOutputToContain('ActiveIntegerWorkflow')
            ->doesntExpectOutputToContain('TerminalCompletedWorkflow')
            ->doesntExpectOutputToContain('TerminalFailedWorkflow')
            ->doesntExpectOutputToContain('TerminalCancelledWorkflow')
            ->expectsOutputToContain('Found 1 active v1 workflow(s) in the workflows table.')
            ->assertSuccessful();
    }

    public function testJsonOutputPreservesTheIntegerIdType(): void
    {
        $id = DB::table('workflows')->insertGetId([
            'class' => 'App\\JsonWorkflow',
            'status' => 'pending',
            'created_at' => '2026-07-09 10:00:00',
            'updated_at' => '2026-07-09 10:00:00',
        ]);

        $exitCode = Artisan::call('workflow:v1:list', [
            '--json' => true,
        ]);
        $workflows = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertIsArray($workflows);
        $this->assertCount(1, $workflows);
        $this->assertIsArray($workflows[0]);
        $this->assertIsInt($workflows[0]['id']);
        $this->assertSame($id, $workflows[0]['id']);
    }

    public function testItRendersShortLongAndUuidLikeStringIds(): void
    {
        $this->recreateWorkflowsTableWithStringIds();

        $shortId = 'workflow-7';
        $longId = 'tenant-orders-workflow-identifier-that-is-long';
        $uuidId = '123e4567-e89b-12d3-a456-426614174000';

        foreach ([$shortId, $longId, $uuidId] as $index => $id) {
            DB::table('workflows')->insert([
                'id' => $id,
                'class' => 'App\\StringIdWorkflow' . $index,
                'status' => 'running',
                'created_at' => sprintf('2026-07-09 10:00:0%d', $index),
                'updated_at' => sprintf('2026-07-09 10:00:0%d', $index),
            ]);
        }

        $this->artisan('workflow:v1:list')
            ->expectsOutputToContain($shortId)
            ->expectsOutputToContain(substr($longId, 0, 24) . '...')
            ->expectsOutputToContain(substr($uuidId, 0, 24) . '...')
            ->expectsOutputToContain('Found 3 active v1 workflow(s) in the workflows table.')
            ->assertSuccessful();

        $exitCode = Artisan::call('workflow:v1:list', [
            '--json' => true,
        ]);
        $workflows = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertIsArray($workflows);

        $ids = [];
        foreach ($workflows as $workflow) {
            $this->assertIsArray($workflow);
            $this->assertIsString($workflow['id']);
            $ids[] = $workflow['id'];
        }

        $this->assertSame([$uuidId, $longId, $shortId], $ids);
    }

    public function testItReportsWhenNoActiveV1WorkflowsRemain(): void
    {
        DB::table('workflows')->insert([
            'class' => 'App\\CompletedWorkflow',
            'status' => 'completed',
            'created_at' => '2026-07-09 10:00:00',
            'updated_at' => '2026-07-09 10:00:00',
        ]);

        $command = $this->app->make(V1ListCommand::class);

        $this->assertSame(
            'List active v1 workflows from the workflows table after upgrading to v2',
            $command->getDescription()
        );

        $this->artisan('workflow:v1:list')
            ->expectsOutput('No active v1 workflows found in the workflows table.')
            ->expectsOutputToContain('DROP TABLE IF EXISTS workflows;')
            ->assertSuccessful();
    }

    private function recreateWorkflowsTableWithStringIds(): void
    {
        foreach ([
            'workflow_relationships',
            'workflow_exceptions',
            'workflow_timers',
            'workflow_signals',
            'workflow_logs',
            'workflows',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('workflows', static function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->text('class');
            $table->text('arguments')->nullable();
            $table->text('output')->nullable();
            $table->string('status')->default('pending')->index();
            $table->timestamps(6);
        });
    }
}
