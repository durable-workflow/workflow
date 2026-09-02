<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use RuntimeException;
use Tests\NonDatabaseTestCase;
use Workflow\V2\Support\ActivityCall;
use Workflow\V2\Support\DurableOperationHandle;
use Workflow\V2\Support\SelectionResult;

final class SelectionResultTest extends NonDatabaseTestCase
{
    public function testFailedWinnerExposesRemainingHandlesAndRethrowsItsFailure(): void
    {
        $winner = new DurableOperationHandle(
            'winner',
            0,
            'activity',
            'activity-1',
            1,
            1,
            'select-calls:1:2',
            new ActivityCall('FailingActivity', []),
        );
        $remaining = new DurableOperationHandle(
            'remaining',
            1,
            'activity',
            'activity-2',
            2,
            1,
            'select-calls:1:2',
            new ActivityCall('PendingActivity', []),
        );
        $failure = new RuntimeException('winner failed');
        $result = new SelectionResult(
            'winner',
            0,
            'activity',
            'activity-1',
            null,
            $failure,
            $winner,
            [
                'winner' => $winner,
                'remaining' => $remaining,
            ],
        );

        $this->assertFalse($result->succeeded());
        $this->assertSame([
            'remaining' => $remaining,
        ], $result->remaining());

        $this->expectExceptionObject($failure);

        $result->result();
    }
}
