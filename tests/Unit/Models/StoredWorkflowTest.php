<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\TestContinueAsNewWorkflow;
use Tests\Fixtures\TestWorkflow;
use Tests\TestCase;
use Workflow\Models\StoredWorkflow;
use Workflow\Serializers\Serializer;
use Workflow\States\WorkflowContinuedStatus;
use Workflow\States\WorkflowRunningStatus;
use Workflow\WorkflowStub;

final class StoredWorkflowTest extends TestCase
{
    public function testModel(): void
    {
        $workflow = StoredWorkflow::create([
            'class' => 'class',
            'status' => 'completed',
        ]);

        $workflow->exceptions()
            ->create([
                'class' => 'class',
                'exception' => 'exception',
            ]);

        $workflow->logs()
            ->create([
                'index' => 0,
                'now' => now(),
                'class' => 'class',
            ]);

        $workflow->signals()
            ->create([
                'method' => 'method',
            ]);

        $workflow->timers()
            ->create([
                'index' => 0,
                'stop_at' => now(),
            ]);

        $workflow->children()
            ->create([
                'class' => 'class',
                'status' => 'completed',
            ], [
                'parent_index' => 0,
                'parent_now' => now(),
            ]);

        $this->assertSame(1, $workflow->exceptions()->count());
        $this->assertSame(1, $workflow->logs()->count());
        $this->assertSame(1, $workflow->signals()->count());
        $this->assertSame(1, $workflow->timers()->count());
        $this->assertSame(1, $workflow->children()->count());

        Carbon::setTestNow(now()->addMonth()->addSecond());

        $this->artisan('model:prune', [
            '--model' => 'Workflow\Models\StoredWorkflow',
        ])
            ->doesntExpectOutputToContain('No prunable models found.')
            ->assertExitCode(0);

        $this->assertSame(0, $workflow->exceptions()->count());
        $this->assertSame(0, $workflow->logs()->count());
        $this->assertSame(0, $workflow->signals()->count());
        $this->assertSame(0, $workflow->timers()->count());
        $this->assertSame(0, $workflow->children()->count());
    }

    public function testContinuedWorkflows(): void
    {
        $parentWorkflow = StoredWorkflow::create([
            'class' => 'ParentWorkflow',
            'status' => 'continued',
        ]);

        $continuedWorkflow = StoredWorkflow::create([
            'class' => 'ContinuedWorkflow',
            'status' => 'completed',
        ]);

        $continuedWorkflow->parents()
            ->attach($parentWorkflow, [
                'parent_index' => StoredWorkflow::CONTINUE_PARENT_INDEX,
                'parent_now' => now(),
            ]);

        $result = $parentWorkflow->continuedWorkflows();

        $this->assertSame(1, $parentWorkflow->continuedWorkflows()->count());
        $this->assertSame($continuedWorkflow->id, $parentWorkflow->continuedWorkflows()->first()->id);
    }

    public function testActiveWithContinuedWorkflow(): void
    {
        $parentWorkflow = StoredWorkflow::create([
            'class' => 'ParentWorkflow',
            'status' => WorkflowContinuedStatus::class,
        ]);

        $continuedWorkflow = StoredWorkflow::create([
            'class' => 'ContinuedWorkflow',
            'status' => 'completed',
        ]);

        $continuedWorkflow->parents()
            ->attach($parentWorkflow, [
                'parent_index' => StoredWorkflow::CONTINUE_PARENT_INDEX,
                'parent_now' => now(),
            ]);

        $parentWorkflow->children()
            ->attach($continuedWorkflow, [
                'parent_index' => StoredWorkflow::ACTIVE_WORKFLOW_INDEX,
                'parent_now' => now(),
            ]);

        $active = $parentWorkflow->active();

        $this->assertSame($continuedWorkflow->id, $active->id);
    }

    public function testActiveWithShortcut(): void
    {
        $rootWorkflow = StoredWorkflow::create([
            'class' => 'RootWorkflow',
            'status' => WorkflowContinuedStatus::class,
        ]);

        $activeWorkflow = StoredWorkflow::create([
            'class' => 'ActiveWorkflow',
            'status' => 'completed',
        ]);

        $rootWorkflow->children()
            ->attach($activeWorkflow, [
                'parent_index' => StoredWorkflow::ACTIVE_WORKFLOW_INDEX,
                'parent_now' => now(),
            ]);

        $active = $rootWorkflow->active();

        $this->assertSame($activeWorkflow->id, $active->id);
    }

