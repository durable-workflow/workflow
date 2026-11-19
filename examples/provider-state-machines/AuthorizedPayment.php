<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use DurableWorkflow\Examples\BusinessStateMachines\BusinessCancellationRequested;
use LogicException;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Exceptions\WorkflowCancellationRequestedException;
use Workflow\V2\StateMachine\Execution\TransitionStatus;
use Workflow\V2\Workflow;

#[Type('example.provider.payment.authorized.v1')]
#[Signal('capture-request')]
final class AuthorizedPayment extends Workflow
{
    public function handle(string $key, string $rootRun, array $entry, array $scope): array
    {
        $machine = new Machine($this, self::activity(BoundaryActivity::class, 'initialize', [
            'arguments' => [$key, $rootRun, $entry, $scope],
        ]));
        try {
            $machine->registerOwner();
            $graph = $machine->initialization['descriptor'];
            $authorization = $machine->scope->register(
                new Obligation('payment.authorize', 'provider.financial.authorize', '1', $graph)
            );
            // Only an opted-in unit-money grant records this field; legacy histories keep the original capture.
            $capture = $machine->initialization['capture'] ?? null;
            $money = $machine->scope->register(new Obligation(
                'payment.capture',
                $capture['handler'] ?? 'provider.financial.capture',
                '1',
                $capture['input'] ?? $graph
            ));
            $step = new FinancialStep($authorization, $money);
            $permission = $machine->transition('authorize', $step);
            if ($permission->status !== TransitionStatus::Committed) {
                return [
                    'businessOutcome' => 'unsettled',
                    'transition' => $permission->toArray(),
                ];
            }
            self::activity(FinancialHandoffActivity::class, 'notify', [
                'stage' => 'authorize',
            ]);
            for ($wake = 0; $wake < 32; ++$wake) {
                $snapshot = self::activity(FinancialHandoffActivity::class, 'read-capture', []);
                $financial = $snapshot['financial'];
                if ($financial['facts']['authorization_expired'] && ! $financial['facts']['capture_confirmed']) {
                    self::activity(FinancialHandoffActivity::class, 'expire', []);
                    throw new BusinessCancellationRequested('The original authorization expired before capture.');
                }
                if ($snapshot['business']['captureInstruction'] !== null) {
                    $captured = $machine->transition('capture', $step);
                    if ($captured->status === TransitionStatus::Committed) {
                        self::activity(FinancialHandoffActivity::class, 'notify', [
                            'stage' => 'capture',
                        ]);
                    }
                    return $machine->outcome($captured);
                }
                self::await(
                    'capture-request',
                    max(0, $financial['originalTerms']['expiresAt'] - $financial['observedAt'])
                );
            }
            throw new LogicException('The bounded original capture-instruction wait remains unresolved.');
        } catch (WorkflowCancellationRequestedException|BusinessCancellationRequested $error) {
            self::cancellationShield(fn () => $this->compensate());
            // verifyCompensation() returns only once every obligation is verified; otherwise its activity fails.
            self::cancellationShield(static fn () => $machine->scope->verifyCompensation());
            return [
                'businessOutcome' => 'compensated',
                'originalRunId' => $this->runId(),
            ];
        }
    }
}
