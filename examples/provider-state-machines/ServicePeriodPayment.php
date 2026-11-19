<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use DurableWorkflow\Examples\BusinessStateMachines\BusinessCancellationRequested;
use LogicException;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\DurableScope;
use Workflow\V2\Exceptions\WorkflowCancellationRequestedException;
use Workflow\V2\StateMachine\Execution\ActivityTransitionRuntime;
use Workflow\V2\StateMachine\Execution\TransitionStatus;
use Workflow\V2\StateMachine\TransitionRunner;
use Workflow\V2\Workflow;

/** Native Payment for a genuine service period; it owns no invented Order, stock or Shipment. */
#[Type('example.billing.period.payment.v1')]
#[Signal('payment-observed')]
final class ServicePeriodPayment extends Workflow
{
    public function handle(string $key): array
    {
        $initial = self::activity(BillingPeriodBoundaryActivity::class, 'initialize', []);
        $period = BillingPeriod::fromArray($initial['period']);
        if ($period->order !== null || $period->subjectKey() !== $key || $initial['nativeRun'] !== $this->runId()) {
            throw new LogicException('The recorded service-period initialization belongs to another original owner.');
        }
        $scope = new DurableScope($this, ScopeIdentity::fromArray($initial['scope']), $initial['principal']);
        $owner = $scope->register(new Obligation('owner', 'billing.period.owner', '1', [
            'period' => $key,
            'run' => $this->runId(),
        ]));
        $payment = $scope->register(
            new Obligation('payment', 'billing.period.payment', '1', $period->paymentDescriptor())
        );
        try {
            if (($owner->execute()['fenced'] ?? null) !== false) {
                throw new BusinessCancellationRequested('The original period was fenced before payment.');
            }
            $profile = BillingPeriodPolicy::paymentProfile($period);
            $runtime = new ActivityTransitionRuntime(
                BillingPeriodSnapshotActivity::class,
                BillingPeriodAdmissionActivity::class,
                BillingPeriodCommitActivity::class,
                $profile->definition
            );
            $step = new BillingPeriodStep($period, $payment);
            $handlers = [];
            $edges = [];
            foreach ($profile->transitions as $edge) {
                $handlers[$edge->handlerKey()] = $step;
                $edges[$edge->key] = $edge;
            }
            foreach (['prepare', 'collect', 'confirm-receipt'] as $stage) {
                $edge = $edges[$stage];
                $execution = Authority::reference(self::activity(BillingPeriodBoundaryActivity::class, 'execution', [
                    'key' => $edge->key,
                ]));
                $result = (new TransitionRunner())->run($this, $profile, $execution, $edge->event, $runtime, $handlers);
                if ($result->status !== TransitionStatus::Committed) {
                    break;
                }
            }
            $finished = self::activity(BillingPeriodBoundaryActivity::class, 'finish', []);
            $outcome = $finished['paymentState'] === 'paid' ? 'paid' : 'unsettled';
            if ($finished['service']['latePayment']) {
                $outcome = 'late-payment-unsettled';
            }
            return [
                'originalRunId' => $this->runId(),
                'businessOutcome' => $outcome,
                ...$finished,
                'transition' => $result->toArray(),
            ];
        } catch (WorkflowCancellationRequestedException|BusinessCancellationRequested $error) {
            self::cancellationShield(fn () => $this->compensate());
            // verifyCompensation() returns only once every obligation is verified; otherwise its activity fails.
            self::cancellationShield(static fn () => $scope->verifyCompensation());
            return [
                'originalRunId' => $this->runId(),
                'businessOutcome' => 'compensated',
            ];
        }
    }
}