    public function testActiveWorkflowShortcutTransferOnContinue(): void
    {
        $rootWorkflow = StoredWorkflow::create([
            'class' => TestWorkflow::class,
            'arguments' => Serializer::serialize([]),
            'status' => WorkflowRunningStatus::class,
        ]);

        $intermediateWorkflow = StoredWorkflow::create([
            'class' => TestContinueAsNewWorkflow::class,
            'arguments' => Serializer::serialize([1, 3]),
            'status' => WorkflowRunningStatus::class,
        ]);

        $intermediateWorkflow->parents()
            ->attach($rootWorkflow->id, [
                'parent_index' => StoredWorkflow::ACTIVE_WORKFLOW_INDEX,
                'parent_now' => now(),
            ]);

        WorkflowStub::setContext([
            'storedWorkflow' => $intermediateWorkflow,
            'index' => 0,
            'now' => now(),
            'replaying' => false,
        ]);

        WorkflowStub::continueAsNew(2, 3);

        $this->assertSame(1, $intermediateWorkflow->continuedWorkflows()->count());
        $newWorkflow = $intermediateWorkflow->continuedWorkflows()
            ->first();

        $activeParent = $newWorkflow->parents()
            ->wherePivot('parent_index', StoredWorkflow::ACTIVE_WORKFLOW_INDEX)
            ->first();

        $this->assertNotNull($activeParent);
        $this->assertSame($rootWorkflow->id, $activeParent->id);
    }

    public function testActiveWorkflowWithMultipleContinuations(): void
    {
        $rootWorkflow = StoredWorkflow::create([
            'class' => 'RootWorkflow',
            'status' => WorkflowContinuedStatus::class,
        ]);

        $intermediateWorkflow = StoredWorkflow::create([
            'class' => 'IntermediateWorkflow',
            'status' => WorkflowContinuedStatus::class,
        ]);

        $finalWorkflow = StoredWorkflow::create([
            'class' => 'FinalWorkflow',
            'status' => 'completed',
        ]);

        $intermediateWorkflow->parents()
            ->attach($rootWorkflow, [
                'parent_index' => StoredWorkflow::CONTINUE_PARENT_INDEX,
                'parent_now' => now(),
            ]);

        $finalWorkflow->parents()
            ->attach($intermediateWorkflow, [
                'parent_index' => StoredWorkflow::CONTINUE_PARENT_INDEX,
                'parent_now' => now(),
            ]);

        $rootWorkflow->children()
            ->attach($finalWorkflow, [
                'parent_index' => StoredWorkflow::ACTIVE_WORKFLOW_INDEX,
                'parent_now' => now(),
            ]);

        $active = $rootWorkflow->active();

        $this->assertSame($finalWorkflow->id, $active->id);
    }

    public function testActiveWithContinuedStatusButNoActiveChild(): void
    {
        $workflow = StoredWorkflow::create([
            'class' => 'TestWorkflow',
            'status' => WorkflowRunningStatus::class,
            'arguments' => json_encode([]),
        ]);

        $workflow->status->transitionTo(WorkflowContinuedStatus::class);

        $active = $workflow->active();

        $this->assertNotNull($active);
        $this->assertSame($workflow->id, $active->id);
    }

    public function testEffectiveConnectionUsesWorkflowOptionConnection(): void
    {
        $workflow = StoredWorkflow::create([
            'class' => TestWorkflow::class,
            'status' => 'running',
            'arguments' => Serializer::serialize([
                'arguments' => [],
                'options' => [
                    'connection' => 'sync',
                    'queue' => null,
                ],
            ]),
        ]);

        $this->assertSame('sync', $workflow->effectiveConnection());
    }

    public function testFindLogByIndexFreshSyncsLoadedLogsRelation(): void
    {
        $workflow = StoredWorkflow::create([
            'class' => 'TestWorkflow',
            'status' => 'running',
        ]);

        $existingLog = $workflow->logs()
            ->create([
                'index' => 0,
                'now' => now(),
                'class' => 'test',
            ]);

        $freshLog = $workflow->logs()
            ->create([
                'index' => 1,
                'now' => now(),
                'class' => 'test',
            ]);

        $workflow->setRelation('logs', $workflow->logs()->whereKey($existingLog->id)->get());

        $log = $workflow->findLogByIndex(1, true);

        $this->assertNotNull($log);
        $this->assertSame($freshLog->id, $log->id);
        $this->assertSame([$existingLog->id, $freshLog->id], $workflow->getRelation('logs')->pluck('id')->toArray());
    }

    public function testFindLogByIndexUsesLoadedLogsRelation(): void
    {
        $workflow = StoredWorkflow::create([
            'class' => 'TestWorkflow',
            'status' => 'running',
        ]);

        $workflow->logs()
            ->create([
                'index' => 0,
                'now' => now(),
                'class' => 'test',
            ]);

        $workflow->load('logs');

        DB::flushQueryLog();
        DB::enableQueryLog();

        $log = $workflow->findLogByIndex(0);

        $this->assertNotNull($log);
        $this->assertCount(0, DB::getQueryLog());
    }

