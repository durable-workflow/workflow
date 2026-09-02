<?php

declare(strict_types=1);

/**
 * Run one command while recording Linux process I/O and child CPU usage.
 *
 * Usage:
 *   php run-with-resource-metrics.php --output=metrics.md -- command [args...]
 */

$arguments = array_slice($_SERVER['argv'], 1);
$output = null;

while ($arguments !== []) {
    $argument = array_shift($arguments);

    if ($argument === '--') {
        break;
    }

    if (is_string($argument) && str_starts_with($argument, '--output=')) {
        $output = substr($argument, strlen('--output='));

        continue;
    }

    fwrite(STDERR, "Usage: php run-with-resource-metrics.php --output=metrics.md -- command [args...]\n");
    exit(2);
}

if (! is_string($output) || $output === '' || $arguments === []) {
    fwrite(STDERR, "Usage: php run-with-resource-metrics.php --output=metrics.md -- command [args...]\n");
    exit(2);
}

$usageBefore = getrusage(1);
$startedAt = hrtime(true);
$process = proc_open(
    $arguments,
    [
        0 => STDIN,
        1 => STDOUT,
        2 => STDERR,
    ],
    $pipes,
    null,
    null,
    ['bypass_shell' => true],
);

if (! is_resource($process)) {
    fwrite(STDERR, "Unable to start the measured command.\n");
    exit(2);
}

$status = proc_get_status($process);
$pid = (int) $status['pid'];
$writeBytes = 0;
$writeSyscalls = 0;
$exitCode = null;

while (true) {
    $io = readProcessIo($pid);
    $writeBytes = max($writeBytes, $io['write_bytes'] ?? 0);
    $writeSyscalls = max($writeSyscalls, $io['syscw'] ?? 0);

    $status = proc_get_status($process);
    if (! $status['running']) {
        $exitCode = (int) $status['exitcode'];
        break;
    }

    usleep(100_000);
}

$closeCode = proc_close($process);
if ($exitCode < 0) {
    $exitCode = $closeCode;
}

$elapsedSeconds = (hrtime(true) - $startedAt) / 1_000_000_000;
$usageAfter = getrusage(1);
$cpuSeconds = usageSeconds($usageAfter, 'ru_utime') + usageSeconds($usageAfter, 'ru_stime')
    - usageSeconds($usageBefore, 'ru_utime') - usageSeconds($usageBefore, 'ru_stime');

$report = sprintf(
    "## Coverage resource metrics\n\n"
    . "| Metric | Value |\n"
    . "| --- | ---: |\n"
    . "| Elapsed time | %.3f s |\n"
    . "| PHP CPU time | %.3f s |\n"
    . "| Filesystem write bytes | %d |\n"
    . "| Write syscalls | %d |\n",
    $elapsedSeconds,
    $cpuSeconds,
    $writeBytes,
    $writeSyscalls,
);

$outputDirectory = dirname($output);
if (! is_dir($outputDirectory)) {
    mkdir($outputDirectory, 0777, true);
}

file_put_contents($output, $report);
fwrite(STDOUT, "\n{$report}");

$stepSummary = getenv('GITHUB_STEP_SUMMARY');
if (is_string($stepSummary) && $stepSummary !== '') {
    file_put_contents($stepSummary, $report, FILE_APPEND);
}

exit($exitCode);

/**
 * @return array<string, int>
 */
function readProcessIo(int $pid): array
{
    $contents = @file_get_contents("/proc/{$pid}/io");
    if (! is_string($contents)) {
        return [];
    }

    $values = [];
    foreach (explode("\n", $contents) as $line) {
        if (preg_match('/^(\w+):\s+(\d+)$/', $line, $matches) === 1) {
            $values[$matches[1]] = (int) $matches[2];
        }
    }

    return $values;
}

/**
 * @param array<string, int> $usage
 */
function usageSeconds(array $usage, string $prefix): float
{
    return (float) ($usage["{$prefix}.tv_sec"] ?? 0)
        + ((float) ($usage["{$prefix}.tv_usec"] ?? 0) / 1_000_000);
}
