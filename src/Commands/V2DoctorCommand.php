<?php

declare(strict_types=1);

namespace Workflow\Commands;

use Illuminate\Console\Command;
use JsonException;
use Symfony\Component\Console\Attribute\AsCommand;
use Workflow\V2\Support\BackendCapabilities;
use Workflow\V2\Support\MatchingRoleSnapshot;

#[AsCommand(name: 'workflow:v2:doctor')]
class V2DoctorCommand extends Command
{
    protected $signature = 'workflow:v2:doctor
        {--json : Output the capability snapshot as JSON}
        {--strict : Exit with failure when required capabilities are missing}';

    protected $description = 'Inspect Workflow v2 backend capabilities and the local matching-role deployment shape';

    public function handle(): int
    {
        $snapshot = BackendCapabilities::snapshot();
        $snapshot['matching_role'] = MatchingRoleSnapshot::current();

        if ((bool) $this->option('json')) {
            try {
                $this->line(json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            } catch (JsonException $exception) {
                $this->error($exception->getMessage());

                return self::FAILURE;
            }
        } else {
            $this->renderHumanSnapshot($snapshot);
        }

        if ((bool) $this->option('strict') && ! BackendCapabilities::isSupported($snapshot)) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    private function renderHumanSnapshot(array $snapshot): void
    {
        $this->line('Workflow v2 backend capabilities');

        $this->componentLine('database', $snapshot['database'] ?? []);
        $this->componentLine('queue', $snapshot['queue'] ?? []);
        $this->componentLine('cache', $snapshot['cache'] ?? []);
        $this->componentLine('codec', $snapshot['codec'] ?? []);
        $this->matchingRoleLine($snapshot['matching_role'] ?? null);

        $issues = $snapshot['issues'] ?? [];

        if (! is_array($issues) || $issues === []) {
            $this->info('No blocking backend capability issues were detected.');

            return;
        }

        foreach ($issues as $issue) {
            if (! is_array($issue)) {
                continue;
            }

            $severity = (string) ($issue['severity'] ?? 'warn');
            $message = sprintf(
                '[%s] [%s] %s',
                strtoupper($severity),
                (string) ($issue['code'] ?? 'capability_issue'),
                (string) ($issue['message'] ?? 'Capability issue detected.'),
            );

            match ($severity) {
                'error' => $this->error($message),
                'info' => $this->line($message),
                default => $this->warn($message),
            };
        }
    }

    private function componentLine(string $name, mixed $component): void
    {
        if (! is_array($component)) {
            $this->line(sprintf('[FAIL] %s: unavailable', $name));

            return;
        }

        $status = ($component['supported'] ?? false) === true ? 'OK' : 'FAIL';
        $identity = match ($name) {
            'database' => sprintf(
                '%s/%s',
                $component['connection'] ?? 'unknown',
                $component['driver'] ?? 'unknown',
            ),
            'queue' => sprintf('%s/%s', $component['connection'] ?? 'unknown', $component['driver'] ?? 'unknown'),
            'cache' => sprintf('%s/%s', $component['store'] ?? 'unknown', $component['driver'] ?? 'unknown'),
            'codec' => (string) ($component['canonical'] ?? 'unknown'),
            default => 'unknown',
        };

        $this->line(sprintf('[%s] %s: %s', $status, $name, $identity));
    }

    private function matchingRoleLine(mixed $matchingRole): void
    {
        if (! is_array($matchingRole)) {
            $this->line('[INFO] matching_role: unavailable');

            return;
        }

        $shape = is_string($matchingRole['shape'] ?? null) ? $matchingRole['shape'] : 'unknown';
        $queueWakeEnabled = ($matchingRole['queue_wake_enabled'] ?? false) === true ? 'true' : 'false';
        $wakeOwner = is_string($matchingRole['wake_owner'] ?? null) ? $matchingRole['wake_owner'] : 'unknown';
        $dispatchMode = is_string($matchingRole['task_dispatch_mode'] ?? null)
            ? $matchingRole['task_dispatch_mode']
            : 'unknown';

        $this->line(sprintf(
            '[INFO] matching_role: %s (queue_wake_enabled=%s, wake_owner=%s, task_dispatch_mode=%s)',
            $shape,
            $queueWakeEnabled,
            $wakeOwner,
            $dispatchMode,
        ));
    }
}
