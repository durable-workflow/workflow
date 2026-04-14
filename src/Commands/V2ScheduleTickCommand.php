<?php

declare(strict_types=1);

namespace Workflow\Commands;

use Illuminate\Console\Command;
use JsonException;
use Symfony\Component\Console\Attribute\AsCommand;
use Workflow\V2\Support\ScheduleManager;

#[AsCommand(name: 'workflow:v2:schedule-tick')]
class V2ScheduleTickCommand extends Command
{
    protected $signature = 'workflow:v2:schedule-tick
        {--json : Output the tick report as JSON}';

    protected $description = 'Evaluate all due workflow v2 schedules and trigger matching workflows';

    public function handle(): int
    {
        $results = ScheduleManager::tick();

        if ((bool) $this->option('json')) {
            try {
                $this->line(json_encode($results, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            } catch (JsonException $exception) {
                $this->error($exception->getMessage());

                return self::FAILURE;
            }
        } else {
            if ($results === []) {
                $this->line('No schedules due.');
            } else {
                foreach ($results as $result) {
                    $instanceId = $result['instance_id'] ?? 'skipped';
                    $this->line(sprintf('[%s] → %s', $result['schedule_id'], $instanceId));
                }

                $this->line(sprintf('Processed %d schedule(s).', count($results)));
            }
        }

        return self::SUCCESS;
    }
}
