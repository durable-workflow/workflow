<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase;
use RuntimeException;
use Workflow\Providers\WorkflowServiceProvider;
use Workflow\V2\Contracts\ActivityTaskBridge;
use Workflow\V2\Support\ActivityOutcomeRecorder;
use Workflow\V2\Support\WorkflowRunRetentionCleanup;

final class ActivityOutcomeRetentionProcessTest extends TestCase
{
    public function testOutcomeAndRetention(): void
    {
        $input = $this->input();
        Date::setTestNow($input['observed_at']);
        $connection = DB::connection();
        if ($input['outcome'] === 'pruned') {
            $connection->listen(static function (QueryExecuted $event) use ($input): void {
                if (! str_contains(strtolower($event->sql), 'for update')
                    || ! str_contains($event->sql, 'workflow_runs')) {
                    return;
                }
                file_put_contents($input['ready'], 'run locked');
                while (! is_file($input['continue'])) {
                    if (hrtime(true) >= $input['deadline']) {
                        self::fail('Retention exceeded the original process budget.');
                    }
                    usleep(1_000);
                }
            });
            $report = WorkflowRunRetentionCleanup::pruneRun($input['run_id']);
            self::assertGreaterThan(0, $report['history_events_deleted']);
            $reply = [
                'recorded' => false,
                'reason' => 'pruned',
            ];
        } elseif ($input['outcome'] === 'renew') {
            // Hold the attempt lock, as renewal takes it first, until released.
            // Then announce the run lock request that waits on the audit.
            $paused = false;
            $connection->listen(static function (QueryExecuted $event) use ($input, &$paused): void {
                if ($paused || ! str_contains(strtolower($event->sql), 'for update')
                    || ! str_contains($event->sql, 'activity_attempts')) {
                    return;
                }
                $paused = true;
                file_put_contents($input['ready'], 'attempt locked');
                self::waitFor($input['continue'], $input['deadline']);
            });
            $connection->beforeExecuting(static function (string $query) use ($input): void {
                if (str_contains(strtolower($query), 'for update') && str_contains($query, 'workflow_runs')) {
                    file_put_contents($input['waiting'], 'requesting run lock');
                }
            });
            $status = $this->app->make(ActivityTaskBridge::class)->renewLease(
                $input['attempt_id'],
                $input['lease_owner'],
            );
            $reply = [
                'recorded' => $status['lease_renewed'],
                'reason' => $status['reason'],
            ];
        } elseif ($input['pause_after_run_lock'] ?? false) {
            // Hold the run lock before the audit append until released.
            $paused = false;
            $connection->listen(static function (QueryExecuted $event) use ($input, &$paused): void {
                if ($paused || ! str_contains(strtolower($event->sql), 'for update')
                    || ! str_contains($event->sql, 'workflow_runs')) {
                    return;
                }
                $paused = true;
                file_put_contents($input['ready'], 'run locked');
                self::waitFor($input['continue'], $input['deadline']);
            });
            $reply = $this->app->make(ActivityTaskBridge::class)->complete(
                $input['attempt_id'],
                'discarded late result',
            );
            self::assertFalse($reply['recorded']);
        } else {
            // Signal before this query can wait on retention's run lock. The
            // preceding implementation already holds task/execution locks here.
            $connection->beforeExecuting(static function (string $query) use ($input): void {
                if (str_contains(strtolower($query), 'for update')
                    && str_contains($query, 'workflow_runs')) {
                    file_put_contents($input['ready'], 'requesting run lock');
                }
            });
            if ($input['direct']) {
                $reply = ActivityOutcomeRecorder::record(
                    $input['task_id'],
                    $input['attempt_id'],
                    $input['attempt_number'],
                    'discarded late result',
                    $input['outcome'] === 'failed' ? new RuntimeException('discarded late failure') : null,
                    2,
                    5,
                );
            } else {
                $bridge = $this->app->make(ActivityTaskBridge::class);
                $reply = $input['outcome'] === 'completed'
                    ? $bridge->complete($input['attempt_id'], 'discarded late result')
                    : $bridge->fail($input['attempt_id'], [
                        'message' => 'discarded late failure',
                    ]);
            }
            self::assertFalse($reply['recorded']);
        }

        file_put_contents($input['report'], json_encode([
            'pid' => getmypid(),
            'recorded' => $reply['recorded'],
            'reason' => $reply['reason'],
        ], JSON_THROW_ON_ERROR));
    }

    protected function getPackageProviders($app): array
    {
        return [WorkflowServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $input = $this->input();
        $app['config']->set('database.default', 'retention_process');
        $app['config']->set('database.connections.retention_process', $input['database']);
        $app['config']->set('workflows.storage.connection', 'retention_process');
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('logging.default', 'null');
    }

    private static function waitFor(string $path, int $deadline): void
    {
        while (! is_file($path)) {
            if (hrtime(true) >= $deadline) {
                self::fail('The interleaving exceeded its original process budget.');
            }
            usleep(1_000);
        }
    }

    private function input(): array
    {
        $path = getenv('DWF_RETENTION_PROCESS_INPUT');
        if (! is_string($path) || $path === '') {
            $this->markTestSkipped('Invoked only by the concurrent retention control.');
        }

        return json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }
}
