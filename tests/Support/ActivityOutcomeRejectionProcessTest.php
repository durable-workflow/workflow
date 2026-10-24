<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase;
use Workflow\Providers\WorkflowServiceProvider;
use Workflow\V2\Contracts\ActivityTaskBridge;
use Workflow\V2\Support\WorkflowRunRetentionCleanup;

final class ActivityOutcomeRejectionProcessTest extends TestCase
{
    public function testRefusedOutcome(): void
    {
        $path = getenv('DWF_REJECTION_PROCESS_INPUT');
        if (! is_string($path) || $path === '') {
            $this->markTestSkipped('Invoked with an existing database by the concurrent audit test.');
        }
        $input = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        Date::setTestNow($input['observed_at']);
        DB::connection()->getPdo();
        file_put_contents($input['ready'], 'ready');
        while (! is_file($input['go'])) {
            if (hrtime(true) >= $input['deadline']) {
                $this->fail('The original process budget expired.');
            }
            usleep(1_000);
        }

        if ($input['outcome'] === 'pruned') {
            $pruned = WorkflowRunRetentionCleanup::pruneRun($input['run_id']);
            $this->assertGreaterThan(0, $pruned['history_events_deleted']);
            $reply = [
                'recorded' => false,
                'reason' => 'pruned',
            ];
        } else {
            $bridge = $this->app->make(ActivityTaskBridge::class);
            $reply = $input['outcome'] === 'completed'
                ? $bridge->complete($input['attempt_id'], 'discarded concurrent output')
                : $bridge->fail($input['attempt_id'], [
                    'message' => 'discarded concurrent failure',
                ]);
        }

        file_put_contents($input['report'], json_encode([
            'pid' => getmypid(),
            'recorded' => $reply['recorded'],
            'reason' => $reply['reason'],
        ], JSON_THROW_ON_ERROR));
        $this->assertFalse($reply['recorded']);
        $this->assertContains($reply['reason'], $input['outcome'] === 'pruned' ? ['pruned']
            : ($input['allow_pruned'] ? ['stale_attempt', 'attempt_not_found', 'task_not_found'] : ['stale_attempt']));
    }

    protected function getPackageProviders($app): array
    {
        return [WorkflowServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $path = getenv('DWF_REJECTION_PROCESS_INPUT');
        if (! is_string($path) || $path === '') {
            return;
        }
        $input = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $app['config']->set('database.default', 'audit_process');
        $app['config']->set('database.connections.audit_process', $input['database']);
        $app['config']->set('workflows.storage.connection', 'audit_process');
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('logging.default', 'null');
    }
}
