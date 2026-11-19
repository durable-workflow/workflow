<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\BusinessStateMachines;

use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Compensation\DurableScope;
use Workflow\V2\Exceptions\WorkflowCancellationRequestedException;
use Workflow\V2\Workflow;

/** One original Order execution; Shipment start and result observation are separate milestones. */
#[Type('example.fulfilment.parent.v1')]
#[Signal('finish')]
final class OrderWorkflow extends Workflow
{
    public function handle(string $subject, string $integration, string $version = '1'): array
    {
        $parentRun = $this->runId();
        $scope = new DurableScope($this, ExampleOwnershipPolicy::scope(
            $subject,
            $parentRun,
            'parent'
        ), NestedCompensationExample::PRINCIPAL);
        $execution = self::activity(NestedExampleActivity::class, 'initialize', [
            'subject' => $subject,
            'integration' => $integration,
            'version' => $version,
            'role' => 'parent',
            'parentRun' => $parentRun,
        ]);
        try {
            foreach (ProfileCatalogue::actions($integration, $version) as $action) {
                $result = $scope->execute(new Obligation($action, 'example.sql.' . $action, '1', [
                    'action' => $action,
                    'execution' => $execution,
                ]));
                if (($result['status'] ?? null) !== 'active') {
                    throw new BusinessCancellationRequested('Business forward work was fenced.');
                }
            }
            $childScope = ExampleOwnershipPolicy::scope($subject, $parentRun, 'child');
            $reservation = $scope->reserveChild('work', $childScope, ExampleOwnershipPolicy::binding(
                $subject,
                $integration,
                $version,
                $parentRun,
            ));
            $child = $reservation->start($subject, $integration, $version, $childScope->toArray());
            $binding = self::activity(NestedExampleActivity::class, 'bind', [
                'reservation' => $reservation->toArray(),
            ]);
            // The child may finish while the Order remains in its hosting state.
            self::await('finish');
            $outcome = $child->await();
            return self::activity(NestedExampleActivity::class, 'order-completed', [
                'binding' => $binding,
                'outcome' => $outcome,
                'integration' => $integration,
                'version' => $version,
            ]);
        } catch (WorkflowCancellationRequestedException|BusinessCancellationRequested $error) {
            self::cancellationShield(fn () => $this->compensate());
            // verifyCompensation() returns only once every obligation is verified; otherwise its activity fails.
            $result = self::cancellationShield(static fn () => $scope->verifyCompensation());
            return [
                'businessOutcome' => 'compensated',
                'scope' => $result,
            ];
        }
    }
}
