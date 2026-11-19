<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use Closure;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\CartReservationDescriptor;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Journal;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\RetainedOperation;
use LogicException;
use Workflow\V2\Compensation\Execution\ForwardResult;
use Workflow\V2\Compensation\Execution\OperationContext;
use Workflow\V2\Compensation\Execution\Resolution;
use Workflow\V2\Compensation\Execution\ScopedOperation;

/** Both lease duties precede Payment; a never-started successor needs the original positive admission fence. */
final readonly class CartLeaseOperation implements ScopedOperation
{
    /**
     * @param Closure(OperationContext, string): CartPlan $authorize Resolve and authorize the complete original host relation.
     */
    public function __construct(
        private Journal $provider,
        private Closure $authorize,
    ) {
    }

    public function assertInput(array $input): void
    {
        $keys = array_keys($input);
        sort($keys);
        if ($keys !== ['compensation', 'forward'] || ! is_array($input['forward']) || ! is_array(
            $input['compensation']
        )) {
            throw new LogicException('The cart duty descriptor is malformed.');
        }
        CartReservationDescriptor::fromArray([
            'reserve' => $input['forward'],
            'release' => $input['compensation'],
        ]);
    }

    public function execute(OperationContext $context): ForwardResult
    {
        [$plan] = $this->assertContext($context, 'forward');
        return $this->delegate($plan)
            ->execute($context);
    }

    public function compensate(OperationContext $context): Resolution
    {
        [$plan, $step] = $this->assertContext($context, 'compensate');
        if ($step === 'late') {
            // An explicit same-key provider fence is safe even if the initial command never ran.
            // Unknown/processing admissions remain unresolved; no absence inference is made.
            $this->provider->closeAdmission($plan->initial->reserve);
        }
        return $this->delegate($plan)
            ->compensate($context);
    }

    /**
     * @return array{CartPlan, string}
     */
    private function assertContext(OperationContext $context, string $mode): array
    {
        $this->assertInput($context->obligation->input);
        $plan = ($this->authorize)($context, $mode);
        foreach (['initial', 'late'] as $step) {
            if ($context->obligation->fingerprint() === $plan->obligation($step)->fingerprint()) {
                return [$plan, $step];
            }
        }
        throw new LogicException('The native cart responsibility belongs to another plan or lease step.');
    }

    private function delegate(CartPlan $expected): RetainedOperation
    {
        return new RetainedOperation($this->provider, function (OperationContext $context, string $mode) use (
            $expected
        ): void {
            [$current] = $this->assertContext($context, $mode);
            if ($current->fingerprint() !== $expected->fingerprint()) {
                throw new LogicException('The original cart assignment changed during provider authorization.');
            }
        });
    }
}
