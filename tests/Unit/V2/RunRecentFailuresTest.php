<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\DefaultOperatorObservabilityRepository;
use Workflow\V2\Support\RunRecentFailures;

final class RunRecentFailuresTest extends TestCase
{
    public function testRecentFailuresReadOnlySelectedRowsAndPrimaryEventHeaders(): void
    {
        $run = $this->createRun('selected');
        $other = $this->createRun('other');
        for ($i = 1; $i <= 101; ++$i) {
            $this->failure($run, sprintf('failure-%03d', $i));
        }
        $this->failure($other, 'other-failure');
        $this->event($run, 1, 'ActivityFailed', [
            'failure_id' => 'failure-101',
            'exception' => 'opaque-not-decoded',
        ]);
        $this->event($run, 2, 'FailureHandled', [
            'failure_id' => 'failure-101',
        ]);
        $this->event($run, 3, 'UpdateCompleted', [
            'failure_id' => 'failure-100',
        ]);
        $this->event($other, 99, 'WorkflowFailed', [
            'failure_id' => 'failure-099',
        ]);
        $history = [];
        for ($i = 4; $i <= 1004; ++$i) {
            $history[] = [
                'id' => 'history-' . $i,
                'workflow_run_id' => $run->id,
                'sequence' => $i,
                'event_type' => 'SignalReceived',
                'payload' => '{}',
                'recorded_at' => now(),
            ];
        }
        foreach (array_chunk($history, 100) as $chunk) {
            DB::table('workflow_history_events')->insert($chunk);
        }
        $retrieved = [
            'failures' => 0,
            'history' => 0,
        ];
        WorkflowFailure::retrieved(static function () use (&$retrieved): void {
            ++$retrieved['failures'];
        });
        WorkflowHistoryEvent::retrieved(static function (WorkflowHistoryEvent $event) use (&$retrieved): void {
            ++$retrieved['history'];
            self::assertArrayNotHasKey('payload', $event->getAttributes());
        });
        DB::enableQueryLog();
        DB::flushQueryLog();
        $result = (new DefaultOperatorObservabilityRepository())->runRecentFailures($run);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame([
            'failures' => 11,
            'history' => 2,
        ], $retrieved);
        $this->assertCount(3, $queries);
        foreach ($queries as $query) {
            $this->assertStringStartsWith('select ', strtolower($query['query']));
        }
        $this->assertCount(10, $result['failures']);
        $this->assertTrue($result['has_more']);
        $this->assertNull($result['total_count']);
        $this->assertSame('partial', $result['state']);
        $this->assertSame('not_evaluated', $result['history_audit']);
        $failure = $result['failures'][0];
        $this->assertSame('failure-101', $failure['id']);
        $this->assertSame(1, $failure['event_sequence']);
        $this->assertSame('ActivityFailed', $failure['supporting_event']['event_type']);
        $this->assertSame(0, $failure['supporting_event']['after_sequence']);
        $this->assertSame('workflow_failures', $failure['handled_source']);
        $this->assertSame('unavailable', $result['failures'][2]['supporting_event']['state']);
        $this->assertSame([], $run->getRelations());
    }

    public function testPrunedOrMissingSupportingHistoryIsExplicitAndNeverProvesNoFailure(): void
    {
        $run = $this->createRun('pruned');
        $this->failure($run, 'failure');
        $run->forceFill([
            'details_pruned_at' => now(),
        ])->save();
        $result = RunRecentFailures::forRun($run);
        $this->assertSame('pruned', $result['state']);
        $this->assertSame('pruned', $result['failures'][0]['supporting_event']['state']);
        $this->assertNull($result['failures'][0]['event_sequence']);
        WorkflowFailure::query()->delete();
        $empty = RunRecentFailures::forRun($run);
        $this->assertSame([], $empty['failures']);
        $this->assertSame('pruned', $empty['state']);
        $this->assertNull($empty['total_count']);
        $this->assertSame('not_evaluated', $empty['history_audit']);
    }

    public function testConfiguredFailureScopeAndEagerLoadsArePreservedAndBounded(): void
    {
        $run = $this->createRun('scope');
        $this->failure($run, 'visible');
        $this->failure($run, 'hidden');
        $this->event($run, 1, 'WorkflowFailed', [
            'failure_id' => 'visible',
        ]);
        config()
            ->set('workflows.v2.failure_model', ScopedObservationFailure::class);
        $runModels = 0;
        WorkflowRun::retrieved(static function () use (&$runModels): void {
            ++$runModels;
        });
        $result = RunRecentFailures::forRun($run);
        $this->assertSame(['visible'], array_column($result['failures'], 'id'));
        $this->assertSame(0, $runModels);
    }

    public function testInvalidLimitIsRejectedBeforeReading(): void
    {
        $run = $this->createRun('invalid');
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            RunRecentFailures::forRun($run, 51);
            $this->fail('Expected invalid limit.');
        } catch (InvalidArgumentException) {
            $this->assertSame([], DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
    }

    private function createRun(string $id): WorkflowRun
    {
        WorkflowInstance::query()->create([
            'id' => 'instance-' . $id,
            'namespace' => 'default',
            'workflow_class' => 'failure.proof',
            'workflow_type' => 'failure.proof',
            'run_count' => 1,
        ]);

        return WorkflowRun::query()->create([
            'id' => $id,
            'workflow_instance_id' => 'instance-' . $id,
            'namespace' => 'default',
            'run_number' => 1,
            'workflow_class' => 'failure.proof',
            'workflow_type' => 'failure.proof',
            'status' => 'failed',
            'connection' => 'database',
            'queue' => 'default',
        ]);
    }

    private function failure(WorkflowRun $run, string $id): void
    {
        WorkflowFailure::query()->create([
            'id' => $id,
            'workflow_run_id' => $run->id,
            'source_kind' => 'activity',
            'source_id' => 'activity',
            'propagation_kind' => 'activity',
            'failure_category' => 'application',
            'non_retryable' => false,
            'handled' => false,
            'exception_class' => 'RuntimeException',
            'message' => 'Observed failure',
            'file' => 'fixture.php',
            'line' => 12,
            'trace_preview' => '',
        ]);
    }

    private function event(WorkflowRun $run, int $sequence, string $type, array $payload): void
    {
        WorkflowHistoryEvent::query()->create([
            'id' => $run->id . '-event-' . $sequence,
            'workflow_run_id' => $run->id,
            'sequence' => $sequence,
            'event_type' => $type,
            'payload' => $payload,
            'recorded_at' => now(),
        ]);
    }
}

final class ScopedObservationFailure extends WorkflowFailure
{
    protected $with = ['run.historyEvents'];

    protected static function booted(): void
    {
        static::addGlobalScope('observation-fixture', static function ($query): void {
            $query->where('id', '!=', 'hidden');
        });
    }
}
