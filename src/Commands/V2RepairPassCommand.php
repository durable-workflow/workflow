<?php

declare(strict_types=1);

namespace Workflow\Commands;

use Illuminate\Console\Command;
use JsonException;
use Symfony\Component\Console\Attribute\AsCommand;
use Workflow\V2\Contracts\MatchingRole;
use Workflow\V2\Support\TaskRepairPolicy;

#[AsCommand(name: 'workflow:v2:repair-pass')]
class V2RepairPassCommand extends Command
{
    protected $signature = 'workflow:v2:repair-pass
        {--run-id=* : Limit the sweep to one or more workflow run ids}
        {--instance-id= : Limit the sweep to one workflow instance id}
        {--connection= : Limit the repair pass and record the heartbeat against a queue connection scope}
        {--queue= : Limit the repair pass and record the heartbeat against a queue scope}
        {--respect-throttle : Respect the queue-loop repair throttle instead of forcing a repair pass}
        {--loop : Run the repair pass on a loop as a dedicated matching-role daemon until interrupted}
        {--sleep-seconds= : Seconds to sleep between loop iterations (defaults to the configured loop throttle)}
        {--max-iterations= : Stop after this many loop iterations instead of running until interrupted}
        {--json : Output each repair pass report as JSON}';

    protected $description = 'Run one Workflow v2 repair sweep, or run a dedicated matching-role daemon loop with --loop, using the current repair candidate scan and backoff policy';

    /**
     * Set to true when a SIGTERM/SIGINT is observed so the loop exits at the
     * next iteration boundary instead of mid-sleep.
     */
    private bool $shouldStop = false;

    public function __construct(
        private readonly MatchingRole $matchingRole,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if ((bool) $this->option('loop')) {
            return $this->runLoop();
        }

        return $this->runOnce(respectThrottleOverride: null);
    }

    /**
     * Sleep between loop iterations. Extracted so tests can override the
     * sleep without slowing the suite.
     */
    protected function sleepBetweenIterations(int $sleepSeconds): void
    {
        if ($sleepSeconds <= 0) {
            return;
        }

        sleep($sleepSeconds);
    }

    /**
     * Run a single repair pass and emit the report.
     */
    private function runOnce(?bool $respectThrottleOverride): int
    {
        $report = $this->matchingRole->runPass(
            $this->stringOption('connection'),
            $this->stringOption('queue'),
            respectThrottle: $respectThrottleOverride ?? (bool) $this->option('respect-throttle'),
            runIds: $this->runIds(),
            instanceId: $this->stringOption('instance-id'),
        );

        if ((bool) $this->option('json')) {
            try {
                $this->line(json_encode($report, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            } catch (JsonException $exception) {
                $this->error($exception->getMessage());

                return self::FAILURE;
            }
        } else {
            $this->renderHumanReport($report);
        }

        return $report['existing_task_failures'] === []
            && $report['missing_run_failures'] === []
            ? self::SUCCESS
            : self::FAILURE;
    }

    /**
     * Run the dedicated matching-role daemon loop. Each iteration is a
     * full repair pass that respects the loop throttle so cooperating
     * matching-role processes do not duplicate work, followed by a
     * configurable sleep before the next iteration.
     *
     * The loop exits cleanly on SIGTERM/SIGINT (when the runtime supports
     * pcntl signal handling) or after --max-iterations iterations, which
     * tests use to drive the daemon deterministically.
     */
    private function runLoop(): int
    {
        $maxIterationsOption = $this->option('max-iterations');
        $maxIterations = is_string($maxIterationsOption) && $maxIterationsOption !== ''
            ? max(1, (int) $maxIterationsOption)
            : null;

        $sleepSeconds = $this->resolveSleepSeconds();

        // SIGTERM/SIGINT are PCNTL-extension constants; the closure form keeps
        // the signal list out of bytecode when the runtime lacks pcntl, so the
        // command still loops cleanly on environments without signal support.
        $this->trap(static fn (): array => [SIGTERM, SIGINT], function (): void {
            $this->shouldStop = true;
        },);

        $exitCode = self::SUCCESS;
        $iteration = 0;

        while (! $this->shouldStop) {
            $iteration++;

            $iterationExit = $this->runOnce(respectThrottleOverride: true);

            if ($iterationExit !== self::SUCCESS) {
                $exitCode = $iterationExit;
            }

            if ($maxIterations !== null && $iteration >= $maxIterations) {
                break;
            }

            if ($this->shouldStop) {
                break;
            }

            $this->sleepBetweenIterations($sleepSeconds);
        }

        return $exitCode;
    }

    private function resolveSleepSeconds(): int
    {
        $option = $this->option('sleep-seconds');

        if (is_string($option) && $option !== '') {
            return max(0, (int) $option);
        }

        return TaskRepairPolicy::loopThrottleSeconds();
    }

    /**
     * @param array{
     *     connection: string|null,
     *     queue: string|null,
     *     run_ids: list<string>,
     *     instance_id: string|null,
     *     respect_throttle: bool,
     *     throttled: bool,
     *     selected_existing_task_candidates: int,
     *     selected_missing_task_candidates: int,
     *     selected_total_candidates: int,
     *     repaired_existing_tasks: int,
     *     repaired_missing_tasks: int,
     *     dispatched_tasks: int,
     *     existing_task_failures: list<array{candidate_id: string, message: string}>,
     *     missing_run_failures: list<array{run_id: string, message: string}>,
     *     deadline_expired_failures: list<array{run_id: string, message: string}>,
     *     activity_timeout_failures: list<array{execution_id: string, message: string}>
     * } $report
     */
    private function renderHumanReport(array $report): void
    {
        if ($report['throttled']) {
            $this->warn('Skipped repair pass because the watchdog loop throttle is already held.');

            return;
        }

        $this->line('Workflow v2 repair pass completed.');
        $this->line(sprintf(
            'Selected %d existing task candidate(s) and %d missing-task run candidate(s).',
            $report['selected_existing_task_candidates'],
            $report['selected_missing_task_candidates'],
        ));
        $this->line(sprintf(
            'Repaired %d existing task(s), %d missing task(s), and dispatched %d task(s).',
            $report['repaired_existing_tasks'],
            $report['repaired_missing_tasks'],
            $report['dispatched_tasks'],
        ));
        foreach ($report['existing_task_failures'] as $failure) {
            $this->error(sprintf(
                'Existing task candidate [%s] failed repair: %s',
                $failure['candidate_id'],
                $failure['message'],
            ));
        }

        foreach ($report['missing_run_failures'] as $failure) {
            $this->error(sprintf(
                'Missing-task run [%s] failed repair: %s',
                $failure['run_id'],
                $failure['message'],
            ));
        }

        foreach ($report['deadline_expired_failures'] as $failure) {
            $this->error(sprintf(
                'Deadline-expired run [%s] failed repair: %s',
                $failure['run_id'],
                $failure['message'],
            ));
        }

        foreach ($report['activity_timeout_failures'] as $failure) {
            $this->error(sprintf(
                'Activity timeout candidate [%s] failed enforcement: %s',
                $failure['execution_id'],
                $failure['message'],
            ));
        }
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @return list<string>
     */
    private function runIds(): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): string => is_scalar($value) ? trim((string) $value) : '',
            (array) $this->option('run-id'),
        ))));
    }
}
