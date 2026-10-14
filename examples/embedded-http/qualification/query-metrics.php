<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$path = config('qualification.cli_query_metrics');
Illuminate\Support\Facades\DB::select('SELECT 1');
$before = app(App\Support\RequestMetrics::class)->queries;
$app->forgetScopedInstances();
if (app(App\Support\RequestMetrics::class)->queries !== 0 || $before < 1) {
    throw new RuntimeException('Request counters did not remain scoped.');
}
Illuminate\Support\Facades\DB::select('SELECT 2');
Illuminate\Support\Facades\Event::dispatch(new Illuminate\Queue\Events\Looping('database', 'default'));
if ($path !== null && $path !== '') {
    $records = array_map(static fn ($line) => json_decode($line, true, 512, JSON_THROW_ON_ERROR), file($path));
    $last = end($records);
    if ($last['queries'] < 2 || $last['final'] !== false || strlen($last['process']) !== 16) {
        throw new RuntimeException('CLI counts were lost across scope reset.');
    }
    foreach ($records as $record) {
        if (isset($record['sql']) || isset($record['bindings'])) {
            throw new RuntimeException('Query content was recorded.');
        }
    }
    echo "scoped request counters and cumulative CLI counters pass\n";
} else {
    if (is_file('/state/cli-metrics.jsonl')) {
        throw new RuntimeException('Disabled measurements wrote a file.');
    }
    echo "disabled measurements leave no file\n";
}
