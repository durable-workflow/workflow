<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\DatabaseServiceProvider;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use Throwable;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;

/** @internal Metadata-only SQL process. It never loads an activity or its arguments. */
final class EmbeddedActivityLeaseMaintainer
{
    private string $buffer = '';

    private bool $lost = false;

    private int $nextRenewal = 0;

    private readonly ActivityTaskClaim $claim;

    /**
     * @param array<string, mixed> $start
     */
    private function __construct(
        private readonly array $start
    ) {
        self::bootDatabase($start);
        $this->claim = $this->loadOriginalClaim();
        if (hrtime(true) >= $start['initial_deadline']) {
            throw new EmbeddedActivityLeaseUnavailable('The initial original grant expired during bootstrap.');
        }
    }

    public static function run(): void
    {
        $start = [];
        try {
            $line = fgets(STDIN, 131073);
            if ($line === false || ! str_ends_with($line, "\n")) {
                return;
            }
            $start = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($start) || ($start['operation'] ?? null) !== 'start') {
                return;
            }
            $maintainer = new self($start);
            stream_set_blocking(STDIN, false);
            $maintainer->renew();
            $maintainer->respond($start);
            $maintainer->loop();
        } catch (Throwable) {
            // SQL/bootstrap exceptions may contain credentials. Never relay them.
            self::write([
                'id' => is_array($start) ? ($start['id'] ?? 0) : 0,
                'can_continue' => false,
            ]);
        }
    }

    /**
     * @param array<string, mixed> $start
     */
    private static function bootDatabase(array $start): void
    {
        if (! is_array($start['connection'] ?? null) || ! is_int($start['parent_pid'] ?? null)
            || ! is_int($start['initial_deadline'] ?? null) || ! is_array($start['identity'] ?? null)
            || ! is_string($start['namespace'] ?? null) || ! is_string($start['timezone'] ?? null)
            || ! is_int($start['lease_seconds'] ?? null) || $start['lease_seconds'] < 1
            || $start['lease_seconds'] > ActivityLease::MAX_SECONDS
            || hrtime(true) >= $start['initial_deadline']) {
            throw new EmbeddedActivityLeaseUnavailable('Malformed original maintenance configuration.');
        }
        date_default_timezone_set($start['timezone']);
        $container = new Container();
        Container::setInstance($container);
        $container->instance('config', new Repository([
            'database' => [
                'default' => 'activity-maintenance',
                'connections' => [
                    'activity-maintenance' => $start['connection'],
                ],
            ],
            'workflows' => [
                'storage' => [
                    'connection' => 'activity-maintenance',
                ],
                'v2' => [
                    'activity_task_lease_seconds' => $start['lease_seconds'],
                ],
            ],
        ]));
        $container->instance('events', new Dispatcher($container));
        Facade::setFacadeApplication($container);
        $provider = new DatabaseServiceProvider($container);
        $provider->register();
        $provider->boot();
    }

    private function loadOriginalClaim(): ActivityTaskClaim
    {
        $identity = $this->start['identity'];
        foreach ([
            'workflow_task_id',
            'workflow_run_id',
            'activity_execution_id',
            'activity_attempt_id',
            'lease_owner',
        ] as $key) {
            if (! is_string($identity[$key] ?? null) || $identity[$key] === '') {
                throw new EmbeddedActivityLeaseUnavailable('Missing original claim identity.');
            }
        }
        $task = WorkflowTask::query()->useWritePdo()->findOrFail($identity['workflow_task_id']);
        $run = WorkflowRun::query()->useWritePdo()->findOrFail($identity['workflow_run_id']);
        $execution = ActivityExecution::query()->useWritePdo()->findOrFail($identity['activity_execution_id']);
        $attempt = ActivityAttempt::query()->useWritePdo()->findOrFail($identity['activity_attempt_id']);
        if ($task->task_type !== TaskType::Activity || $task->status !== TaskStatus::Leased
            || $task->workflow_run_id !== $run->getKey() || $execution->workflow_run_id !== $run->getKey()
            || $attempt->activity_execution_id !== $execution->getKey()
            || $attempt->workflow_task_id !== $task->getKey()
            || $task->namespace !== $this->start['namespace'] || $run->namespace !== $this->start['namespace']) {
            throw new EmbeddedActivityLeaseUnavailable('Stored activity claim relationships changed.');
        }
        $claim = new ActivityTaskClaim($task, $run, $execution, $attempt);
        if ($claim->identity() !== $identity) {
            throw new EmbeddedActivityLeaseUnavailable('Stored activity claim identity changed.');
        }

        return $claim;
    }

    private function loop(): void
    {
        while (! feof(STDIN) && $this->parentPresent()) {
            if (! $this->lost && hrtime(true) >= $this->nextRenewal) {
                try {
                    $this->renew();
                } catch (Throwable) {
                    // Failure is sticky. A later grant can never restore this helper.
                    $this->lost = true;
                }
            }
            foreach ($this->readCommands() as $command) {
                if (($command['operation'] ?? null) !== 'check') {
                    $this->lost = true;
                }
                $this->respond($command);
            }
            usleep(10000);
        }
    }

    private function renew(): void
    {
        $started = hrtime(true);
        if (! $this->parentPresent() || $started >= $this->claim->outcomeAdmissionDeadline()) {
            throw new EmbeddedActivityLeaseUnavailable('The original activity authority expired.');
        }
        $reply = (new DefaultActivityTaskBridge())->renewLease(
            $this->claim->attemptId(),
            $this->start['identity']['lease_owner']
        );
        if (! $this->claim->acknowledgeLeaseRenewal($reply, $started)) {
            throw new EmbeddedActivityLeaseUnavailable('The original activity renewal was refused.');
        }
        $now = hrtime(true);
        $this->nextRenewal = $now + min(
            10_000_000_000,
            intdiv(max(0, $this->claim->outcomeAdmissionDeadline() - $now), 3)
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readCommands(): array
    {
        $part = stream_get_contents(STDIN, 65537 - strlen($this->buffer));
        if ($part === false || strlen($part) + strlen($this->buffer) > 65536) {
            throw new EmbeddedActivityLeaseUnavailable('The private maintenance input exceeds its bound.');
        }
        $this->buffer .= $part;
        $commands = [];
        while (str_contains($this->buffer, "\n")) {
            [$line, $this->buffer] = explode("\n", $this->buffer, 2);
            $command = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($command)) {
                throw new EmbeddedActivityLeaseUnavailable('Malformed private maintenance command.');
            }
            $commands[] = $command;
        }

        return $commands;
    }

    /**
     * @param array<string, mixed> $command
     */
    private function respond(array $command): void
    {
        $now = hrtime(true);
        $until = $this->claim->outcomeAdmissionDeadline();
        if ($this->lost || ! $this->parentPresent() || $now >= $until) {
            $this->lost = true;
            self::write([
                'id' => $command['id'] ?? 0,
                'can_continue' => false,
            ]);

            return;
        }
        self::write([
            'id' => $command['id'] ?? 0,
            'can_continue' => true,
            'identity' => $this->claim->identity(),
            'until' => $until,
            'monotonic' => $now,
        ]);
    }

    private function parentPresent(): bool
    {
        return ! function_exists('posix_getppid') || posix_getppid() === $this->start['parent_pid'];
    }

    /**
     * @param array<string, mixed> $reply
     */
    private static function write(array $reply): void
    {
        $line = json_encode($reply, JSON_THROW_ON_ERROR) . "\n";
        if (fwrite(STDOUT, $line) !== strlen($line)) {
            throw new EmbeddedActivityLeaseUnavailable('The original activity process is unavailable.');
        }
        fflush(STDOUT);
    }
}
