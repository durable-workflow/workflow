<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

Illuminate\Support\Carbon::setTestNowAndTimezone(
    Illuminate\Support\Carbon::parse('2026-11-02T00:00:00Z')->setTimezone('UTC'),
    'UTC',
);

$exit = $kernel->call('queue:work', [
    'connection' => 'database',
    '--stop-when-empty' => true,
    '--max-jobs' => 100,
    '--tries' => 1,
    '--sleep' => 1,
]);
echo $kernel->output();
exit($exit);
