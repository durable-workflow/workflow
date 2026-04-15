<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use RuntimeException;
use Tests\Fixtures\V2\TestGreetingActivity;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\TestCase;
use Workflow\Exceptions\NonRetryableException;
use Workflow\V2\Enums\FailureCategory;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\WorkflowStub;

final class V2BackfillFailureCategoriesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()
            ->set('queue.default', 'sync');
        config()
            ->set('queue.connections.sync.driver', 'sync');
    }

    public function testBackfillCategorizesUncategorizedFailures(): void
    {
        WorkflowStub::fake();

        WorkflowStub::mock(TestGreetingActivity::class, static function (): never {
            throw new RuntimeException('test failure');
        });

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'backfill-test-1');
        $workflow->start('Taylor');

        $this->assertTrue($workflow->refresh()->failed());

        // Simulate an older failure row by clearing the failure_category.
        $failure = WorkflowFailure::query()
            ->where('workflow_run_id', $workflow->runId())
            ->firstOrFail();

        $failure->forceFill([
            'failure_category' => null,
        ])->save();
        $this->assertNull($failure->fresh()->failure_category);

        // Run the backfill command.
        $this->artisan('workflow:v2:backfill-failure-categories')
            ->assertSuccessful();

        // The failure should now have a failure_category.
        $backfilled = $failure->fresh();
        $this->assertNotNull($backfilled->failure_category);
    }

    public function testBackfillDetectsNonRetryableOnAlreadyCategorizedRows(): void
    {
        WorkflowStub::fake();

        WorkflowStub::mock(TestGreetingActivity::class, static function (): never {
            throw new NonRetryableException('permanently broken');
        });

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'backfill-non-retryable-1');
        $workflow->start('Taylor');

        $this->assertTrue($workflow->refresh()->failed());

        // Simulate an older failure row: failure_category is already set,
        // but non_retryable was defaulted to false by the migration.
        // This is the exact scenario described in TD-062.
        $failure = WorkflowFailure::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('source_kind', 'activity_execution')
            ->firstOrFail();

        $failure->forceFill([
            'failure_category' => FailureCategory::Activity->value,
            'non_retryable' => false,
        ])->save();

        $refreshed = $failure->fresh();
        $this->assertSame(FailureCategory::Activity, $refreshed->failure_category);
        $this->assertFalse($refreshed->non_retryable);

        // Run the backfill command — this should find the row because of the
        // orWhere('non_retryable', false) clause added to fix TD-062.
        $this->artisan('workflow:v2:backfill-failure-categories')
            ->assertSuccessful();

        // The failure should now have non_retryable = true.
        $backfilled = $failure->fresh();
        $this->assertTrue($backfilled->non_retryable);
    }

    public function testBackfillDryRunDoesNotModifyRows(): void
    {
        WorkflowStub::fake();

        WorkflowStub::mock(TestGreetingActivity::class, static function (): never {
            throw new RuntimeException('test failure');
        });

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'backfill-dry-run-1');
        $workflow->start('Taylor');

        $this->assertTrue($workflow->refresh()->failed());

        // Clear the category to simulate an older row.
        $failure = WorkflowFailure::query()
            ->where('workflow_run_id', $workflow->runId())
            ->firstOrFail();

        $failure->forceFill([
            'failure_category' => null,
        ])->save();

        // Run with --dry-run.
        $this->artisan('workflow:v2:backfill-failure-categories', [
            '--dry-run' => true,
        ])
            ->assertSuccessful();

        // The failure should still have no category.
        $this->assertNull($failure->fresh()->failure_category);
    }

    public function testBackfillSkipsAlreadyCategorizedRows(): void
    {
        WorkflowStub::fake();

        WorkflowStub::mock(TestGreetingActivity::class, static function (): never {
            throw new RuntimeException('test failure');
        });

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'backfill-skip-1');
        $workflow->start('Taylor');

        $this->assertTrue($workflow->refresh()->failed());

        // The failure should already be categorized from the normal flow.
        $failure = WorkflowFailure::query()
            ->where('workflow_run_id', $workflow->runId())
            ->firstOrFail();

        $originalCategory = $failure->failure_category;
        $this->assertNotNull($originalCategory);

        // Run the backfill — it should report the row as already categorized.
        $this->artisan('workflow:v2:backfill-failure-categories', [
            '--json' => true,
        ])
            ->assertSuccessful();

        // The category should be unchanged.
        $this->assertSame($originalCategory, $failure->fresh()->failure_category);
    }

    public function testBackfillScopedToRunId(): void
    {
        WorkflowStub::fake();

        WorkflowStub::mock(TestGreetingActivity::class, static function (): never {
            throw new RuntimeException('test failure');
        });

        // Create two failing workflows.
        $workflow1 = WorkflowStub::make(TestGreetingWorkflow::class, 'backfill-scoped-1');
        $workflow1->start('Taylor');

        $workflow2 = WorkflowStub::make(TestGreetingWorkflow::class, 'backfill-scoped-2');
        $workflow2->start('Taylor');

        // Clear categories on both.
        WorkflowFailure::query()
            ->whereIn('workflow_run_id', [$workflow1->runId(), $workflow2->runId()])
            ->update([
                'failure_category' => null,
            ]);

        // Backfill only the first run.
        $this->artisan('workflow:v2:backfill-failure-categories', [
            '--run-id' => [$workflow1->runId()],
        ])->assertSuccessful();

        // First run should be categorized.
        $failure1 = WorkflowFailure::query()
            ->where('workflow_run_id', $workflow1->runId())
            ->firstOrFail();
        $this->assertNotNull($failure1->failure_category);

        // Second run should still be uncategorized.
        $failure2 = WorkflowFailure::query()
            ->where('workflow_run_id', $workflow2->runId())
            ->firstOrFail();
        $this->assertNull($failure2->failure_category);
    }
}
