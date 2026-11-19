<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use DurableWorkflow\Examples\ProviderStateMachines\AuthorizationCheckout;
use DurableWorkflow\Examples\ProviderStateMachines\CancellationFinalizer;
use DurableWorkflow\Examples\ProviderStateMachines\CancellationStore;
use DurableWorkflow\Examples\ProviderStateMachines\FullShipment;
use RuntimeException;
use Tests\Support\PhysicalMachineFixture;
use Tests\TestCase;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\Recovery\AutomaticRecovery;
use Workflow\V2\Compensation\Recovery\NextAction;
use Workflow\V2\Compensation\Recovery\ObligationStatus;
use Workflow\V2\Compensation\Recovery\ScopeRecovery;
use Workflow\V2\Compensation\Recovery\ScopeStatus;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\WorkflowReplayer;
use Workflow\V2\TaskWatchdog;

require_once __DIR__ . '/../../../examples/provider-state-machines/bootstrap.php';

/** Business cancellation of an in-transit full parcel (#1217); synthetic recorded-task source fixture. */
final class V2PhysicalInTransitCancellationTest extends TestCase
{
    use PhysicalMachineFixture;

    private const FORWARD = ['authorize', 'reserve', 'capture-authorized', 'ship-book', 'deduct-stock'];

    private const RETURN_UNSETTLED = [ObligationStatus::Unresolved->value, 'shipment-physical-return-unsettled'];

    public function testInTransitCancellationWaitsForInspectedReturnThenRecoversToCancelled(): void
    {
        CancellationFinalizer::register(app('events'), $this->store->authority, app(CancellationStore::class));
        $origin = $this->startPhysical();
        $shipment = $this->shipment();
        $this->record('handed-over');
        $this->hint($shipment, 'original-handover');
        $this->current();
        $this->assertSame('in-transit', $this->subjectState('shipment'), $this->progress());
        $this->assertSame('ready', $this->subjectState('order'));
        $key = $this->terms->subjectKey();
        $cancellations = app(CancellationStore::class);
        $request = $cancellations->request($key, $origin->runId(), 'cancel-in-transit');
        $this->assertSame('requested', $request['status']);
        $this->assertSame('origin', $request['route']['route'], json_encode($request));
        $this->drain();

        // The parcel is still out: the return is requested, nothing is refunded, released or restocked.
        foreach ([
            $origin->runId() => AuthorizationCheckout::class,
            $shipment->id => FullShipment::class,
        ] as $id => $class) {
            $run = WorkflowRun::query()->findOrFail($id);
            $this->assertSame($class, $run->workflow_class);
            $this->assertSame(RunStatus::Failed, $run->status, $this->progress());
            $this->assertSame(
                'scope_compensation_unknown',
                WorkflowFailure::query()->where('workflow_run_id', $id)->latest('created_at')->value('message')
            );
        }
        $this->assertSame([...self::FORWARD, 'request-return'], $this->effects());
        $this->assertSame('ready', $this->subjectState('order'));
        $plan = $this->store->authority->plan($key);
        $root = $this->described($plan->rootScope());
        $this->assertSame([
            ScopeStatus::Requested->value,
            RunStatus::Failed->value,
            AutomaticRecovery::Pending->value,
            NextAction::AwaitAutomaticRecovery->value,
        ], [$root['scopeStatus'], $root['originStatus'], $root['automaticRecovery'], $root['nextAction']]);
        $this->assertSame(self::RETURN_UNSETTLED, $this->physical($plan->childScope('shipment')));
        $history = $this->history($origin->runId());
        $childHistory = $this->history($shipment->id);

        // Receipt alone is not settlement. Automatic recovery routes the accepted request and stays unsettled.
        $this->record('received');
        $this->assertSame(2, TaskWatchdog::runPass(respectThrottle: false)['compensation_recoveries_started']);
        $this->drain();
        $this->assertSame([...self::FORWARD, 'request-return'], $this->effects(), $this->progress());
        $this->assertSame(0, $this->physicalFacts()['restocked_quantity']);
        $this->assertSame('ready', $this->subjectState('order'));
        $root = $this->described($plan->rootScope());
        $this->assertSame([
            ScopeStatus::Requested->value,
            AutomaticRecovery::Routed->value,
            NextAction::SubmitFreshAuthorizedRequest->value,
        ], [$root['scopeStatus'], $root['automaticRecovery'], $root['nextAction']]);
        $this->assertSame(self::RETURN_UNSETTLED, $this->physical($plan->childScope('shipment')));

        // Accepted saleable inspection: one fresh authorized request; recovery and the finalizer do the rest.
        $this->provider->shipments()
            ->record(
                $this->graph(),
                'original-accepted-inspection',
                'inspected',
                $this->terms->quantity,
                $this->terms->quantity,
                $this->terms->quantity
            );
        $this->assertSame(0, TaskWatchdog::runPass(respectThrottle: false)['compensation_recoveries_started']);
        $this->assertSame(
            'recovery',
            $cancellations->request($key, $origin->runId(), 'cancel-in-transit-return-inspected')['route']['route']
        );
        $this->drain();
        $this->assertSame(
            ScopeStatus::Verified->value,
            $this->described($plan->rootScope())['scopeStatus'],
            $this->progress()
        );
        $this->assertSame(
            [...self::FORWARD, 'request-return', 'restock', 'refund-authorized', 'release'],
            $this->effects()
        );
        $this->assertSame($this->terms->quantity, $this->physicalFacts()['restocked_quantity']);
        $this->assertFalse($this->physicalFacts()['original_recipient_delivered']);
        $this->assertFalse($this->deliveredPublication());
        $this->assertSame('cancelled', $this->subjectState('order'), $this->progress());
        $this->assertSame($history, $this->history($origin->runId()));
        $this->assertSame($childHistory, $this->history($shipment->id));
        $before = (int) $this->providerDb->query('SELECT COUNT(*) FROM provider_requests')
            ->fetchColumn();
        $replayed = [];
        foreach ([$origin->runId(), $shipment->id] as $id) {
            try {
                app(WorkflowReplayer::class)->replay(WorkflowRun::query()->findOrFail($id));
                $replayed[] = 'replayed';
            } catch (RuntimeException $failure) {
                // Replay reproduces the recorded technical failure without provider I/O.
                $replayed[] = $failure->getMessage();
            }
        }
        $this->assertSame(['scope_compensation_unknown', 'scope_compensation_unknown'], $replayed);
        $this->assertSame(
            $before,
            (int) $this->providerDb->query('SELECT COUNT(*) FROM provider_requests')
                ->fetchColumn()
        );
    }

    private function described(ScopeIdentity $scope): array
    {
        return app(ScopeRecovery::class)->describe($scope, $this->store->authority->principal);
    }

    /**
     * @return array{0: string, 1: string|null}|null
     */
    private function physical(ScopeIdentity $scope): ?array
    {
        foreach ($this->described($scope)['unresolved'] as $obligation) {
            if ($obligation['key'] === 'shipment.fulfillment') {
                return [$obligation['status'], $obligation['reason']];
            }
        }
        return null;
    }
}
