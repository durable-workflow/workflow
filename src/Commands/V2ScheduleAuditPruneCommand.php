<?php

declare(strict_types=1);

namespace Workflow\Commands;

use Illuminate\Console\Command;
use LogicException;
use Workflow\V2\Models\WorkflowSchedule;
use Workflow\V2\Support\ConfiguredV2Models;
use Workflow\V2\Support\NamespaceIdentity;
use Workflow\V2\Support\ScheduleAuditRetention;

class V2ScheduleAuditPruneCommand extends Command
{
    protected $signature = 'workflow:v2:schedule-audit-prune
        {--namespace= : Exact namespace; defaults to configured namespace or default}
        {--null-namespace : Select only legacy NULL-namespace rows}
        {--schedule= : Compact this existing schedule ID instead of cleaning orphaned rows}
        {--limit=500 : Maximum orphan rows deleted in this pass (1 to 1000)}';

    protected $description = 'Bound schedule audit snapshots or clean audit rows left by purged schedules';

    public function handle(): int
    {
        $namespace = $this->option('namespace');
        $nullNamespace = (bool) $this->option('null-namespace');
        if ($nullNamespace && $namespace !== null) {
            $this->error('--namespace and --null-namespace cannot be combined.');
            return self::FAILURE;
        }
        $namespace = $nullNamespace ? null : ($namespace ?? config('workflows.v2.namespace') ?? 'default');
        if ($namespace !== null && (! is_string($namespace) || strlen($namespace) > 255
            || preg_match('/(*UCP)\S/u', $namespace) !== 1)) {
            $this->error('The selected namespace must be a nonempty UTF-8 string within 255 bytes.');
            return self::FAILURE;
        }
        $limit = $this->option('limit');
        if (is_string($limit) && preg_match('/\A[1-9][0-9]*\z/D', $limit) === 1) {
            $limit = filter_var($limit, FILTER_VALIDATE_INT);
        }
        if (! is_int($limit) || $limit < 1 || $limit > 1000) {
            $this->error('--limit must be an integer between 1 and 1000.');
            return self::FAILURE;
        }

        try {
            $id = $this->option('schedule');
            if ($id === null) {
                $report = ScheduleAuditRetention::cleanup($namespace, $limit);
            } else {
                $schedule = NamespaceIdentity::constrain(
                    ConfiguredV2Models::query('schedule_model', WorkflowSchedule::class)->withoutGlobalScopes(),
                    $namespace,
                )->where('schedule_id', $id)
                    ->first();
                if ($schedule === null || $schedule->schedule_id !== $id) {
                    $this->error('The exact schedule ID does not exist in the selected namespace.');
                    return self::FAILURE;
                }
                $report = ScheduleAuditRetention::pruneWithReport($schedule);
            }
            $this->line(json_encode($report, JSON_THROW_ON_ERROR));
        } catch (LogicException $error) {
            $this->error($error->getMessage());
            return self::FAILURE;
        }
        return self::SUCCESS;
    }
}
