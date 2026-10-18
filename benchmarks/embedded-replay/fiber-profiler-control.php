<?php

declare(strict_types=1);

$mode = $argv[1] ?? 'plain';
$profiler = getenv('FIBER_PROFILER') ?: 'none';
if (! in_array($mode, ['plain', 'heavy'], true) || ! in_array($profiler, ['none', 'xhprof', 'spx'], true)) {
    throw new InvalidArgumentException('Use plain or heavy, with FIBER_PROFILER=none, xhprof or spx.');
}
if ($profiler !== 'none' && ! extension_loaded($profiler)) {
    throw new RuntimeException('The requested profiler extension is not loaded.');
}
if ($profiler === 'none') {
    // Give an external sampler time to resolve PHP symbols before counted work.
    usleep(1_000_000);
}

function authoredFiber(): int
{
    $value = 0;
    for ($i = 0; $i < 200_000; ++$i) {
        $value = ($value + $i) % 104729;
    }
    Fiber::suspend($value);
    for ($i = 0; $i < 200_000; ++$i) {
        $value = ($value + $i) % 104729;
    }

    return $value;
}

/** @param array<string, int> $usage */
function cpuMicros(array $usage): int
{
    return $usage['ru_utime.tv_sec'] * 1_000_000 + $usage['ru_utime.tv_usec']
        + $usage['ru_stime.tv_sec'] * 1_000_000 + $usage['ru_stime.tv_usec'];
}

$fiber = new Fiber(authoredFiber(...));
$before = cpuMicros(getrusage());
if ($profiler === 'xhprof') {
    xhprof_enable(XHPROF_FLAGS_CPU);
} elseif ($profiler === 'spx') {
    spx_profiler_start();
}
$suspended = $fiber->start();
$callerValue = 0;
for ($i = 0; $i < ($mode === 'heavy' ? 20_000_000 : 0); ++$i) {
    $callerValue = ($callerValue + $i) % 104729;
}
$fiber->resume();
$raw = null;
if ($profiler === 'xhprof') {
    $raw = xhprof_disable();
} elseif ($profiler === 'spx') {
    spx_profiler_stop();
}
$cpu = cpuMicros(getrusage()) - $before;
if (! $fiber->isTerminated() || $suspended !== 12328 || $fiber->getReturn() !== 24656
    || $callerValue !== ($mode === 'heavy' ? 10730 : 0)) {
    throw new RuntimeException('The original Fiber/caller workload did not complete exactly.');
}
echo json_encode([
    'mode' => $mode,
    'profiler' => $profiler,
    'php' => PHP_VERSION,
    'extension_version' => $profiler === 'none' ? null : phpversion($profiler),
    'process_cpu_us' => $cpu,
    'suspended_checksum' => $suspended,
    'authored_checksum' => $fiber->getReturn(),
    'caller_checksum' => $callerValue,
    'xhprof_raw' => $raw,
], JSON_THROW_ON_ERROR) . PHP_EOL;
