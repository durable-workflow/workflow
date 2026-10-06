<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Support\DefaultOperatorObservabilityRepository;
use Workflow\V2\Support\RunHistoryPage;

final class RunHistoryPageTest extends TestCase
{
    public function testLongHistoryReadsOnlyOnePageAndNoChildOrProjectionModels(): void
    {
        $run = $this->createRun('selected', 'namespace-a');
        $other = $this->createRun('other', 'namespace-b');
        $child = $this->createRun('child', 'namespace-a');
        $this->events($run, 1, 1201);
        $this->events($other, 1, 500);
        $this->events($child, 1, 1000);
        WorkflowLink::query()->create([
            'id' => 'child-link',
            'link_type' => 'child_workflow',
            'sequence' => 1,
            'parent_workflow_instance_id' => $run->workflow_instance_id,
            'parent_workflow_run_id' => $run->id,
            'child_workflow_instance_id' => $child->workflow_instance_id,
            'child_workflow_run_id' => $child->id,
            'is_primary_parent' => true,
        ]);
        $retrieved = 0;
        $runsRetrieved = 0;
        $summariesRetrieved = 0;
        WorkflowHistoryEvent::retrieved(static function () use (&$retrieved): void {
            ++$retrieved;
        });
        WorkflowRun::retrieved(static function () use (&$runsRetrieved): void {
            ++$runsRetrieved;
        });
        WorkflowRunSummary::retrieved(static function () use (&$summariesRetrieved): void {
            ++$summariesRetrieved;
        });

        $page = (new DefaultOperatorObservabilityRepository())->runHistoryPage($run);

        $this->assertCount(200, $page['events']);
        $this->assertSame(201, $retrieved, 'Read at most one page and its continuation sentinel.');
        $this->assertSame(0, $runsRetrieved);
        $this->assertSame(0, $summariesRetrieved);
        $this->assertSame('selected', $page['run_id']);
        $this->assertSame('namespace-a', $page['namespace']);
        $this->assertSame(1201, $page['through_sequence']);
        $this->assertSame(200, $page['next_sequence']);
        $this->assertSame(1, $page['first_sequence']);
        $this->assertTrue($page['history_window_from_start']);
        $this->assertTrue($page['has_more']);
        $this->assertFalse($run->relationLoaded('historyEvents'));
        $this->assertSame(0, DB::table('workflow_run_timeline_entries')->count());

        $retrieved = 0;
        $lastPage = RunHistoryPage::forRun($run, afterSequence: 1200);
        $this->assertSame(1, $retrieved);
        $this->assertSame(1201, $lastPage['events'][0]['sequence']);
        $this->assertFalse($lastPage['history_window_from_start']);
        $this->assertFalse($lastPage['has_more']);
        $this->assertNull($lastPage['next_sequence']);
    }

    public function testContinuationKeepsItsOriginalBoundaryWhileTheRunAppends(): void
    {
        $run = $this->createRun('growing');
        $this->events($run, 1, 3);
        $first = RunHistoryPage::forRun($run, limit: 2);
        $this->events($run, 4, 5);

        $second = RunHistoryPage::forRun(
            $run,
            limit: 2,
            afterSequence: $first['next_sequence'],
            throughSequence: $first['through_sequence'],
        );

        $this->assertSame([3], array_column($second['events'], 'sequence'));
        $this->assertFalse($second['has_more']);
        $this->assertSame(3, $second['through_sequence']);
        $refreshed = RunHistoryPage::forRun($run, afterSequence: 3);
        $this->assertSame([4, 5], array_column($refreshed['events'], 'sequence'));
        $this->assertSame(5, $refreshed['through_sequence']);
    }

    public function testPrunedAndEmptyWindowsAreExplicitAndDoNotResolveStoredValues(): void
    {
        $run = $this->createRun('pruned');
        $run->forceFill([
            'details_pruned_at' => now(),
        ])->save();
        $this->events($run, 99, 99);
        $payload = [
            'input' => [
                'external' => [
                    'reference' => 'not-a-fetchable-fixture',
                ],
            ],
        ];
        DB::table('workflow_history_events')->where('workflow_run_id', $run->id)
            ->update([
                'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            ]);

        $page = RunHistoryPage::forRun($run);
        $this->assertSame('pruned', $page['details_state']);
        $this->assertSame($payload, $page['events'][0]['payload']);
        $this->assertSame(HistoryEventType::SignalReceived->value, $page['events'][0]['event_type']);
        $this->assertSame(99, $page['first_sequence']);
        $empty = RunHistoryPage::forRun($run, afterSequence: 99);
        $this->assertSame([], $empty['events']);
        $this->assertNull($empty['last_sequence']);
        $this->assertFalse($empty['has_more']);
        $this->assertSame('pruned', $empty['details_state']);
    }

    #[DataProvider('invalidWindows')]
    public function testInvalidWindowDoesNotQueryHistory(int $limit, int $after, ?int $through): void
    {
        $run = $this->createRun('invalid');
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            RunHistoryPage::forRun($run, $limit, $after, $through);
            $this->fail('An invalid page was accepted.');
        } catch (InvalidArgumentException) {
            $this->assertSame([], DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
    }

    /**
     * @return list<array{int, int, ?int}>
     */
    public static function invalidWindows(): array
    {
        return [[0, 0, null], [1001, 0, null], [200, -1, null], [200, 0, -1]];
    }

    private function createRun(string $id, string $namespace = 'default'): WorkflowRun
    {
        $instance = WorkflowInstance::query()->create([
            'id' => 'instance-' . $id,
            'namespace' => $namespace,
            'workflow_class' => 'history.page',
            'workflow_type' => 'history.page',
            'run_count' => 1,
        ]);

        return WorkflowRun::query()->create([
            'id' => $id,
            'workflow_instance_id' => $instance->id,
            'namespace' => $namespace,
            'run_number' => 1,
            'workflow_class' => 'history.page',
            'workflow_type' => 'history.page',
            'status' => 'running',
            'connection' => 'database',
            'queue' => 'history-page',
        ]);
    }

    private function events(WorkflowRun $run, int $from, int $through): void
    {
        $rows = [];
        for ($sequence = $from; $sequence <= $through; ++$sequence) {
            $rows[] = [
                'id' => $run->id . '-' . $sequence,
                'workflow_run_id' => $run->id,
                'sequence' => $sequence,
                'event_type' => HistoryEventType::SignalReceived->value,
                'payload' => json_encode([
                    'signal_id' => 'signal-' . $sequence,
                ], JSON_THROW_ON_ERROR),
                'recorded_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        foreach (array_chunk($rows, 100) as $batch) {
            DB::table('workflow_history_events')->insert($batch);
        }
    }
}
