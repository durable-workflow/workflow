<?php

declare(strict_types=1);

namespace Workflow\Commands;

use Illuminate\Console\Command;
use JsonException;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;
use Workflow\V2\Enums\FailureCategory;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\FailureFactory;

#[AsCommand(name: 'workflow:v2:backfill-failure-categories')]
class V2BackfillFailureCategoriesCommand extends Command
{
    protected $signature = 'workflow:v2:backfill-failure-categories
        {--run-id=* : Backfill one or more selected workflow run ids}
        {--instance-id= : Backfill every failure for one workflow instance id}
        {--dry-run : Report the affected rows without changing failure records}
        {--json : Output the backfill report as JSON}';

    protected $description = 'Backfill failure_category and non_retryable on older Workflow v2 failure rows';

    public function handle(): int
    {
        $runIds = $this->runIds();
        $instanceId = $this->stringOption('instance-id');
        $dryRun = (bool) $this->option('dry-run');

        $query = $this->failureQuery($runIds, $instanceId);

        $report = [
            'dry_run' => $dryRun,
            'failures_matched' => (clone $query)->count(),
            'failures_scanned' => 0,
            'failures_already_categorized' => 0,
            'failures_updated' => 0,
            'failures_would_update' => 0,
            'non_retryable_detected' => 0,
            'errors' => [],
        ];

        $query->chunkById(100, function ($failures) use (&$report, $dryRun): void {
            foreach ($failures as $failure) {
                if (! $failure instanceof WorkflowFailure) {
                    continue;
                }

                $report['failures_scanned']++;

                try {
                    $needsCategoryBackfill = $failure->failure_category === null;
                    $needsNonRetryableBackfill = $failure->non_retryable === null
                        || ($failure->non_retryable === false && $this->shouldBeNonRetryable($failure));

                    if (! $needsCategoryBackfill && ! $needsNonRetryableBackfill) {
                        $report['failures_already_categorized']++;

                        continue;
                    }

                    $updates = [];

                    if ($needsCategoryBackfill) {
                        $category = FailureFactory::classifyFromStrings(
                            (string) ($failure->propagation_kind ?? 'terminal'),
                            (string) ($failure->source_kind ?? 'workflow_run'),
                            $failure->exception_class,
                            $failure->message,
                        );
                        $updates['failure_category'] = $category->value;
                    }

                    $nonRetryable = FailureFactory::isNonRetryableFromStrings($failure->exception_class);

                    if ($nonRetryable) {
                        $updates['non_retryable'] = true;
                        $report['non_retryable_detected']++;
                    }

                    if ($updates === []) {
                        $report['failures_already_categorized']++;

                        continue;
                    }

                    if ($dryRun) {
                        $report['failures_would_update']++;

                        continue;
                    }

                    $failure->forceFill($updates)->save();
                    $report['failures_updated']++;
                } catch (Throwable $exception) {
                    $report['errors'][] = [
                        'failure_id' => $failure->id,
                        'run_id' => $failure->workflow_run_id,
                        'message' => $exception->getMessage(),
                    ];
                }
            }
        });

        $this->renderReport($report);

        return $report['errors'] !== [] ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param list<string> $runIds
     */
    private function failureQuery(array $runIds, ?string $instanceId)
    {
        $query = WorkflowFailure::query()
            ->where(static function ($q): void {
                $q->whereNull('failure_category')
                    ->orWhereNull('non_retryable');
            })
            ->orderBy('id');

        if ($runIds !== []) {
            $query->whereIn('workflow_run_id', $runIds);
        }

        if ($instanceId !== null) {
            $runModel = config('workflows.v2.run_model', WorkflowRun::class);

            $query->whereIn(
                'workflow_run_id',
                $runModel::query()
                    ->select('id')
                    ->where('workflow_instance_id', $instanceId),
            );
        }

        return $query;
    }

    /**
     * @return list<string>
     */
    private function runIds(): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): string => is_scalar($value) ? trim((string) $value) : '',
            (array) $this->option('run-id'),
        ))));
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function shouldBeNonRetryable(WorkflowFailure $failure): bool
    {
        return FailureFactory::isNonRetryableFromStrings($failure->exception_class);
    }

    /**
     * @param array{
     *     dry_run: bool,
     *     failures_matched: int,
     *     failures_scanned: int,
     *     failures_already_categorized: int,
     *     failures_updated: int,
     *     failures_would_update: int,
     *     non_retryable_detected: int,
     *     errors: list<array{failure_id: string, run_id: string, message: string}>
     * } $report
     */
    private function renderReport(array $report): void
    {
        if ((bool) $this->option('json')) {
            try {
                $this->line(json_encode($report, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            } catch (JsonException $exception) {
                $this->error($exception->getMessage());
            }

            return;
        }

        if ($report['dry_run']) {
            $this->info(sprintf(
                'Would backfill %d failure row(s).',
                $report['failures_would_update'],
            ));
        } else {
            $this->info(sprintf('Backfilled %d failure row(s).', $report['failures_updated']));
        }

        if ($report['failures_already_categorized'] > 0) {
            $this->info(sprintf(
                'Skipped %d already-categorized failure row(s).',
                $report['failures_already_categorized'],
            ));
        }

        if ($report['non_retryable_detected'] > 0) {
            $this->info(sprintf(
                'Detected %d non-retryable failure(s).',
                $report['non_retryable_detected'],
            ));
        }

        foreach ($report['errors'] as $error) {
            $this->error(sprintf(
                'Failed to backfill failure [%s] on run [%s]: %s',
                $error['failure_id'],
                $error['run_id'],
                $error['message'],
            ));
        }
    }
}
