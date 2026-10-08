<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Support\HistoryRecordedAt;

final class StringCastWorkflowHistoryEvent extends WorkflowHistoryEvent
{
    protected $casts = [
        'event_type' => 'string',
        'payload' => 'array',
        'recorded_at' => HistoryRecordedAt::class,
    ];
}
