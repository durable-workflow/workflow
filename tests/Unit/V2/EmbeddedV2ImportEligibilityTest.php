<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\ActivityAttemptStatus;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\BundleIntegrityVerifier;
use Workflow\V2\Support\EmbeddedV2HistoryImport;
use Workflow\V2\Support\HistoryExport;

final class EmbeddedV2ImportEligibilityTest extends TestCase
{
    private const TABLES = [
        'workflow_run_summaries', 'workflow_run_waits', 'workflow_run_timeline_entries',
        'workflow_run_timer_entries', 'workflow_run_lineage_entries', 'workflow_search_attributes',
        'workflow_memos', 'workflow_history_events', 'workflow_tasks', 'activity_attempts',
        'activity_executions', 'workflow_run_timers', 'workflow_failures', 'workflow_links',
        'workflow_signal_records', 'workflow_updates', 'workflow_commands',
        'workflow_runs', 'workflow_instances',
    ];

    #[DataProvider('ineligibleBundles')]
    public function testIneligibleImportsNeverWriteEvenWhenRepeated(string $mutation, string $rule, bool $dryRun): void
    {
        $bundle = $this->exportedBundle();
        $this->clearSourceFixture();
        $options = [
            'dry_run' => $dryRun,
        ];

        switch ($mutation) {
            case 'missing-run':
                unset($bundle['workflow']['run_id']);
                break;
            case 'missing-instance':
                unset($bundle['workflow']['instance_id']);
                break;
            case 'unsupported-status':
                $bundle['workflow']['status'] = 'unsupported-import-status';
                break;
            case 'non-current':
                $bundle['workflow']['is_current_run'] = false;
                break;
            case 'incomplete-terminal':
                $bundle['workflow']['status'] = RunStatus::Completed->value;
                $bundle['history_complete'] = false;
                break;
            case 'signature-required':
                $options['require_signature'] = true;
                break;
            case 'running-attempt':
                $bundle['activities'] = [[
                    'id' => (string) Str::ulid(),
                    'status' => ActivityStatus::Running->value,
                    'attempts' => [[
                        'id' => (string) Str::ulid(),
                        'status' => ActivityAttemptStatus::Running->value,
                    ]],
                ]];
                break;
        }

        $bundle = $this->reseal($bundle);
        $before = $this->snapshot();
        $original = $bundle;
        $first = null;

        for ($delivery = 0; $delivery < 2; ++$delivery) {
            $report = EmbeddedV2HistoryImport::import($bundle, $options);
            $this->assertSame('rejected', $report['status']);
            $this->assertSame($dryRun, $report['dry_run']);
            $this->assertFalse($report['eligibility']['eligible']);
            $this->assertContains($rule, array_column($report['eligibility']['errors'], 'rule'));
            $this->assertSame(0, array_sum($report['rows']));
            $this->assertSame($before, $this->snapshot());
            $this->assertSame($original, $bundle);

            if ($first !== null) {
                $this->assertSame($first, $report);
            }
            $first = $report;
        }
    }

    public function testEligibleDryRunReportsRowsAndNamespaceWithoutImporting(): void
    {
        $bundle = $this->exportedBundle();
        $this->clearSourceFixture();
        $before = $this->snapshot();
        $original = $bundle;

        for ($delivery = 0; $delivery < 2; ++$delivery) {
            $report = EmbeddedV2HistoryImport::import($bundle, [
                'dry_run' => true,
                'namespace' => 'preview-target',
            ]);
            $this->assertSame('dry_run', $report['status']);
            $this->assertTrue($report['eligibility']['eligible']);
            $this->assertSame([], $report['eligibility']['errors']);
            $this->assertSame('preview-target', $report['workflow']['namespace']);
            $this->assertSame(1, $report['rows']['workflow_instances']);
            $this->assertSame(1, $report['rows']['workflow_runs']);
            $this->assertSame(1, $report['rows']['workflow_history_events']);
            $this->assertSame(1, $report['rows']['workflow_run_summaries']);
            $this->assertSame(0, $report['rows']['workflow_tasks']);
            $this->assertSame(0, $report['rows']['activity_executions']);
            $this->assertSame($before, $this->snapshot());
            $this->assertSame($original, $bundle);
        }
    }

    public function testIntegrityWarningIsReportedWithoutRejectingAnOtherwiseEligiblePreview(): void
    {
        $bundle = $this->exportedBundle();
        $this->clearSourceFixture();
        unset($bundle['exported_at']);
        $bundle = $this->reseal($bundle);
        $before = $this->snapshot();

        $report = EmbeddedV2HistoryImport::import($bundle, [
            'dry_run' => true,
        ]);

        $this->assertSame('dry_run', $report['status']);
        $this->assertSame(BundleIntegrityVerifier::STATUS_WARNING, $report['integrity']['status']);
        $this->assertTrue($report['eligibility']['eligible']);
        $this->assertContains('bundle.integrity_warning', array_column($report['eligibility']['warnings'], 'rule'));
        $this->assertSame([], $report['eligibility']['errors']);
        $this->assertSame($before, $this->snapshot());
    }

    public function testAnExistingNonImportedRunConflictsWithoutChangingItsState(): void
    {
        $bundle = $this->exportedBundle();
        $before = $this->snapshot();
        $original = $bundle;

        for ($delivery = 0; $delivery < 2; ++$delivery) {
            $report = EmbeddedV2HistoryImport::import($bundle);
            $this->assertSame('rejected', $report['status']);
            $this->assertContains('target.run_id_conflict', array_column($report['eligibility']['errors'], 'rule'));
            $this->assertSame(0, array_sum($report['rows']));
            $this->assertSame($before, $this->snapshot());
            $this->assertSame($original, $bundle);
        }
    }

