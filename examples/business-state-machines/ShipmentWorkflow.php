<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\BusinessStateMachines;

use Workflow\V2\Attributes\Type;
use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\DurableScope;
use Workflow\V2\Exceptions\WorkflowCancellationRequestedException;
use Workflow\V2\Workflow;

#[Type('example.fulfilment.child.v1')]
final class ShipmentWorkflow extends Workflow
{
    public function handle(string $subject, string $integration, string $version, array $identity): array
    {
        $scopeIdentity = ScopeIdentity::fromArray($identity);
        $scope = new DurableScope($this, $scopeIdentity, NestedCompensationExample::PRINCIPAL);
        $execution = self::activity(NestedExampleActivity::class, 'initialize', [
            'subject' => $subject,
            'integration' => $integration,
            'version' => $version,
            'role' => 'child',
            'parentRun' => $scopeIdentity->generation,
        ]);
        try {
            $result = $scope->execute(new Obligation('deliver', 'example.sql.deliver', '1', [
                'action' => 'deliver',
                'execution' => $execution,
            ]));
            if (($result['status'] ?? null) !== 'active') {
                throw new BusinessCancellationRequested('Shipment delivery was fenced.');
            }
            return self::activity(NestedExampleActivity::class, 'shipment-completed', [
                'subject' => $subject,
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
