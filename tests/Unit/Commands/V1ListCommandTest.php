<?php

declare(strict_types=1);

namespace Tests\Unit\Commands;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\SchemaTestCase;
use Workflow\Commands\V1ListCommand;

final class V1ListCommandTest extends SchemaTestCase
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

        [$exitCode, $output] = $this->runListCommand();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString((string) $activeId, $output);
        $this->assertStringContainsString('ActiveIntegerWorkflow', $output);
        $this->assertStringNotContainsString('TerminalCompletedWorkflow', $output);
        $this->assertStringNotContainsString('TerminalFailedWorkflow', $output);
        $this->assertStringNotContainsString('TerminalCancelledWorkflow', $output);
        $this->assertStringContainsString('Found 1 active v1 workflow(s) in the workflows table.', $output);
    }

    public function testJsonOutputPreservesTheIntegerIdType(): void
    {
        $id = DB::table('workflows')->insertGetId([
            'class' => 'App\\JsonWorkflow',
            'status' => 'pending',
            'created_at' => '2026-07-09 10:00:00',
            'updated_at' => '2026-07-09 10:00:00',
        ]);

        [$exitCode, $output] = $this->runListCommand([
            '--json' => true,
        ]);
        $workflows = json_decode(trim($output), true, 512, JSON_THROW_ON_ERROR);

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

        [$exitCode, $output] = $this->runListCommand();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString($shortId, $output);
        $this->assertStringContainsString(substr($longId, 0, 24) . '...', $output);
        $this->assertStringContainsString(substr($uuidId, 0, 24) . '...', $output);
        $this->assertStringContainsString('Found 3 active v1 workflow(s) in the workflows table.', $output);

        [$exitCode, $output] = $this->runListCommand([
            '--json' => true,
        ]);
        $workflows = json_decode(trim($output), true, 512, JSON_THROW_ON_ERROR);

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

        [$exitCode, $output] = $this->runListCommand();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('No active v1 workflows found in the workflows table.', $output);
        $this->assertStringContainsString('DROP TABLE IF EXISTS workflows;', $output);
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array{0: int, 1: string}
     */
    private function runListCommand(array $arguments = []): array
    {
        $output = new BufferedOutput();
        $exitCode = Artisan::call('workflow:v1:list', $arguments, $output);

        return [$exitCode, $output->fetch()];
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
            $table->string('id')
                ->primary();
            $table->text('class');
            $table->text('arguments')
                ->nullable();
            $table->text('output')
                ->nullable();
            $table->string('status')
                ->default('pending')
                ->index();
            $table->timestamps(6);
        });
    }
}
