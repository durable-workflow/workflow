<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Fixtures\TestNestedSignalLeafWorkflow;
use Tests\Fixtures\TestNestedSignalParentWorkflow;
use Tests\TestCase;
use Workflow\Models\StoredWorkflow;
use Workflow\States\WorkflowCompletedStatus;
use Workflow\WorkflowStub;

final class NestedSignalRaceConditionTest extends TestCase
{
    public function testNestedChildWorkflowsWithDuplicateSignalsDoNotGetStuckPending(): void
    {
        $runId = (int) now()
            ->format('Uu');
        $middleCount = 12;
        $leafCount = 3;
        $duplicateSignals = 4;
        $expectedLeafCount = $middleCount * $leafCount;

        $workflow = WorkflowStub::make(TestNestedSignalParentWorkflow::class);
        $workflow->start($runId, $middleCount, $leafCount);

        $leafIds = [];
        $this->waitForWorkflow(
            $workflow,
            static function (WorkflowStub $_workflow) use (&$leafIds, $expectedLeafCount): bool {
                $leafIds = StoredWorkflow::query()
                    ->where('class', TestNestedSignalLeafWorkflow::class)
                    ->pluck('id')
                    ->all();

                return count($leafIds) === $expectedLeafCount;
            },
            'all nested leaf workflows to be created',
            30.0,
        );

        $this->assertCount($expectedLeafCount, $leafIds, 'Timed out waiting for all nested leaf workflows');

        for ($round = 0; $round < $duplicateSignals; $round++) {
            foreach ($leafIds as $leafId) {
                WorkflowStub::load((int) $leafId)->respond();
            }
        }

        // This stress case deliberately fans out to 36 child workflows and
        // 144 duplicate signals, so retain its original two-minute budget.
        $this->waitForWorkflow($workflow, timeoutSeconds: 120.0);

        $this->assertSame(WorkflowCompletedStatus::class, $workflow->status());
        $this->assertSame([
            'run_id' => $runId,
            'middle_count' => $middleCount,
            'leaf_count' => $leafCount,
            'resolved_leaf_count' => $expectedLeafCount,
        ], $workflow->output());
    }
}
