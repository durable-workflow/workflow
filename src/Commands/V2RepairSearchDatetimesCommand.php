<?php

declare(strict_types=1);

namespace Workflow\Commands;

use DomainException;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;
use Throwable;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\ConfiguredV2Models;
use Workflow\V2\Support\SearchAttributeDatetimeRepair;

final class V2RepairSearchDatetimesCommand extends Command
{
    protected $signature = 'workflow:v2:repair-search-datetimes
        {run : The exact workflow run id whose datetime projections should be inspected}
        {--namespace= : Require the selected run to belong to this namespace}
        {--key=* : Inspect only these datetime attribute keys}
        {--apply : Repair the selected attributes after locking and validating current evidence}
        {--json : Output the repair report as JSON}';

    protected $description = 'Preview or repair selected datetime search attributes from retained typed history';

    public function handle(SearchAttributeDatetimeRepair $repair): int
    {
        $status = self::SUCCESS;

        try {
            $model = ConfiguredV2Models::resolve('run_model', WorkflowRun::class);
            $query = $model::query()->whereKey((string) $this->argument('run'));
            $namespace = $this->option('namespace');

            if ($namespace !== null) {
                if (! is_string($namespace) || $namespace === '') {
                    throw new InvalidArgumentException('An explicit namespace must be a non-empty string.');
                }

                $query->where('namespace', $namespace);
            }

            $keys = $this->option('key');

            if (! is_array($keys)) {
                throw new InvalidArgumentException('Datetime keys must be a list of strings.');
            }

            foreach ($keys as $key) {
                if (! is_string($key) || $key === '') {
                    throw new InvalidArgumentException('Each selected datetime key must be a non-empty string.');
                }
            }

            $keys = array_values(array_unique($keys));
            $report = $repair->repair($query->firstOrFail(), (bool) $this->option('apply'), $keys);

            if ((bool) $this->option('json')) {
                $this->line(json_encode($report, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            } else {
                $this->table(['Run', 'Examined', 'Would repair', 'Repaired', 'Already correct'], [[
                    $report['run_id'], $report['examined'], $report['would_repair'],
                    $report['repaired'], $report['already_correct'],
                ]]);
            }
        } catch (ModelNotFoundException) {
            $status = $this->failure('The selected run was not found in the requested namespace.', 'refused');
        } catch (DomainException|InvalidArgumentException $exception) {
            $status = $this->failure($exception->getMessage(), 'refused');
        } catch (Throwable) {
            $status = $this->failure(
                'Repair could not be verified. Inspect the selected projections before retrying.',
                'unverified',
            );
        }

        return $status;
    }

    private function failure(string $message, string $status): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'status' => $status,
                'message' => $message,
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->error($message);
        }

        return self::FAILURE;
    }
}
