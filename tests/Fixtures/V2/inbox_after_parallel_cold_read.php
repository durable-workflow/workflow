<?php

declare(strict_types=1);

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) !== __FILE__) {
    return;
}

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$app = \Orchestra\Testbench\Foundation\Application::create(options: [
    'load_environment_variables' => false,
    'extra' => [
        'providers' => [\Workflow\Providers\WorkflowServiceProvider::class],
    ],
]);
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
try {
    $result = \Workflow\V2\WorkflowStub::loadRun((string) getenv('INBOX_PARALLEL_RUN_ID'))->query('received');
    echo json_encode([
        'received' => $result,
    ], JSON_THROW_ON_ERROR);
} finally {
    $app->make('db')
        ->purge();
}
