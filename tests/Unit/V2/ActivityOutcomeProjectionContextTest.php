<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\ActivityOutcomeProjectionContext;

final class ActivityOutcomeProjectionContextTest extends TestCase
{
    public function testNestedProjectionsAreKeyedByRunAndClearedAfterFailure(): void
    {
        $run = new WorkflowRun();
        $run->setRawAttributes([
            'id' => 'activity-context-run',
        ]);
        $refreshedRun = new WorkflowRun();
        $refreshedRun->setRawAttributes([
            'id' => 'activity-context-run',
        ]);
        $otherRun = new WorkflowRun();
        $otherRun->setRawAttributes([
            'id' => 'activity-context-other',
        ]);
        $first = new WorkflowHistoryEvent();
        $second = new WorkflowHistoryEvent();

        $result = ActivityOutcomeProjectionContext::run($run, $first, function () use (
            $run,
            $refreshedRun,
            $otherRun,
            $first,
            $second,
        ): string {
            $this->assertSame($first, ActivityOutcomeProjectionContext::eventFor($refreshedRun));
            $this->assertNull(ActivityOutcomeProjectionContext::eventFor($otherRun));

            try {
                ActivityOutcomeProjectionContext::run($refreshedRun, $second, function () use (
                    $run,
                    $second,
                ): void {
                    $this->assertSame($second, ActivityOutcomeProjectionContext::eventFor($run));

                    throw new RuntimeException('projection interrupted');
                });
            } catch (RuntimeException $error) {
                $this->assertSame('projection interrupted', $error->getMessage());
            }

            $this->assertSame($first, ActivityOutcomeProjectionContext::eventFor($run));

            return 'projected';
        });

        $this->assertSame('projected', $result);
        $this->assertNull(ActivityOutcomeProjectionContext::eventFor($run));
    }
}
