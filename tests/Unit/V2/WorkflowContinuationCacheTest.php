<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\WorkflowContinuation;
use Workflow\V2\Support\WorkflowContinuationCache;
use Workflow\V2\Support\WorkflowExecution;
use Workflow\V2\Workflow;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class WorkflowContinuationCacheTest extends TestCase
{
    public function testClaimingAFrameRemovesItBeforeItCanBeResumedAgain(): void
    {
        $cache = new WorkflowContinuationCache();
        $entry = $this->entry();
        $cache->publish('one', $cache->observe('one'), $entry);
        $this->assertSame($entry, $cache->take('one'));
        $this->assertNull($cache->take('one'));
        $this->assertSame(0, $cache->snapshot()['entries']);
    }

    public function testReentrantOlderCommitCannotReplaceANewerGeneration(): void
    {
        $cache = new WorkflowContinuationCache();
        $older = $cache->observe('one');
        $newer = $cache->observe('one');
        $expected = $this->entry();
        $cache->publish('one', $newer, $expected);
        $cache->publish('one', $older, $this->entry());
        $this->assertSame($expected, $cache->take('one'));
        $this->assertSame(1, $cache->snapshot()['counts']['discard_publication']);
    }

    public function testCapacityEvictsTheOldestEntryWithoutChangingOtherRuns(): void
    {
        $cache = new WorkflowContinuationCache();
        for ($index = 0; $index < 5; ++$index) {
            $key = 'run-' . $index;
            $cache->publish($key, $cache->observe($key), $this->entry());
        }
        $this->assertSame(4, $cache->snapshot()['entries']);
        $this->assertNull($cache->take('run-0'));
        $this->assertNotNull($cache->take('run-1'));
        $this->assertNotNull($cache->take('run-4'));
    }

    public function testExpiredMonotonicAgeSelectsAMiss(): void
    {
        $cache = new WorkflowContinuationCache();
        $entry = $this->entry();
        $cache->publish('one', $cache->observe('one'), $entry);
        $entry->retainedAt = hrtime(true) - 30_000_000_000;
        $this->assertNull($cache->take('one'));
        $this->assertSame(1, $cache->snapshot()['counts']['evict_age']);
    }

    public function testActualResidentPressureEvictsAndRefusesPublication(): void
    {
        $cache = new WorkflowContinuationCache();
        $cache->publish('one', $cache->observe('one'), $this->entry());
        $this->assertSame(1, $cache->snapshot()['entries']);
        $pressure = str_repeat('x', 140 * 1024 * 1024);
        $this->assertGreaterThan(128 * 1024 * 1024, $cache->snapshot()['resident_bytes']);
        $this->assertSame(0, $cache->snapshot()['entries']);
        $cache->publish('two', $cache->observe('two'), $this->entry());
        $this->assertSame(0, $cache->snapshot()['entries']);
        $this->assertSame(140 * 1024 * 1024, strlen($pressure));
    }

    public function testEvictionCannotResumeDurableCleanupFromFinally(): void
    {
        $observed = [];
        $workflow = new CacheDisposalWorkflow();
        $workflow->run = new WorkflowRun();
        $execution = WorkflowExecution::startCallback(static function () use (&$observed): void {
            try {
                Workflow::timer(1);
            } finally {
                $observed[] = 'entered';
                Workflow::timer(1);
                $observed[] = 'continued';
            }
        });
        $entry = new WorkflowContinuation($workflow, $execution, $execution->current());
        $cache = new WorkflowContinuationCache();
        $cache->publish('one', $cache->observe('one'), $entry);
        unset($entry, $execution, $workflow);
        $cache->clear();
        gc_collect_cycles();
        $this->assertSame(['entered'], $observed);
        $this->assertSame(0, $cache->snapshot()['entries']);
    }

    private function entry(): WorkflowContinuation
    {
        $workflow = new CacheDisposalWorkflow();
        $workflow->run = new WorkflowRun();
        $execution = WorkflowExecution::start($workflow, []);

        return new WorkflowContinuation($workflow, $execution, $execution->current());
    }
}

final class CacheDisposalWorkflow extends Workflow
{
    public function handle(): void
    {
        Workflow::timer(1);
    }
}
