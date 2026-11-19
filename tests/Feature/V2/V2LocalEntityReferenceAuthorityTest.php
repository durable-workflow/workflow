<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\V2\EntityReferences\Order;
use Tests\Fixtures\V2\EntityReferences\OrderReferenceAdapter;
use Tests\TestCase;
use Workflow\V2\Activity;
use Workflow\V2\EntityReferences\EntityReference;
use Workflow\V2\EntityReferences\EntityReferenceContext;
use Workflow\V2\EntityReferences\EntityReferenceResolver;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Jobs\RunWorkflowTask;
use function Workflow\V2\localActivity;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Workflow;
use Workflow\V2\WorkflowStub;

final class V2LocalEntityReferenceAuthorityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config()
            ->set('queue.default', 'redis');
        config()
            ->set('workflows.v2.entity_references', [
                'orders.order' => [
                    'entity_class' => Order::class,
                    'adapter' => OrderReferenceAdapter::class,
                    'resolver' => ReviewOrderResolver::class,
                ],
            ]);
        ReviewOrderResolver::$duringLookup = null;
        ReviewOrderResolver::$loads = 0;
        ReviewOrderResolver::$samples = [];
        ReviewOrderActivity::$calls = 0;
    }

    public function testExpiredHostingLeaseRefusesTheResolvedObject(): void
    {
        ReviewOrderResolver::$duringLookup = static function (EntityReferenceContext $context): void {
            WorkflowTask::query()->where('workflow_run_id', $context->workflowRunId)->update([
                'lease_expires_at' => now()
                    ->subSecond(),
            ]);
        };
        $runId = $this->execute('expired-lease');
        $this->assertNoCallbackOrCompletion($runId);
        $this->assertSame(1, ReviewOrderResolver::$loads);
    }

    public function testCancellationDuringLookupPreventsTheLocalCallback(): void
    {
        $workflow = WorkflowStub::make(ReviewLocalWorkflow::class, 'cancel-during-lookup');
        ReviewOrderResolver::$duringLookup = static function () use ($workflow): void {
            $workflow->cancel('Review cancellation during entity lookup.');
        };
        $workflow->start();
        $this->runReadyTask($workflow->runId());
        $this->assertNoCallbackOrCompletion($workflow->runId());
        $this->assertSame(RunStatus::Cancelled, WorkflowRun::query()->findOrFail($workflow->runId())->status);
    }

    public function testRunDeadlineCrossingDuringLookupPreventsTheLocalCallback(): void
    {
        ReviewOrderResolver::$duringLookup = static function (EntityReferenceContext $context): void {
            WorkflowRun::query()->whereKey($context->workflowRunId)->update([
                'run_deadline_at' => now()
                    ->subSecond(),
            ]);
        };
        $runId = $this->execute('expired-run');
        $this->assertNoCallbackOrCompletion($runId);
        $this->assertSame(RunStatus::Failed, WorkflowRun::query()->findOrFail($runId)->status);
    }

    public function testChangedHostingAttemptCannotAcceptTheResolvedObject(): void
    {
        ReviewOrderResolver::$duringLookup = static function (EntityReferenceContext $context): void {
            WorkflowTask::query()->where('workflow_run_id', $context->workflowRunId)->increment('attempt_count');
        };
        $runId = $this->execute('replaced-attempt');
        $this->assertNoCallbackOrCompletion($runId);
        $this->assertSame(1, ReviewOrderResolver::$loads);
    }

    public function testSecondLocalResolutionKeepsTheOriginalTaskHistoryBudget(): void
    {
        config()->set('workflows.v2.structural_limits.history_transaction_size', 2);
        $runId = $this->execute('history-budget', 2);
        $this->assertSame(1, ReviewOrderActivity::$calls, json_encode([
            'run' => WorkflowRun::query()->findOrFail($runId)->only(['status', 'last_history_sequence']),
            'events' => WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)->orderBy('sequence')->get()
                ->map(static fn ($event) => [$event->sequence, $event->event_type->value])->all(),
            'samples' => ReviewOrderResolver::$samples,
        ], JSON_THROW_ON_ERROR));
        $this->assertSame(2, ReviewOrderResolver::$loads);
        $this->assertSame(ReviewOrderResolver::$samples[0]['baseline'], ReviewOrderResolver::$samples[1]['baseline']);
        $this->assertSame(RunStatus::Failed, WorkflowRun::query()->findOrFail($runId)->status);
    }

    private function execute(string $id, int $count = 1): string
    {
        $workflow = WorkflowStub::make(ReviewLocalWorkflow::class, $id);
        $workflow->start($count);
        $this->runReadyTask($workflow->runId());
        return $workflow->runId();
    }

    private function runReadyTask(string $runId): void
    {
        $task = WorkflowTask::query()->where('workflow_run_id', $runId)->where('status', TaskStatus::Ready)->sole();
        $this->app->call([new RunWorkflowTask($task->id), 'handle']);
    }

    private function assertNoCallbackOrCompletion(string $runId): void
    {
        $this->assertSame(0, ReviewOrderActivity::$calls);
        $this->assertSame(0, WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)
            ->where('event_type', HistoryEventType::ActivityCompleted)->count());
    }
}

final class ReviewOrderResolver implements EntityReferenceResolver
{
    public static ?Closure $duringLookup = null;

    public static int $loads = 0;

    public static array $samples = [];

    public function resolve(EntityReference $reference, EntityReferenceContext $context): object
    {
        if (DB::transactionLevel() !== 0) {
            throw new \RuntimeException('Resolver ran inside SQL.');
        }
        ++self::$loads;
        $task = WorkflowTask::query()->where('workflow_run_id', $context->workflowRunId)->where(
            'status',
            TaskStatus::Leased
        )->sole();
        self::$samples[] = [
            'history' => WorkflowRun::query()->findOrFail($context->workflowRunId)->last_history_sequence,
            'baseline' => \Workflow\V2\Support\EmbeddedLocalActivityScope::historySequenceAtTaskStart($task, -1),
            'limit' => \Workflow\V2\Support\StructuralLimits::historyTransactionSizeLimit(),
        ];
        if (self::$duringLookup !== null) {
            (self::$duringLookup)($context);
        }
        return new Order($reference->id, 'current');
    }
}

final class ReviewOrderActivity extends Activity
{
    public static int $calls = 0;

    public function handle(Order $order): string
    {
        ++self::$calls;
        return $order->status;
    }
}

final class ReviewLocalWorkflow extends Workflow
{
    public function handle(int $count = 1): string
    {
        $result = '';
        for ($i = 0; $i < $count; ++$i) {
            $result = localActivity(ReviewOrderActivity::class, new Order('o-' . $i, 'before'));
        }
        return $result;
    }
}
