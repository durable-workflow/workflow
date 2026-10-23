<?php

declare(strict_types=1);

namespace Tests\Unit\Commands;

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Workflow\Providers\WorkflowServiceProvider;
use Workflow\V2\Activity;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\StartOptions;
use Workflow\V2\Support\BundleIntegrityVerifier;
use Workflow\V2\Support\MemoPayload;
use Workflow\V2\Workflow;
use Workflow\V2\WorkflowStub;

final class V2HistoryExportNumericKindsTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [WorkflowServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set([
            'database.default' => 'sqlite',
            'database.connections.sqlite' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
            'cache.default' => 'array',
            'queue.default' => 'redis',
            'workflows.v1.enabled' => false,
            'workflows.serializer' => 'avro',
            'workflows.v2.compatibility.current' => null,
            'workflows.v2.execution_continuation.enabled' => false,
        ]);
    }

    /**
     * @return iterable<string, array{bool, bool}>
     */
    public static function exportModes(): iterable
    {
        yield 'waiting compact' => [false, false];
        yield 'waiting pretty' => [false, true];
        yield 'completed compact' => [true, false];
        yield 'completed pretty' => [true, true];
    }

    #[DataProvider('exportModes')]
    public function testSerializedExportsPreserveNumericKinds(bool $complete, bool $pretty): void
    {
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        Queue::fake();
        $memo = [
            'whole' => 1.0,
            'signed_zero' => -0.0,
            'nested' => [
                'whole' => 2.0,
                'signed_zero' => -0.0,
                'list' => [3.0, -0.0, 1.5, 4],
            ],
        ];
        $workflow = WorkflowStub::make(NumericExportWorkflow::class);
        $workflow->start(new StartOptions(
            executionTimeoutSeconds: 30,
            runTimeoutSeconds: 30,
            memo: $memo,
        ));
        $this->runReadyTask(TaskType::Workflow);

        if ($complete) {
            $this->runReadyTask(TaskType::Activity);
            $this->runReadyTask(TaskType::Workflow);
            $this->assertTrue($workflow->refresh()->completed());
        } else {
            $this->assertFalse($workflow->refresh()->run()->status->isTerminal());
        }

        $path = sys_get_temp_dir() . '/workflow-numeric-export-' . Str::ulid() . '.json';
        $this->beforeApplicationDestroyed(static function () use ($path): void {
            if (is_file($path)) {
                unlink($path);
            }
        });
        $this->artisan('workflow:v2:history-export', [
            'target' => $workflow->runId(),
            '--run' => true,
            '--output' => $path,
            '--pretty' => $pretty,
        ])->assertSuccessful();

        $raw = (string) file_get_contents($path);
        $hash = hash('sha256', $raw);
        $bundle = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        $projection = $bundle['workflow']['memo'];
        $this->assertSame($memo, $projection);
        $this->assertSame(-INF, fdiv(1.0, $projection['signed_zero']));
        $this->assertSame(-INF, fdiv(1.0, $projection['nested']['signed_zero']));
        $this->assertSame(-INF, fdiv(1.0, $projection['nested']['list'][1]));
        $this->assertSame($memo, MemoPayload::decodeEntries($bundle['workflow']['memo_payload']));
        $report = BundleIntegrityVerifier::verifyJson($raw);
        $this->assertSame(BundleIntegrityVerifier::STATUS_OK, $report['status'], json_encode($report));
        $this->artisan('workflow:v2:replay-verify', [
            'bundle' => $path,
            '--skip-replay' => true,
            '--json' => true,
        ])->assertSuccessful();
        $this->assertSame($hash, hash_file('sha256', $path));

        // Historical lossy exports remain invalid. A reader must not coerce
        // their projections or replace their recorded canonical checksum.
        $lossy = json_encode($bundle, JSON_THROW_ON_ERROR);
        $refusal = BundleIntegrityVerifier::verifyJson($lossy);
        $this->assertSame(BundleIntegrityVerifier::STATUS_FAILED, $refusal['status']);
        $rules = array_column($refusal['findings'], 'rule');
        $this->assertContains('workflow.memo_projection_mismatch', $rules);
        $this->assertContains('integrity.checksum_mismatch', $rules);
    }

    private function runReadyTask(TaskType $type): void
    {
        $task = WorkflowTask::query()->where('status', TaskStatus::Ready->value)
            ->where('task_type', $type->value)->sole();
        $job = $type === TaskType::Workflow ? new RunWorkflowTask($task->id) : new RunActivityTask($task->id);
        $this->app->call([$job, 'handle']);
    }
}

final class NumericExportWorkflow extends Workflow
{
    public function handle(): array
    {
        return \Workflow\V2\activity(NumericExportActivity::class);
    }
}

final class NumericExportActivity extends Activity
{
    public function handle(): array
    {
        return [1.0, -0.0, ['whole' => 2.0]];
    }
}
