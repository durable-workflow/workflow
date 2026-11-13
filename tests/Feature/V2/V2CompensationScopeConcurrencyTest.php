<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Fixtures\V2\TestSignalWorkflow;
use Tests\TestCase;
use Throwable;
use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\Persistence\ChildScopes;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\Compensation\Recovery\ScopeRecovery;
use Workflow\V2\Compensation\Security\ScopeAuthorization;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\ConfiguredV2Models;
use Workflow\V2\WorkflowStub;

final class V2CompensationScopeConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'pgsql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Separate processes and PostgreSQL row-lock observation are required.');
        }
        self::stopWorkers();
        Queue::fake();
        config([
            'workflows.v2.task_dispatch_mode' => 'poll',
        ]);
        $this->app->instance(ScopeAuthorization::class, new class() implements ScopeAuthorization {
            public function assertAllowed(ScopeIdentity $scope, string $principal, string $operation): string
            {
                if ($scope->namespace !== 'client-a' || $principal !== 'operator-a') {
                    throw new RuntimeException('Scope access refused.');
                }
                return 'test-policy-1';
            }
        });
    }

    public function testConcurrentRequestsStartOneCanonicalRecovery(): void
    {
        $scope = $this->openOrigin();
        $results = $this->race(
            static fn (int $index): array => app(
                ScopeRecovery::class
            )->request($scope, 'request-' . $index, 'operator-a', 'cancel')
        );
        $this->assertSame($results[0]['runId'], $results[1]['runId']);
        $this->assertSame(
            1,
            WorkflowRun::query()->where('workflow_instance_id', $scope->recoveryInstanceId())->count()
        );
        $this->assertSame(
            2,
            ConfiguredV2Models::connection()->table('workflow_compensation_requests')->where(
                'scope_id',
                $scope->id()
            )->count()
        );
    }

    public function testRequestSerializesWithRegistrationAndClosesNewAdmission(): void
    {
        $scope = $this->openOrigin();
        $this->race(static function (int $index) use ($scope): array {
            if ($index === 0) {
                return [
                    'id' => (new ScopeRepository())->register(
                        $scope,
                        new Obligation('book', 'carrier.cancel', '1', [])
                    ),
                ];
            }
            return app(ScopeRecovery::class)->request($scope, 'request', 'operator-a', 'cancel');
        });
        $this->assertCount(1, (new ScopeRepository())->inspect($scope)['obligations']);
        $this->expectException(\LogicException::class);
        (new ScopeRepository())->register($scope, new Obligation('later', 'carrier.cancel', '1', []));
    }

    public function testCompetingEdgesCannotCreateACycle(): void
    {
        $a = new ScopeIdentity('shop', 'client-a', 'a', '1');
        $b = new ScopeIdentity('shop', 'client-a', 'b', '1');
        $results = $this->race(static function (int $index) use ($a, $b): array {
            try {
                $id = (new ChildScopes())->register(
                    $index === 0 ? $a : $b,
                    $index === 0 ? 'run-a' : 'run-b',
                    'child',
                    $index === 0 ? $b : $a
                );
                return [
                    'accepted' => true,
                    'id' => $id,
                ];
            } catch (\LogicException) {
                return [
                    'accepted' => false,
                ];
            }
        });
        $this->assertTrue($results[0]['accepted']);
        $this->assertFalse($results[1]['accepted']);
        $this->assertSame(1, DB::table('workflow_compensation_children')->count());
    }

    private function openOrigin(): ScopeIdentity
    {
        $scope = new ScopeIdentity('shop', 'client-a', 'order-42', '1');
        $origin = WorkflowStub::make(TestSignalWorkflow::class, 'origin', 'client-a');
        $origin->start();
        WorkflowRun::query()->findOrFail($origin->runId())->forceFill([
            'status' => 'completed',
            'closed_at' => now(),
        ])->save();
        (new ScopeRepository())->open($scope, $origin->runId());
        return $scope;
    }

    private function race(Closure $operation, bool $expectLock = true): array
    {
        $root = sys_get_temp_dir() . '/dw-conflict-' . bin2hex(random_bytes(6));
        mkdir($root);
        DB::purge();
        $children = [];
        $results = [];
        try {
            for ($index = 0; $index < 2; $index++) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    throw new RuntimeException('Cannot fork the conflict probe.');
                }
                if ($pid === 0) {
                    try {
                        DB::reconnect();
                        DB::statement("SET application_name = 'dw-conflict-probe-{$index}'");
                        DB::statement("SET lock_timeout = '15s'");
                        if ($index === 0) {
                            DB::beginTransaction();
                            $result = $operation($index);
                            touch($root . '/holding');
                            self::waitFile($root . '/release');
                            DB::commit();
                        } else {
                            self::waitFile($root . '/holding');
                            $result = $operation($index);
                        }
                        file_put_contents($root . '/' . $index . '.json', json_encode([
                            'result' => $result,
                        ], JSON_THROW_ON_ERROR));
                        DB::disconnect();
                        exit(0);
                    } catch (Throwable $error) {
                        file_put_contents($root . '/' . $index . '.json', json_encode([
                            'error' => $error::class . ': ' . $error->getMessage(),
                        ]));
                        exit(1);
                    }
                }
                $children[] = $pid;
            }
            DB::reconnect();
            self::waitFile($root . '/holding');
            if ($expectLock) {
                $deadline = microtime(true) + 10;
                do {
                    $state = DB::selectOne(
                        "SELECT wait_event_type FROM pg_stat_activity WHERE application_name = 'dw-conflict-probe-1'"
                    );
                    if (($state->wait_event_type ?? null) === 'Lock') {
                        break;
                    }
                    if (is_file($root . '/1.json')) {
                        throw new RuntimeException(
                            'Second caller finished without reaching the expected row lock: ' . file_get_contents(
                                $root . '/1.json'
                            )
                        );
                    }
                    usleep(10000);
                } while (microtime(true) < $deadline);
                self::assertSame(
                    'Lock',
                    $state->wait_event_type ?? null,
                    'The calls must overlap at the real PostgreSQL lock.'
                );
            } else {
                self::waitFile($root . '/1.json');
            }
            touch($root . '/release');
            foreach ($children as $index => $pid) {
                pcntl_waitpid($pid, $status);
                $payload = json_decode(
                    file_get_contents($root . '/' . $index . '.json'),
                    true,
                    flags: JSON_THROW_ON_ERROR
                );
                self::assertSame(0, pcntl_wexitstatus($status), $payload['error'] ?? 'Child failed.');
                $results[] = $payload['result'];
            }
            return $results;
        } finally {
            touch($root . '/release');
            foreach ($children as $pid) {
                if (pcntl_waitpid($pid, $status, WNOHANG) === 0) {
                    posix_kill($pid, SIGKILL);
                    pcntl_waitpid($pid, $status);
                }
            }
            foreach (glob($root . '/*') as $file) {
                unlink($file);
            }
            rmdir($root);
        }
    }

    private static function waitFile(string $file): void
    {
        $deadline = microtime(true) + 10;
        while (! is_file($file)) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Probe barrier timed out.');
            }
            usleep(10000);
        }
    }
}
