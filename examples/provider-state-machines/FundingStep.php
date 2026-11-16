<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use DurableWorkflow\Examples\BusinessStateMachines\BusinessCancellationRequested;
use LogicException;
use Workflow\V2\Compensation\RegisteredObligation;
use Workflow\V2\StateMachine\Execution\ExecutionReceipt;
use Workflow\V2\StateMachine\Execution\ExecutionReference;
use Workflow\V2\StateMachine\Transition\AssessedTransitionStep;
use Workflow\V2\StateMachine\Transition\TransitionContext;
use Workflow\V2\Workflow;

final class FundingStep extends AssessedTransitionStep
{
    /**
     * @param array<string, RegisteredObligation> $duties
     */
    public function __construct(
        private readonly string $recipe,
        private readonly array $duties
    ) {
    }

    public function execute(TransitionContext $context): ExecutionReceipt
    {
        $key = $context->transition->key;
        $cod = CodFundingPolicy::selected($this->recipe);
        $this->admit($key);
        $target = $this->target($key);
        $signal = match ($this->recipe) {
            CodFundingPolicy::BEFORE, CodFundingPolicy::AFTER => 'cod-observed',
            FundingCatalogue::BANK => 'incoming-observed', default => 'reconcile-funding',
        };
        for ($wake = 0; $wake < 32; ++$wake) {
            $value = Workflow::activity(FundingHandoffActivity::class, 'read-capture', []);
            if ($value['values'][$target]) {
                return new ExecutionReceipt($context->execution(), [
                    'original-funding:' . $context->execution()->executionId,
                ]);
            }
            $waitingForMerchant = $cod && $key === 'confirm-receipt';
            if ($value['waitSeconds'] === 0 && ! $waitingForMerchant) {
                Workflow::activity(FundingHandoffActivity::class, 'expire', []);
                throw new BusinessCancellationRequested(
                    'The original funding deadline expired without its required fact.'
                );
            }
            $remaining = $value['waitSeconds'];
            if ($waitingForMerchant) {
                $remaining = $value['remittanceWaitSeconds'] ?: null;
            }
            Workflow::await($signal, $remaining);
        }
        throw new LogicException('The bounded original funding observation remains unresolved.');
    }

    public function compensate(TransitionContext $context, ExecutionReference $originalExecution): void
    {
        if (! $context->execution()->equals($originalExecution)) {
            throw new LogicException('Funding compensation must retain its original execution.');
        }
        foreach (array_reverse($this->duties) as $duty) {
            $duty->compensate();
        }
    }

    private function admit(string $key): void
    {
        $cod = CodFundingPolicy::selected($this->recipe);
        if ($key === 'prepare' && $cod) {
            $this->duties['merchant']->execute();
            $this->duties['payer']->execute();
        } elseif ($key === 'prepare') {
            $stage = match ($this->recipe) {
                FundingCatalogue::BANK => 'incoming', FundingCatalogue::IMMEDIATE => 'capture', default => 'authorize',
            };
            $this->duties[$stage]->execute();
        } elseif ($key === 'collect' && FundingCatalogue::requiresAuthorization($this->recipe)) {
            $this->duties['capture']->execute();
        } elseif (! in_array($key, ['collect', 'confirm-receipt'], true)) {
            throw new LogicException('The original funding transition is unavailable.');
        }
    }

    private function target(string $key): string
    {
        $collected = $this->recipe === FundingCatalogue::BANK ? 'incoming_full_amount_confirmed' : 'capture_confirmed';
        $prepared = FundingCatalogue::requiresAuthorization($this->recipe) ? 'authorization_active' : $collected;
        if (CodFundingPolicy::selected($this->recipe)) {
            $collected = 'cod_payer_collection_confirmed';
            $prepared = 'cod_reference_ready';
        }
        return match ($key) {
            'prepare' => $prepared, 'collect' => $collected, 'confirm-receipt' => 'merchant_receipt_confirmed',
        };
    }
}
