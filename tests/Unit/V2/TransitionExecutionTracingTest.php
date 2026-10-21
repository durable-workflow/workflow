<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Composer\Autoload\ClassLoader;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class TransitionExecutionTracingTest extends TestCase
{
    public static function transitions(): array
    {
        return [
            'child, tracing enabled' => ['child', true, true],
            'child, tracing disabled' => ['child', false, false],
            'continuation, tracing enabled' => ['continue', true, true],
            'continuation, tracing disabled' => ['continue', false, false],
            'legacy child, enabled later' => ['child', true, false],
            'legacy continuation, enabled later' => ['continue', true, false],
            'child, disabled workers' => ['child', false, true],
            'continuation, disabled workers' => ['continue', false, true],
            'child, wrong task carrier' => ['child', true, true, 'wrong task'],
            'child, wrong namespace carrier' => ['child', true, true, 'wrong namespace'],
            'child failure, tracing enabled' => ['child-failure', true, true],
            'child failure, tracing disabled' => ['child-failure', false, false],
            'legacy child failure, enabled later' => ['child-failure', true, false],
            'child failure, disabled workers' => ['child-failure', false, true],
        ];
    }

    #[DataProvider('transitions')]
    public function testColdSuccessorRetainsTheAdmissionCarrierWithoutChangingDurableExecution(
        string $transition,
        bool $enabled,
        bool $admissionEnabled,
        string $control = 'valid',
    ): void {
        $directory = sys_get_temp_dir() . '/dw-tracing-transition-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $worker = static function (string $mode) use (
            $directory,
            $transition,
            $enabled,
            $admissionEnabled,
            $control
        ): array {
            $packageVendor = dirname(__DIR__, 3) . '/vendor';
            $otherVendors = array_filter(
                array_keys(ClassLoader::getRegisteredLoaders()),
                static fn (string $vendor): bool => $vendor !== $packageVendor
            );
            $process = new Process([
                PHP_BINARY, __DIR__ . '/../../Fixtures/V2/tracing-process.php', $directory, $mode, 'once',
                ...array_map(
                    static fn (string $vendor): string => $vendor . '/autoload.php',
                    [...$otherVendors, $packageVendor]
                ),
            ], env: [
                'DW_TEST_TRACING_TRANSITION' => $transition,
                'DW_TEST_TRACING_CHILD_OUTCOME_CONTROL' => $control,
                'DW_TEST_TRACING_ENABLED' => ($mode === 'admit' ? $admissionEnabled : $enabled) ? 'true' : 'false',
            ]);
            $process->setTimeout(15);
            $process->mustRun();
            self::assertSame('', $process->getErrorOutput());
            $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame([], $result['outside_context']);
            self::assertTrue($result['scope_restored']);

            return $result;
        };
        try {
            $admitted = $worker('admit');
            self::assertSame($admissionEnabled, $admitted['carrier'] !== null);
            $scheduled = $worker('work');
            self::assertCount(2, $scheduled['runs']);
            foreach ($scheduled['runs'] as $run) {
                self::assertSame($admitted['carrier'], $run['carrier'], json_encode($run, JSON_THROW_ON_ERROR));
            }
            $workers = [$scheduled];
            $altered = false;
            for ($remaining = 8; $remaining > 0; --$remaining) {
                $state = $worker('inspect');
                if ($state['jobs'] === 0) {
                    break;
                }
                $childClosed = array_filter($state['transition_events'], static fn (array $event): bool =>
                    $event['type'] === 'WorkflowCompleted' && $event['run_id'] !== $admitted['run_id']);
                if ($control !== 'valid' && ! $altered && $childClosed !== []) {
                    $changed = $worker('invalidate-child-outcome');
                    self::assertSame($state['transition_events'], $changed['transition_events']);
                    self::assertSame($state['runs'], $changed['runs']);
                    self::assertNotNull($changed['child_outcome_control']);
                    $altered = true;
                }
                $workers[] = $worker('work');
            }
            $finished = $worker('inspect');
            self::assertSame($control !== 'valid', $altered);
            self::assertSame('completed', $finished['status']);
            self::assertSame([9007199254740993, null, '雪'], $finished['output']);
            self::assertSame(0, $finished['jobs']);
            self::assertSame(0, $finished['failed_jobs']);
            self::assertSame(0, $finished['repairs']);
            self::assertSame($transition === 'child-failure' ? 0 : 1, $finished['activity_count']);
            self::assertSame($transition === 'child-failure' ? 0 : 1, $finished['completion_count']);
            self::assertCount(2, $finished['runs']);
            foreach ($scheduled['runs'] as $index => $run) {
                foreach (['id', 'instance_id', 'carrier', 'execution_deadline', 'run_deadline'] as $key) {
                    self::assertSame($run[$key], $finished['runs'][$index][$key]);
                }
                self::assertSame($run['history_hashes'], array_slice(
                    $finished['runs'][$index]['history_hashes'],
                    0,
                    count($run['history_hashes']),
                ));
            }
            if ($transition === 'continue') {
                self::assertNotSame($admitted['run_id'], $finished['run_id']);
                self::assertSame($admitted['execution_deadline'], $finished['execution_deadline']);
                self::assertSame('completed', $finished['runs'][0]['status']);
                self::assertSame('continued', $finished['runs'][0]['closed_reason']);
                self::assertSame($finished['runs'][0]['instance_id'], $finished['runs'][1]['instance_id']);
            } else {
                self::assertSame($admitted['run_id'], $finished['run_id']);
                self::assertSame('completed', $finished['runs'][0]['status']);
                self::assertNotSame($finished['runs'][0]['instance_id'], $finished['runs'][1]['instance_id']);
            }
            self::assertSame(count($workers), count(array_unique(array_column($workers, 'pid'))));
            $spans = array_merge(...array_column($workers, 'execution_spans'));
            if ($enabled) {
                $observedRuns = [];
                foreach ($spans as $span) {
                    if ($admissionEnabled) {
                        self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $span['trace_id']);
                        self::assertSame('00f067aa0ba902b7', $span['parent_id']);
                    } else {
                        self::assertSame('0000000000000000', $span['parent_id']);
                        self::assertNotSame('11111111111111111111111111111111', $span['trace_id']);
                    }
                    $observedRuns[] = $span['fields']['workflow_run_id'];
                }
                foreach ($finished['runs'] as $run) {
                    self::assertContains($run['id'], $observedRuns);
                }
            } else {
                self::assertSame([], $spans);
            }
            self::assertStringNotContainsString(
                'observability_context',
                json_encode($finished['history_payloads'], JSON_THROW_ON_ERROR)
            );
            if ($transition !== 'continue') {
                self::assertChildOutcomeLinks(
                    $workers,
                    $finished,
                    $enabled,
                    $control === 'valid',
                    $transition === 'child-failure'
                );
            }
        } finally {
            (new Filesystem())->deleteDirectory($directory);
        }
    }

    private static function assertChildOutcomeLinks(
        array $workers,
        array $finished,
        bool $enabled,
        bool $expected,
        bool $failed,
    ): void {
        $spans = array_merge(...array_column($workers, 'spans'));
        if (! $enabled) {
            self::assertSame([], $spans);
            return;
        }
        $events = $finished['transition_events'];
        $childType = $failed ? 'WorkflowFailed' : 'WorkflowCompleted';
        $child = array_values(array_filter($events, static fn (array $event): bool =>
            $event['type'] === $childType && $event['run_id'] !== $finished['run_id']))[0];
        $parent = array_values(array_filter($events, static fn (array $event): bool =>
            $event['type'] === 'WorkflowCompleted' && $event['run_id'] === $finished['run_id']))[0];
        $resolved = array_values(array_filter($events, static fn (array $event): bool =>
            $event['type'] === ($failed ? 'ChildRunFailed' : 'ChildRunCompleted')))[0];
        self::assertSame($child['run_id'], $resolved['payload']['child_workflow_run_id']);
        $childSpan = array_values(array_filter($spans, static fn (array $span): bool =>
            $span['name'] === 'durable.workflow.task' && $span['fields']['task_id'] === $child['task_id']))[0];
        $parentSpan = array_values(array_filter($spans, static fn (array $span): bool =>
            $span['name'] === 'durable.workflow.task' && $span['fields']['task_id'] === $parent['task_id']))[0];
        $links = array_values(array_filter($parentSpan['links'], static fn (array $link): bool =>
            ($link['fields']['durable.link.type'] ?? null) === 'child_outcome'));
        self::assertCount($expected ? 1 : 0, $links);
        if (! $expected) {
            return;
        }
        self::assertSame($childSpan['trace_id'], $links[0]['trace_id']);
        self::assertSame($childSpan['span_id'], $links[0]['span_id']);
        self::assertSame($child['id'], $links[0]['fields']['history_event_id']);
        self::assertSame($child['run_id'], $links[0]['fields']['child_workflow_run_id']);
        self::assertSame($child['task_id'], $links[0]['fields']['task_id']);
        self::assertSame($resolved['id'], $links[0]['fields']['parent_resolution_event_id']);
    }
}
