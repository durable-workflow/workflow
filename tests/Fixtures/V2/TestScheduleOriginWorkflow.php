<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use function Workflow\V2\activity;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\continueAsNew;
use Workflow\V2\Support\ScheduleOrigin;
use Workflow\V2\Workflow;

#[Type('test-schedule-origin-workflow')]
final class TestScheduleOriginWorkflow extends Workflow
{
    /**
     * What workflow code read before its first command, kept for replay checks.
     */
    public ?array $firstTask = null;

    public function handle(bool $continueOnce = false): mixed
    {
        $this->firstTask = self::describe($this->scheduleOrigin());
        $echoed = activity(TestScheduleOriginActivity::class, $this->firstTask);

        if ($continueOnce) {
            return continueAsNew(false);
        }

        return [
            'first_task' => $echoed,
            'final' => self::describe($this->scheduleOrigin()),
            'run_id' => $this->runId(),
        ];
    }

    public static function describe(?ScheduleOrigin $origin): ?array
    {
        if ($origin === null) {
            return null;
        }

        return [
            'schedule_id' => $origin->scheduleId,
            'occurrence_time' => $origin->occurrenceTime?->format('Y-m-d\TH:i:s.uP'),
            'trigger_number' => $origin->triggerNumber,
        ];
    }
}
