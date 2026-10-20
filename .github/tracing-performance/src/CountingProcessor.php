<?php

declare(strict_types=1);

namespace TracingBenchmark;

use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\SDK\Trace\ReadableSpanInterface;
use OpenTelemetry\SDK\Trace\ReadWriteSpanInterface;
use OpenTelemetry\SDK\Trace\SpanProcessorInterface;

final class CountingProcessor implements SpanProcessorInterface
{
    private int $ended = 0;

    private bool $stopped = false;

    public function __construct(private readonly SpanProcessorInterface $processor)
    {
    }

    public function onStart(ReadWriteSpanInterface $span, ContextInterface $parentContext): void
    {
        $this->processor->onStart($span, $parentContext);
    }

    public function onEnd(ReadableSpanInterface $span): void
    {
        ++$this->ended;
        $this->processor->onEnd($span);
    }

    public function forceFlush(?\OpenTelemetry\SDK\Common\Future\CancellationInterface $cancellation = null): bool
    {
        return $this->processor->forceFlush($cancellation);
    }

    public function shutdown(?\OpenTelemetry\SDK\Common\Future\CancellationInterface $cancellation = null): bool
    {
        if ($this->stopped) {
            return false;
        }
        $this->stopped = true;
        try {
            return $this->processor->shutdown($cancellation);
        } finally {
            $usage = getrusage();
            file_put_contents(getenv('BENCH_STATE') . '/ended.jsonl', json_encode([
                'instance' => gethostname(), 'pid' => getmypid(),
                'role' => PHP_SAPI === 'cli-server' ? 'http' : 'worker',
                'ended' => $this->ended,
                'cpu_user_seconds' => $usage['ru_utime.tv_sec'] + $usage['ru_utime.tv_usec'] / 1_000_000,
                'cpu_system_seconds' => $usage['ru_stime.tv_sec'] + $usage['ru_stime.tv_usec'] / 1_000_000,
                'maxrss_kib' => $usage['ru_maxrss'],
            ], JSON_THROW_ON_ERROR) . PHP_EOL, FILE_APPEND | LOCK_EX);
        }
    }
}
