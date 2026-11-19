<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;

#[Type('test-schedule-origin-window-workflow')]
final class TestScheduleOriginWindowWorkflow extends Workflow
{
    public function handle(): ?array
    {
        $origin = $this->scheduleOrigin();

        if ($origin === null) {
            return null;
        }

        return [
            'schedule_id' => $origin->scheduleId,
            'window_end' => $origin->occurrenceTime?->format('Y-m-d\TH:i:s.uP'),
            'trigger_number' => $origin->triggerNumber,
        ];
    }
}
