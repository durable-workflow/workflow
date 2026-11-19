<?php

declare(strict_types=1);

namespace Tests\Support;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Journal;
use DurableWorkflow\Examples\ProviderStateMachines\Authority;
use DurableWorkflow\Examples\ProviderStateMachines\Catalogue;
use DurableWorkflow\Examples\ProviderStateMachines\MachineStore;
use DurableWorkflow\Examples\ProviderStateMachines\RootCancellationStore;
use Orchestra\Testbench\TestCase;
use PDO;
use Workflow\Providers\WorkflowServiceProvider;

require_once __DIR__ . '/../../examples/provider-state-machines/bootstrap.php';

/** Fresh application recovers persisted business/native state, without the parent's service objects. */
final class ProviderRootCancellationRecoveryProcessTest extends TestCase
{
    public function testRecoverTheOriginalBusinessCancellation(): void
    {
        $input = $this->input();
        $authority = new Authority($this->app['db']->connection(), 'provider-worker', [$input['key']], [
            $input['key'] => Catalogue::RESERVE_FIRST,
        ], reservedRoots: [$input['key']]);
        [, $terms] = $authority->assignment($input['key']);
        $providerDb = new PDO('sqlite:' . $input['providerPath']);
        $provider = new Journal($providerDb, $terms->client);
        MachineStore::configure($this->app, $authority, $provider);
        $original = $authority->roots->identity($input['key']);
        $autoloaded = [];
        $loader = static function (string $class) use (&$autoloaded): void {
            $autoloaded[] = $class;
        };
        spl_autoload_register($loader);
        try {
            // The recovery key comes from the durable original request, not a
            // newly generated request or a current-deployment identity factory.
            $request = $authority->db->table('provider_root_cancellations')
                ->where('root_key', $input['key'])->sole();
            $result = app(RootCancellationStore::class)->request($input['key'], $request->request_key);
        } finally {
            spl_autoload_unregister($loader);
        }
        file_put_contents($input['report'], json_encode([
            'pid' => getmypid(),
            'result' => $result,
            'originalClassAutoloaded' => in_array($original->workflowClass, $autoloaded, true),
            'providerRequests' => (int) $providerDb->query('SELECT COUNT(*) FROM provider_requests')
                ->fetchColumn(),
        ], JSON_THROW_ON_ERROR));
        $this->assertSame('cancelled', $result['status']);
    }

    protected function getPackageProviders($app): array
    {
        return [WorkflowServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set([
            'database.default' => 'provider_root_process',
            'database.connections.provider_root_process' => $this->input()['database'],
            'workflows.storage.connection' => 'provider_root_process',
            'logging.default' => 'null',
        ]);
    }

    private function input(): array
    {
        $path = getenv('DWF_PROVIDER_ROOT_RECOVERY_INPUT');
        if (! is_string($path) || $path === '') {
            $this->markTestSkipped('Invoked only by the original provider root recovery control.');
        }
        return json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }
}
