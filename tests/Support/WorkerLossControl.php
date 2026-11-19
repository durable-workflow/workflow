<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Process;

/**
 * Parent side of the real process-loss controls: one worker process dies by SIGKILL at its armed provider cut,
 * then a different fresh process resumes from the shared application database.
 */
trait WorkerLossControl
{
    private function requireWorkerLossSupport(): void
    {
        if (! function_exists('posix_kill')) {
            $this->markTestSkipped('The worker-loss control requires POSIX signals.');
        }
        if (DB::connection()->getConfig('database') === ':memory:') {
            $this->markTestSkipped('A replacement worker process needs the shared application database.');
        }
    }

    /**
     * @param Closure(array, array): array $whileLost Gets the crash report and input while no worker is alive;
     *                                         returns the replacement worker's input.
     * @return array{0: array, 1: array} The crash report and the replacement worker's report.
     */
    private function loseWorkerThenRecover(string $script, string $variable, array $data, Closure $whileLost): array
    {
        $input = tempnam(sys_get_temp_dir(), 'worker-loss-input-');
        $report = tempnam(sys_get_temp_dir(), 'worker-loss-report-');
        chmod($input, 0600);
        chmod($report, 0600);
        $process = null;
        $run = static function (array $phase) use ($script, $variable, $input, $report, &$process): Process {
            file_put_contents($input, json_encode([
                ...$phase,
                'database' => DB::connection()->getConfig(),
                'redis' => config('database.redis'),
                'cache' => config('cache'),
                'report' => $report,
            ], JSON_THROW_ON_ERROR));
            file_put_contents($report, '');
            $process = new Process([PHP_BINARY, 'vendor/bin/phpunit', $script], dirname(__DIR__, 2), [
                $variable => $input,
            ], timeout: 300);
            $process->start();
            return $process;
        };
        try {
            $crashed = $run([
                ...$data,
                'phase' => 'crash',
            ]);
            $pid = $crashed->getPid();
            try {
                $crashed->wait();
                $this->fail('The first worker must die at its selected provider cut. ' . $crashed->getOutput());
            } catch (ProcessSignaledException $error) {
                $this->assertSame(SIGKILL, $error->getProcess()->getTermSignal());
            }
            $cut = json_decode(file_get_contents($report), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($pid, $cut['pid']);
            $this->assertNotSame(getmypid(), $pid);
            $resumed = $run([
                ...$whileLost($cut, $data),
                'phase' => 'resume',
            ]);
            $resumed->wait();
            $this->assertTrue($resumed->isSuccessful(), $resumed->getOutput() . $resumed->getErrorOutput());
            $result = json_decode(file_get_contents($report), true, flags: JSON_THROW_ON_ERROR);
            $this->assertNotSame($pid, $result['pid']);
            return [$cut, $result];
        } finally {
            if ($process !== null && $process->isRunning()) {
                $process->stop(0, SIGKILL);
            }
            unlink($input);
            unlink($report);
        }
    }
}
