<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Support\HealthCheck;
use Workflow\V2\Support\OperatorMetrics;
use Workflow\V2\Support\RunSummaryProjectionDrift;
use Workflow\V2\Support\RunSummaryProjector;
use Workflow\V2\Support\WorkflowRunRetentionCleanup;

final class RetainedRunProjectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-19 12:00:00 UTC');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function testPrunedDetailsAreNotMissingProjectionOrLag(): void
    {
        $run = $this->completedRun('retained', 120);
        RunSummaryProjector::project($run);
        $report = WorkflowRunRetentionCleanup::pruneRun($run);

        $this->assertSame(1, $report['run_summary_deleted']);
        $this->assertNotNull($run->fresh()->details_pruned_at);
        $this->assertDatabaseMissing('workflow_run_summaries', ['id' => $run->id]);
        $drift = RunSummaryProjectionDrift::metrics('retained');
        $this->assertSame(1, $drift['runs']);
        $this->assertSame(0, $drift['summaries']);
        $this->assertSame(0, $drift['missing']);
        $this->assertSame(0, $drift['needs_rebuild']);

        $metrics = OperatorMetrics::snapshot(namespace: 'retained')['projections']['run_summaries'];
        $this->assertNull($metrics['oldest_missing_run_started_at']);
        $this->assertSame(0, $metrics['max_missing_run_age_ms']);
        $check = collect(HealthCheck::snapshot()['checks'])->firstWhere('name', 'run_summary_projection');
        $this->assertSame('ok', $check['status']);
    }

    /**
     * @return iterable<string, array{array<string, bool>, bool}>
     */
    public static function rebuildModes(): iterable
    {
        foreach (['missing', 'needs-rebuild', 'all'] as $mode) {
            foreach ([false, true] as $dryRun) {
                yield $mode . ($dryRun ? '-dry' : '-write') => [
                    $mode === 'all' ? [] : ['--' . $mode => true],
                    $dryRun,
                ];
            }
        }
    }

    /**
     * @param array<string, bool> $options
     */
    #[DataProvider('rebuildModes')]
    public function testRebuildKeepsPrunedRunsAbsentAndRepairsEligibleRuns(array $options, bool $dryRun): void
    {
        $pruned = $this->completedRun('rebuild', 120);
        RunSummaryProjector::project($pruned);
        WorkflowRunRetentionCleanup::pruneRun($pruned);
        $marker = $pruned->fresh()->getRawOriginal('details_pruned_at');
        $missing = $this->completedRun('rebuild', 5);
        $foreign = $this->completedRun('other', 3);

        $this->assertSame(0, Artisan::call('workflow:v2:rebuild-projections', [
            ...$options,
            '--namespace' => 'rebuild',
            '--dry-run' => $dryRun,
            '--json' => true,
        ]));
        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $report['runs_matched']);
        $this->assertSame([], $report['failures']);
        $this->assertSame($dryRun ? 0 : 1, $report['run_summaries_rebuilt']);
        $this->assertSame($dryRun ? 1 : 0, $report['run_summaries_would_rebuild']);
        $this->assertDatabaseMissing('workflow_run_summaries', ['id' => $pruned->id]);
        $this->assertDatabaseMissing('workflow_run_summaries', ['id' => $foreign->id]);
        $this->assertSame(! $dryRun, WorkflowRunSummary::query()->whereKey($missing->id)->exists());
        $this->assertSame($marker, $pruned->fresh()->getRawOriginal('details_pruned_at'));
        $this->assertSame(0, WorkflowHistoryEvent::query()->where('workflow_run_id', $pruned->id)->count());
    }

    public function testGenuineMissingProjectionStillContributesLagInItsNamespace(): void
    {
        $pruned = $this->completedRun('local', 120);
        WorkflowRunRetentionCleanup::pruneRun($pruned);
        $missing = $this->completedRun('local', 5);
        $this->completedRun('foreign', 60);

        $this->assertSame(1, RunSummaryProjectionDrift::metrics('local')['missing']);
        $this->assertSame(1, RunSummaryProjectionDrift::metrics('foreign')['missing']);
        $this->assertSame(2, RunSummaryProjectionDrift::metrics()['missing']);
        $metrics = OperatorMetrics::snapshot(namespace: 'local')['projections']['run_summaries'];
        $this->assertSame($missing->started_at->toJSON(), $metrics['oldest_missing_run_started_at']);
        $this->assertSame(300000, $metrics['max_missing_run_age_ms']);
        $check = collect(HealthCheck::snapshot()['checks'])->firstWhere('name', 'run_summary_projection');
        $this->assertSame('warning', $check['status']);
    }

    public function testExplicitSelectorsCannotResurrectPrunedDetails(): void
    {
        $run = $this->completedRun('selected', 120);
        WorkflowRunRetentionCleanup::pruneRun($run);
        $marker = $run->fresh()->getRawOriginal('details_pruned_at');

        foreach ([['--run-id' => [$run->id]], ['--instance-id' => $run->workflow_instance_id]] as $selector) {
            foreach ([false, true] as $dryRun) {
                $this->assertSame(0, Artisan::call('workflow:v2:rebuild-projections', [
                    ...$selector, '--dry-run' => $dryRun, '--json' => true,
                ]));
                $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
                $this->assertSame(0, $report['runs_matched']);
                $this->assertSame(0, $report['run_summaries_rebuilt']);
                $this->assertSame(0, $report['run_summaries_would_rebuild']);
            }
        }

        $this->assertDatabaseMissing('workflow_run_summaries', ['id' => $run->id]);
        $this->assertSame($marker, $run->fresh()->getRawOriginal('details_pruned_at'));
    }

    public function testUnmarkedLegacyAbsenceIsNotAssumedToBeIntentionalPruning(): void
    {
        $run = $this->completedRun('legacy', 120);
        WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)->delete();

        $this->assertNull($run->fresh()->details_pruned_at);
        $this->assertSame(1, RunSummaryProjectionDrift::metrics('legacy')['missing']);
        $this->assertSame(0, Artisan::call('workflow:v2:rebuild-projections', [
            '--run-id' => [$run->id], '--missing' => true, '--dry-run' => true, '--json' => true,
        ]));
        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $report['runs_matched']);
        $this->assertDatabaseMissing('workflow_run_summaries', ['id' => $run->id]);
        $this->assertNull($run->fresh()->details_pruned_at);
    }

    private function completedRun(string $namespace, int $ageMinutes): WorkflowRun
    {
        $instance = WorkflowInstance::query()->create([
            'id' => 'retention-' . Str::ulid(),
            'namespace' => $namespace,
            'workflow_class' => 'App\\Workflows\\RetentionProjection',
            'workflow_type' => 'retention.projection',
            'run_count' => 1,
            'started_at' => now()->subMinutes($ageMinutes),
        ]);
        $run = WorkflowRun::query()->create([
            'id' => (string) Str::ulid(),
            'namespace' => $namespace,
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => $instance->workflow_class,
            'workflow_type' => $instance->workflow_type,
            'status' => RunStatus::Completed->value,
            'closed_reason' => 'completed',
            'started_at' => now()->subMinutes($ageMinutes),
            'closed_at' => now()->subMinute(),
            'last_progress_at' => now()->subMinute(),
        ]);
        $instance->forceFill(['current_run_id' => $run->id])->save();
        WorkflowHistoryEvent::record($run, HistoryEventType::WorkflowStarted, [
            'workflow_type' => $run->workflow_type,
        ]);
        WorkflowHistoryEvent::record($run->refresh(), HistoryEventType::WorkflowCompleted, [
            'output' => ['ok' => true],
        ]);

        return $run->refresh();
    }
}
