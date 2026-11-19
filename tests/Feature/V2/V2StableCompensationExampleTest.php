<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use DurableWorkflow\Examples\CompensationScopes\EffectLedger;
use DurableWorkflow\Examples\CompensationScopes\OrderCompensation;
use DurableWorkflow\Examples\CompensationScopes\OrderWorkflow;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\WorkflowStub;

require_once __DIR__ . '/../../../examples/compensation-scopes/bootstrap.php';

/**
 * The Order/Shipment example that uses only the stable compensation API (#1063).
 */
final class V2StableCompensationExampleTest extends TestCase
{
    private EffectLedger $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        self::stopWorkers();
        Queue::fake();
        config([
            'workflows.v2.namespaces' => [OrderCompensation::NAMESPACE],
            'workflows.v2.task_dispatch_mode' => 'queue',
            'queue.default' => 'redis',
        ]);
        EffectLedger::install(DB::connection());
        $this->ledger = OrderCompensation::configure($this->app, DB::connection());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        EffectLedger::uninstall(DB::connection());
        parent::tearDown();
    }

    public function testLateCancellationAfterTheOrderClosesCompensatesTheShipmentFirst(): void
    {
        $order = $this->start('order-1');
        $order->signal('finish');
        $this->drain();
        $this->assertSame(RunStatus::Completed, WorkflowRun::query()->findOrFail($order->runId())->status);
        $this->assertSame([
            'order' => 'fulfilled',
            'shipment' => 'booked',
        ], $order->refresh()  ->output());

        $receipt = OrderCompensation::cancel(OrderCompensation::orderScope('order-1', 'g1'), 'cancel-order-1');
        $this->assertSame('recovery', $receipt['route']);
        $this->drain();

        $this->assertSame(['carrier.book', 'stock.reserve'], $this->ledger->undoOrder());
        foreach ([
            OrderCompensation::orderScope('order-1', 'g1'),
            OrderCompensation::shipmentScope('order-1', 'g1'),
        ] as $scope) {
            $view = OrderCompensation::describe($scope);
            $this->assertSame('verified', $view['scopeStatus']);
            $this->assertSame('none', $view['nextAction']);
            $this->assertSame([], $view['unresolved']);
        }

        // Both runs have closed and every obligation is verified, so the host retires the retained scopes.
        foreach ([
            OrderCompensation::shipmentScope('order-1', 'g1'),
            OrderCompensation::orderScope('order-1', 'g1'),
        ] as $scope) {
            OrderCompensation::release($scope);
            OrderCompensation::release($scope);
            $this->assertSame('released', OrderCompensation::describe($scope)['scopeStatus']);
        }
    }

    public function testCancellationWhileTheOrderWaitsCompensatesThroughItsSaga(): void
    {
        $order = $this->start('order-2');
        $receipt = OrderCompensation::cancel(OrderCompensation::orderScope('order-2', 'g1'), 'cancel-order-2');
        $this->assertSame('origin', $receipt['route']);
        $this->drain();

        // Cooperative cancellation closes the Order as cancelled after its saga verified every obligation.
        $this->assertSame(RunStatus::Cancelled, WorkflowRun::query()->findOrFail($order->runId())->status);
        $this->assertSame(['carrier.book', 'stock.reserve'], $this->ledger->undoOrder());
        $this->assertSame(
            'verified',
            OrderCompensation::describe(OrderCompensation::orderScope('order-2', 'g1'))['scopeStatus']
        );
        $this->assertSame(
            'verified',
            OrderCompensation::describe(OrderCompensation::shipmentScope('order-2', 'g1'))['scopeStatus']
        );
    }

    public function testTheExampleUsesOnlyThePlannedStableApi(): void
    {
        $allowed = [
            'Workflow\\V2\\Workflow',
            'Workflow\\V2\\Attributes\\Signal',
            'Workflow\\V2\\Attributes\\Type',
            'Workflow\\V2\\Exceptions\\WorkflowCancellationRequestedException',
            'Workflow\\V2\\Compensation\\DurableScope',
            'Workflow\\V2\\Compensation\\Definition\\Obligation',
            'Workflow\\V2\\Compensation\\Definition\\ScopeIdentity',
            'Workflow\\V2\\Compensation\\Execution\\ForwardResult',
            'Workflow\\V2\\Compensation\\Execution\\HandlerRegistry',
            'Workflow\\V2\\Compensation\\Execution\\OperationContext',
            'Workflow\\V2\\Compensation\\Execution\\Resolution',
            'Workflow\\V2\\Compensation\\Execution\\ScopedOperation',
            'Workflow\\V2\\Compensation\\Recovery\\ScopeRecovery',
            'Workflow\\V2\\Compensation\\Recovery\\ScopeRetention',
            'Workflow\\V2\\Compensation\\Security\\ScopeAuthorization',
            'Workflow\\V2\\Compensation\\Security\\ScopeAuthorizationRefused',
        ];
        foreach (glob(__DIR__ . '/../../../examples/compensation-scopes/*.php') ?: [] as $file) {
            $source = (string) file_get_contents($file);
            preg_match_all('/^use (Workflow\\\\[^;]+);/m', $source, $imports);
            foreach ($imports[1] as $import) {
                $this->assertContains($import, $allowed, basename($file) . ' imports ' . $import);
            }
            foreach (['ScopeRepository', 'PortableDescriptor', 'reserveChild', 'Persistence\\'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $source, basename($file));
            }
        }
    }

    private function start(string $orderId): WorkflowStub
    {
        $stub = WorkflowStub::make(OrderWorkflow::class, 'example-' . $orderId, OrderCompensation::NAMESPACE);
        $stub->start(
            OrderCompensation::orderScope($orderId, 'g1')->toArray(),
            OrderCompensation::shipmentScope($orderId, 'g1')->toArray(),
            'ref-' . $orderId,
        );
        $this->drain();
        $this->assertSame(RunStatus::Waiting, WorkflowRun::query()->findOrFail($stub->runId())->status);
        return $stub;
    }

    private function drain(): void
    {
        for ($i = 0; $i < 250; ++$i) {
            $task = WorkflowTask::query()->where('status', TaskStatus::Ready)->orderBy('available_at')
                ->orderBy('created_at')
                ->orderBy('id')
                ->first();
            if ($task === null) {
                return;
            }
            if ($task->available_at !== null && $task->available_at->isFuture()) {
                Carbon::setTestNow($task->available_at->copy()->addSecond());
            }
            $job = match ($task->task_type) {
                TaskType::Workflow => new RunWorkflowTask($task->id),
                TaskType::Activity => new RunActivityTask($task->id),
                TaskType::Timer => new RunTimerTask($task->id),
            };
            $this->app->call([$job, 'handle']);
        }
        $this->fail('The bounded native task drain did not settle.');
    }
}