    public function testRunNumberConflictPreservesTheExistingInstanceAndRun(): void
    {
        $bundle = $this->exportedBundle();
        $bundle['workflow']['run_id'] = (string) Str::ulid();
        $bundle['workflow']['current_run_id'] = $bundle['workflow']['run_id'];
        $bundle = $this->reseal($bundle);
        $before = $this->snapshot();
        $original = $bundle;

        for ($delivery = 0; $delivery < 2; ++$delivery) {
            $report = EmbeddedV2HistoryImport::import($bundle);
            $this->assertSame('rejected', $report['status']);
            $this->assertContains('target.write_failed', array_column($report['eligibility']['errors'], 'rule'));
            $this->assertSame(0, array_sum($report['rows']));
            $this->assertFalse(WorkflowRun::query()->whereKey($bundle['workflow']['run_id'])->exists());
            $this->assertSame($before, $this->snapshot());
            $this->assertSame($original, $bundle);
        }
    }

    public function testHistoryIdConflictRollsBackNewInstanceAndRunBeforeReturningFailure(): void
    {
        $bundle = $this->exportedBundle();
        $this->clearSourceFixture();
        $sentinel = $this->createRun();
        $event = $bundle['history_events'][0];
        WorkflowHistoryEvent::query()->create([
            'id' => $event['id'],
            'workflow_run_id' => $sentinel->id,
            'sequence' => 1,
            'event_type' => HistoryEventType::WorkflowStarted->value,
            'payload' => [
                'workflow_type' => 'import.eligibility',
            ],
            'recorded_at' => now(),
        ]);
        $this->assertSame(BundleIntegrityVerifier::STATUS_OK, BundleIntegrityVerifier::verify($bundle)['status']);
        $before = $this->snapshot();
        $original = $bundle;

        for ($delivery = 0; $delivery < 2; ++$delivery) {
            $report = EmbeddedV2HistoryImport::import($bundle);
            $this->assertSame('rejected', $report['status']);
            $this->assertFalse($report['eligibility']['eligible']);
            $this->assertContains('target.write_failed', array_column($report['eligibility']['errors'], 'rule'));
            $this->assertNotEmpty($report['eligibility']['errors'][0]['message']);
            $this->assertSame(0, array_sum($report['rows']));
            $this->assertSame('database_transaction', $report['rollback']['mode']);
            $this->assertSame('no_rows_committed_on_failure', $report['rollback']['partial_import_behavior']);
            $this->assertFalse(WorkflowInstance::query()->whereKey($bundle['workflow']['instance_id'])->exists());
            $this->assertFalse(WorkflowRun::query()->whereKey($bundle['workflow']['run_id'])->exists());
            $this->assertSame($before, $this->snapshot());
            $this->assertSame($original, $bundle);
        }
    }

    public static function ineligibleBundles(): array
    {
        $cases = [
            'missing-run' => 'workflow.run_id_missing',
            'missing-instance' => 'workflow.instance_id_missing',
            'unsupported-status' => 'workflow.status_unsupported',
            'non-current' => 'workflow.non_terminal_not_current',
            'incomplete-terminal' => 'workflow.terminal_history_incomplete',
            'signature-required' => 'bundle.signature_required',
            'running-attempt' => 'activities.running_attempt_present',
        ];
        $rows = [];
        foreach ($cases as $mutation => $rule) {
            foreach ([false, true] as $dryRun) {
                $rows[$mutation . ($dryRun ? ' preview' : ' import')] = [$mutation, $rule, $dryRun];
            }
        }
        return $rows;
    }

    private function exportedBundle(): array
    {
        Carbon::setTestNow('2026-10-09T00:00:00Z');
        $this->beforeApplicationDestroyed(static function (): void {
            Carbon::setTestNow();
        });
        $run = $this->createRun();
        WorkflowHistoryEvent::record($run, HistoryEventType::WorkflowStarted, [
            'workflow_class' => $run->workflow_class,
            'workflow_type' => $run->workflow_type,
        ]);
        $bundle = HistoryExport::forRun($run->fresh());
        $this->assertSame('embedded', $bundle['workflow']['source_runtime']);
        $this->assertSame(BundleIntegrityVerifier::STATUS_OK, BundleIntegrityVerifier::verify($bundle)['status']);
        return $bundle;
    }

    private function createRun(): WorkflowRun
    {
        $instance = WorkflowInstance::query()->create([
            'id' => 'import-eligibility-' . Str::ulid(),
            'workflow_class' => 'App\\Workflows\\EligibilityWorkflow',
            'workflow_type' => 'import.eligibility',
            'namespace' => 'import-tests',
            'run_count' => 1,
            'started_at' => now(),
        ]);
        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => $instance->workflow_class,
            'workflow_type' => $instance->workflow_type,
            'namespace' => $instance->namespace,
            'status' => RunStatus::Waiting->value,
            'payload_codec' => config('workflows.serializer'),
            'arguments' => Serializer::serialize([false, 0, null]),
            'connection' => 'redis',
            'queue' => 'import-tests',
            'started_at' => now(),
        ]);
        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();
        return $run;
    }

    private function clearSourceFixture(): void
    {
        foreach (self::TABLES as $table) {
            DB::table($table)->delete();
        }
    }

    private function snapshot(): array
    {
        $snapshot = [];
        foreach (self::TABLES as $table) {
            $rows = DB::table($table)->get()->map(static fn (object $row): array => (array) $row)->all();
            sort($rows);
            $snapshot[$table] = $rows;
        }
        return $snapshot;
    }

    private function reseal(array $bundle): array
    {
        $checksum = BundleIntegrityVerifier::verify($bundle)['integrity']['recomputed_checksum'];
        $this->assertIsString($checksum);
        $bundle['integrity']['checksum'] = $checksum;
        return $bundle;
    }
}
