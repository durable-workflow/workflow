<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Composer\Autoload\ClassLoader;
use Illuminate\Support\Facades\Date;
use Symfony\Component\Process\PhpExecutableFinder;
use Throwable;
use Workflow\V2\WorkflowStub;

/** @internal Maintains SQL metadata while application code stays in its original process. */
final class EmbeddedActivityLease
{
    /**
     * @var resource|null
     */
    private $process = null;

    /**
     * @var array<int, resource>
     */
    private array $pipes = [];

    private string $buffer = '';

    private int $sequence = 0;

    private readonly int $ownerPid;

    private bool $lost = false;

    private function __construct(
        private readonly ActivityTaskClaim $claim
    ) {
        $this->ownerPid = getmypid();
        $php = (new PhpExecutableFinder())->find(false);
        if (! is_string($php) || ! function_exists('proc_open')) {
            throw new EmbeddedActivityLeaseUnavailable(
                'Embedded activity maintenance requires proc_open and a PHP CLI binary.'
            );
        }
        $autoload = self::autoload();
        $this->process = proc_open(
            [$php, '-d', 'auto_prepend_file=', '-d', 'auto_append_file=',
                __DIR__ . '/embedded-activity-lease.php', $autoload],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w'],
            ],
            $this->pipes,
            options: [
                'bypass_shell' => true,
            ]
        );
        if (! is_resource($this->process)) {
            throw new EmbeddedActivityLeaseUnavailable('Could not start embedded activity maintenance.');
        }
        foreach ($this->pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }
        try {
            $this->exchange('start', [
                'parent_pid' => $this->ownerPid,
                'namespace' => $claim->run->namespace,
                'timezone' => date_default_timezone_get(),
                'connection' => $claim->task->getConnection()
                    ->getConfig(),
                'lease_seconds' => ActivityLease::seconds(),
                'identity' => $claim->identity(),
                'initial_deadline' => $claim->outcomeAdmissionDeadline(),
            ], $claim->outcomeAdmissionDeadline());
        } catch (Throwable $error) {
            $this->stop();
            throw $error;
        }
    }

    public function __destruct()
    {
        $this->stop();
    }

    public static function start(ActivityTaskClaim $claim): ?self
    {
        $connection = $claim->task->getConnection();
        // An in-memory database and uncommitted outer transaction have no
        // independent committed grant that another process can maintain.
        if (WorkflowStub::faked() || WorkflowStub::hasMock($claim->execution->activity_class) || Date::hasTestNow()
            || $connection->transactionLevel() !== 0
            || ($connection->getDriverName() === 'sqlite'
                && (! is_string($connection->getConfig('database'))
                    || str_contains($connection->getConfig('database'), ':memory:')
                    || str_contains($connection->getConfig('database'), 'mode=memory')))) {
            return null;
        }

        return new self($claim);
    }

    public function confirm(): void
    {
        $this->exchange('check', [], $this->claim->maintenanceRequestDeadline());
        // The callback is finished. Join the metadata writer before outcome
        // recording takes its own row locks, inside the confirmed grant.
        $this->stop();
    }

    public function stop(): void
    {
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        $this->pipes = [];
        if ($this->ownerPid !== getmypid()) {
            $this->process = null;

            return;
        }
        if (is_resource($this->process)) {
            $this->waitForStop();
            if ($this->running()) {
                proc_terminate($this->process);
                $this->waitForStop();
                if ($this->running()) {
                    proc_terminate($this->process, 9);
                }
            }
            proc_close($this->process);
        }
        $this->process = null;
    }

    private function waitForStop(): void
    {
        $until = hrtime(true) + 250_000_000;
        while ($this->running() && hrtime(true) < $until) {
            usleep(5000);
        }
    }

    /**
     * @phpstan-impure Process state changes independently of this PHP process.
     */
    private function running(): bool
    {
        return is_resource($this->process) && proc_get_status($this->process)['running'];
    }

    private static function autoload(): string
    {
        foreach (ClassLoader::getRegisteredLoaders() as $directory => $loader) {
            if (realpath((string) $loader->findFile(self::class)) === realpath(__FILE__)) {
                return $directory . '/autoload.php';
            }
        }
        throw new EmbeddedActivityLeaseUnavailable('Embedded activity maintenance requires its Composer autoloader.');
    }

    /**
     * @param array<string, mixed> $body
     */
    private function exchange(string $operation, array $body, int $deadline): void
    {
        if ($this->lost || $this->ownerPid !== getmypid()) {
            throw new EmbeddedActivityLeaseUnavailable('The original activity maintenance channel is unavailable.');
        }
        $started = hrtime(true);
        $deadline = min($deadline, $started + 5_000_000_000);
        try {
            if ($started >= $deadline) {
                throw new EmbeddedActivityLeaseUnavailable(
                    'The original activity budget expired before maintenance confirmation.'
                );
            }
            $id = ++$this->sequence;
            $line = json_encode([
                'id' => $id,
                'operation' => $operation,
            ] + $body, JSON_THROW_ON_ERROR) . "\n";
            if (strlen($line) > 131072) {
                throw new EmbeddedActivityLeaseUnavailable('The private maintenance configuration exceeds its bound.');
            }
            $this->write($line, $deadline);
            $reply = $this->read($deadline);
            if (! is_array($reply) || ($reply['id'] ?? null) !== $id
                || ! $this->claim->acknowledgeMaintenance($reply, $started)) {
                throw new EmbeddedActivityLeaseUnavailable('The original embedded activity grant was not confirmed.');
            }
        } catch (Throwable $error) {
            $this->lost = true;
            throw new EmbeddedActivityLeaseUnavailable(
                'Embedded activity maintenance became unavailable.',
                previous: $error
            );
        }
    }

    private function write(string $line, int $deadline): void
    {
        $offset = 0;
        while ($offset < strlen($line)) {
            $written = fwrite($this->pipes[0], substr($line, $offset));
            if ($written === false || hrtime(true) >= $deadline) {
                throw new EmbeddedActivityLeaseUnavailable(
                    'The activity maintenance request did not complete in time.'
                );
            }
            $offset += $written;
            if ($written === 0) {
                usleep(1000);
            }
        }
    }

    private function read(int $deadline): mixed
    {
        while (! str_contains($this->buffer, "\n")) {
            $part = stream_get_contents($this->pipes[1], 65537 - strlen($this->buffer));
            if ($part === false || feof($this->pipes[1]) || strlen($this->buffer) + strlen($part) > 65536
                || hrtime(true) >= $deadline) {
                throw new EmbeddedActivityLeaseUnavailable('The activity maintenance reply did not complete in time.');
            }
            $this->buffer .= $part;
            usleep(1000);
        }
        [$line, $this->buffer] = explode("\n", $this->buffer, 2);

        return json_decode($line, true, 32, JSON_THROW_ON_ERROR);
    }
}