    public function testFindLogByIndexDoesNotUseFirstWhereForLoadedLogsRelation(): void
    {
        $workflow = StoredWorkflow::create([
            'class' => 'TestWorkflow',
            'status' => 'running',
        ]);

        $log = $workflow->logs()
            ->create([
                'index' => 0,
                'now' => now(),
                'class' => 'test',
            ]);

        $workflow->setRelation('logs', new class([$log]) extends \Illuminate\Database\Eloquent\Collection {
            public function firstWhere($key, $operator = null, $value = null)
            {
                throw new \BadMethodCallException('Loaded log lookup should not rely on firstWhere.');
            }
        });

        $foundLog = $workflow->findLogByIndex(0);

        $this->assertNotNull($foundLog);
        $this->assertSame($log->id, $foundLog->id);
    }

    public function testCreateLogSyncsLoadedLogsRelation(): void
    {
        $workflow = StoredWorkflow::create([
            'class' => 'TestWorkflow',
            'status' => 'running',
        ]);

        $workflow->load('logs');

        $workflow->createLog([
            'index' => 2,
            'now' => now(),
            'class' => 'test',
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $log = $workflow->findLogByIndex(2);

        $this->assertNotNull($log);
        $this->assertCount(0, DB::getQueryLog());
    }

    public function testFindTimerByIndexUsesLoadedTimersRelation(): void
    {
        $workflow = StoredWorkflow::create([
            'class' => 'TestWorkflow',
            'status' => 'running',
        ]);

        $workflow->timers()
            ->create([
                'index' => 3,
                'stop_at' => now()
                    ->addSecond(),
            ]);

        $workflow->load('timers');

        DB::flushQueryLog();
        DB::enableQueryLog();

        $timer = $workflow->findTimerByIndex(3);

        $this->assertNotNull($timer);
        $this->assertCount(0, DB::getQueryLog());
    }

    public function testFindTimerByIndexDoesNotUseFirstWhereForLoadedTimersRelation(): void
    {
        $workflow = StoredWorkflow::create([
            'class' => 'TestWorkflow',
            'status' => 'running',
        ]);

        $timer = $workflow->timers()
            ->create([
                'index' => 3,
                'stop_at' => now()
                    ->addSecond(),
            ]);

        $workflow->setRelation('timers', new class([$timer]) extends \Illuminate\Database\Eloquent\Collection {
            public function firstWhere($key, $operator = null, $value = null)
            {
                throw new \BadMethodCallException('Loaded timer lookup should not rely on firstWhere.');
            }
        });

        $foundTimer = $workflow->findTimerByIndex(3);

        $this->assertNotNull($foundTimer);
        $this->assertSame($timer->id, $foundTimer->id);
    }

    public function testFindTimerByIndexQueriesWhenTimersRelationIsNotLoaded(): void
    {
        $workflow = StoredWorkflow::create([
            'class' => 'TestWorkflow',
            'status' => 'running',
        ]);

        $workflow->timers()
            ->create([
                'index' => 3,
                'stop_at' => now()
                    ->addSecond(),
            ]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $timer = $workflow->findTimerByIndex(3);

        $this->assertNotNull($timer);
        $this->assertGreaterThan(0, count(DB::getQueryLog()));
    }

    public function testOrderedSignalsUsesLoadedSignalsRelation(): void
    {
        $workflow = StoredWorkflow::create([
            'class' => 'TestWorkflow',
            'status' => 'running',
        ]);

        $workflow->signals()
            ->create([
                'method' => 'first',
                'arguments' => serialize([]),
                'created_at' => now()
                    ->subSecond(),
            ]);

        $workflow->signals()
            ->create([
                'method' => 'second',
                'arguments' => serialize([]),
                'created_at' => now(),
            ]);

        $workflow->load('signals');

        DB::flushQueryLog();
        DB::enableQueryLog();

        $signals = $workflow->orderedSignals();

        $this->assertSame(['first', 'second'], $signals->pluck('method')->toArray());
        $this->assertCount(0, DB::getQueryLog());
    }

    public function testOrderedSignalsQueriesWhenSignalsRelationIsNotLoaded(): void
    {
        $workflow = StoredWorkflow::create([
            'class' => 'TestWorkflow',
            'status' => 'running',
        ]);

        $workflow->signals()
            ->create([
                'method' => 'first',
                'arguments' => serialize([]),
                'created_at' => now()
                    ->subSecond(),
            ]);

        $workflow->signals()
            ->create([
                'method' => 'second',
                'arguments' => serialize([]),
                'created_at' => now(),
            ]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $signals = $workflow->orderedSignals();

        $this->assertSame(['first', 'second'], $signals->pluck('method')->toArray());
        $this->assertGreaterThan(0, count(DB::getQueryLog()));
    }
}
