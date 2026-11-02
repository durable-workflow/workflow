<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Composer\Autoload\ClassLoader;
use Illuminate\Filesystem\Filesystem;
use OpenTelemetry\API\Trace\StatusCode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class FailureOutcomeExecutionTracingTest extends TestCase
{
    public static function modes(): iterable
    {
        foreach (['local', 'ordinary'] as $kind) {
            yield $kind . ' traced' => [$kind, true, true];
            yield $kind . ' disabled' => [$kind, false, false];
            yield $kind . ' legacy' => [$kind, false, true];
        }
    }

    #[DataProvider('modes')]
    public function testColdReplayLinksTheOriginalHandledFailureWithoutAnotherCallback(
        string $kind,
        bool $admissionEnabled,
        bool $workerEnabled,
    ): void {
        $directory = sys_get_temp_dir() . '/dw-tracing-failure-outcomes-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        try {
            $worker = self::worker($directory, $kind, $admissionEnabled, $workerEnabled);
            $admitted = $worker('admit');
            $states = [$admitted];
            $workers = [];
            $deadline = microtime(true) + 30;
            do {
                $state = $worker('inspect');
                if (in_array($state['status'], ['completed', 'failed', 'terminated'], true)) {
                    break;
                }
                if ($state['failure_outcomes']['ready_jobs'] === 0) {
                    usleep(100_000);
                    continue;
                }
                $states[] = $workers[] = $worker('work');
            } while (microtime(true) < $deadline);
            $finished = $worker('inspect');
            self::assertSame('completed', $finished['status'], json_encode($finished, JSON_THROW_ON_ERROR));
            self::assertSame([
                'caught' => 'deliberate terminal activity failure',
                'value' => [9007199254740993, null, '雪'],
            ], $finished['output']);
            foreach (['jobs', 'failed_jobs', 'repairs'] as $field) {
                self::assertSame(0, $finished[$field]);
            }
            $observed = $finished['failure_outcomes'];
            self::assertCount(1, $observed['attempts']);
            self::assertSame('failed', $observed['attempts'][0]['status']);
            self::assertSame(1, $observed['attempts'][0]['attempt_number']);
            self::assertSame(1, $observed['activities'][0]['attempt_count']);
            self::assertSame(
                [1, 2, 5, 10, 15, 30, 60, 120],
                $observed['activities'][0]['retry_policy']['backoff_seconds']
            );
            $counts = array_count_values($observed['history_types']);
            foreach (['ActivityFailed', 'FailureHandled', 'TimerFired', 'WorkflowCompleted'] as $type) {
                self::assertSame(1, $counts[$type]);
            }
            self::assertCount(count($workers), array_unique(array_column($workers, 'pid')));
            foreach ($states as $state) {
                foreach (['run_id', 'carrier', 'execution_deadline', 'run_deadline'] as $field) {
                    self::assertSame($admitted[$field], $state[$field]);
                    self::assertSame($admitted[$field], $finished[$field]);
                }
                self::assertSame($state['failure_outcomes']['history_hashes'], array_slice(
                    $observed['history_hashes'],
                    0,
                    count($state['failure_outcomes']['history_hashes']),
                ));
                foreach ($state['failure_outcomes']['activities'] as $activity) {
                    self::assertSame($activity['retry_policy'], $observed['activities'][0]['retry_policy']);
                }
            }
            $records = array_map(
                static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
                file($directory . '/activity.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES),
            );
            self::assertCount(1, array_filter($records, static fn (array $record): bool =>
                $record['message'] === 'terminal failure callback'));
            self::assertFailureLinks($workers, $observed, $kind, $workerEnabled);
        } finally {
            (new Filesystem())->deleteDirectory($directory);
        }
    }

    private static function worker(string $directory, string $kind, bool $admission, bool $enabled): callable
    {
        return static function (string $mode) use ($directory, $kind, $admission, $enabled): array {
            $process = new Process([
                PHP_BINARY, __DIR__ . '/../../Fixtures/V2/tracing-process.php', $directory, $mode, 'once',
                ...array_map(
                    static fn (string $vendor): string => $vendor . '/autoload.php',
                    array_keys(ClassLoader::getRegisteredLoaders())
                ),
            ], env: [
                'DW_TEST_TRACING_FAILURE_OUTCOMES' => $kind,
                'DW_TEST_TRACING_ENABLED' => ($mode === 'admit' ? $admission : $enabled) ? 'true' : 'false',
            ]);
            $process->setTimeout(15);
            $process->mustRun();
            self::assertSame('', $process->getErrorOutput());
            $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame([], $result['outside_context']);
            self::assertTrue($result['scope_restored']);

            return $result;
        };
    }

    private static function assertFailureLinks(array $workers, array $observed, string $kind, bool $enabled): void
    {
        $spans = array_merge(...array_column($workers, 'spans'));
        if (! $enabled) {
            self::assertSame([], $spans);
            self::assertSame([], $observed['outcome_contexts']);
            self::assertSame(0, array_sum(array_map(static fn (array $state): int =>
                $state['failure_outcomes']['task_outcome_queries'], $workers)));
            return;
        }
        $callbacks = array_values(array_filter($spans, static fn (array $span): bool =>
            $span['name'] === ($kind === 'local' ? 'durable.local_activity.attempt' : 'durable.activity.attempt')));
        self::assertCount(1, $callbacks);
        self::assertSame(StatusCode::STATUS_ERROR, $callbacks[0]['status_code']);
        $events = array_column($observed['outcome_events'], null, 'type');
        self::assertSame($observed['attempts'][0]['id'], $events['ActivityFailed']['payload']['activity_attempt_id']);
        $failure = $events['ActivityFailed'];
        self::assertSame($failure['payload']['activity_attempt_id'], $callbacks[0]['fields']['activity_attempt_id']);
        self::assertSame($failure['task_id'], $callbacks[0]['fields']['task_id']);
        $final = array_values(array_filter($spans, static fn (array $span): bool =>
            $span['name'] === 'durable.workflow.task'
                && $span['fields']['task_id'] === $events['WorkflowCompleted']['task_id']));
        self::assertCount(1, $final);
        $links = [];
        foreach ($final[0]['links'] as $link) {
            if (($link['fields']['durable.link.type'] ?? null) === 'task_outcome') {
                $links[$link['fields']['history_event_id']] = $link;
            }
        }
        self::assertCount(2, $links);
        self::assertArrayHasKey($failure['id'], $links);
        self::assertSame($callbacks[0]['trace_id'], $links[$failure['id']]['trace_id']);
        self::assertSame($callbacks[0]['span_id'], $links[$failure['id']]['span_id']);
        self::assertSame($failure['task_id'], $links[$failure['id']]['fields']['task_id']);
        self::assertArrayHasKey($events['TimerFired']['id'], $links);
        self::assertStringNotContainsString(
            'deliberate terminal activity failure',
            json_encode($spans, JSON_THROW_ON_ERROR)
        );
    }
}
