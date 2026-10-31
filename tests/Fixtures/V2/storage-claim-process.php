<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\Foundation\Application;
use Tests\Unit\V2\StorageTransactionInstance;
use Tests\Unit\V2\StorageTransactionRun;
use Workflow\Providers\WorkflowServiceProvider;
use Workflow\V2\Contracts\WorkflowTaskBridge;

require __DIR__ . '/../../../vendor/autoload.php';
require __DIR__ . '/../../Unit/V2/WorkflowStorageTransactionTest.php';

final class StorageClaimApplication extends Application
{
    public static array $settings;

    protected function defineEnvironment($app): void
    {
        $app['config']->set([
            'database.default' => 'application',
            'database.connections.application' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
            'database.connections.workflow_storage' => self::$settings['connection'],
            'workflows.storage.connection' => self::$settings['custom'] ? null : 'workflow_storage',
            'workflows.v1.enabled' => false,
            'queue.default' => 'database',
            'cache.default' => 'array',
        ]);
        if (self::$settings['custom']) {
            $app['config']->set([
                'workflows.v2.run_model' => StorageTransactionRun::class,
                'workflows.v2.instance_model' => StorageTransactionInstance::class,
            ]);
        }
    }
}

[, $directory, $worker] = $argv;
StorageClaimApplication::$settings = json_decode(
    file_get_contents($directory . '/settings.json'),
    true,
    flags: JSON_THROW_ON_ERROR,
);
$base = $directory . '/' . $worker;
foreach (['bootstrap/cache', 'storage/logs', 'storage/framework/views', 'config'] as $path) {
    mkdir($base . '/' . $path, 0700, true);
}
$app = StorageClaimApplication::create(basePath: $base, options: [
    'extra' => [
        'providers' => [WorkflowServiceProvider::class],
        'dont-discover' => ['*'],
    ],
]);
$isClaimRead = static fn (string $sql): bool => str_starts_with(strtolower($sql), 'select')
    && str_contains(strtolower($sql), 'workflow_tasks') && str_contains(strtolower($sql), 'for update');
DB::connection('workflow_storage')->beforeExecuting(static function (string $sql) use (
    $directory,
    $worker,
    $isClaimRead,
): void {
    if ($isClaimRead($sql)) {
        file_put_contents($directory . '/' . $worker . '.query', 'ready');
    }
});
DB::listen(static function (QueryExecuted $query) use ($directory, $worker, $isClaimRead): void {
    if ($worker !== 'A' || ! $isClaimRead($query->sql)) {
        return;
    }
    file_put_contents($directory . '/A.selected', 'ready');
    $deadline = hrtime(true) + 10_000_000_000;
    while (! is_file($directory . '/release')) {
        if (hrtime(true) >= $deadline) {
            throw new \RuntimeException('The claim barrier was not released.');
        }
        usleep(10_000);
    }
});
$result = $app->make(WorkflowTaskBridge::class)->claimStatus(
    StorageClaimApplication::$settings['task_id'],
    'storage-' . $worker,
);
echo json_encode([
    'claimed' => $result['claimed'],
], JSON_THROW_ON_ERROR), PHP_EOL;
