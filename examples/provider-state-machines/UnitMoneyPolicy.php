<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\OriginalUnitAmounts;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Request;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\UnitRefundCommand;
use LogicException;
use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Compensation\Definition\PortableDescriptor;
use Workflow\V2\Compensation\Execution\OperationContext;
use Workflow\V2\Compensation\Persistence\ScopeRepository;

/** Trusted original unit amounts; workflow arguments cannot select or replace this policy. */
final readonly class UnitMoneyPolicy
{
    public function __construct(
        private Authority $authority,
        private array $amounts,
        array $subjects,
    ) {
        foreach ($this->amounts as $key => $amounts) {
            if (! in_array($key, $subjects, true) || ! is_array($amounts) || ! array_is_list($amounts)
                || $amounts === [] || count($amounts) > 1000
                || ! in_array($authority->recipe($key), [Catalogue::PAYMENT_FIRST,
                    Catalogue::COMMON_RESERVE_FIRST], true)) {
                throw new LogicException(
                    'Unit money needs an existing direct-capture grant and explicit original amounts.'
                );
            }
            foreach ($amounts as $amount) {
                if (! is_int($amount) || $amount < 0) {
                    throw new LogicException('Unit money requires explicit nonnegative integer original amounts.');
                }
            }
        }
    }

    public function selected(string $key): bool
    {
        return array_key_exists($key, $this->amounts);
    }

    public function grants(): array
    {
        return array_keys($this->amounts);
    }

    public function version(string $key): string
    {
        return $this->selected($key)
            ? '.unit-money-v1.' . hash('sha256', PortableDescriptor::encode($this->amounts[$key])) : '';
    }

    public function original(OrderTerms $terms): OriginalUnitAmounts
    {
        $key = $terms->subjectKey();
        if (! $this->selected($key) || count($this->amounts[$key]) !== $terms->quantity) {
            throw new LogicException('Unit money must retain every original requested order unit.');
        }
        return new OriginalUnitAmounts(Request::fromArray(
            $terms->descriptor('payment')['forward']
        ), $this->amounts[$key]);
    }

    public function obligation(string $key): Obligation
    {
        [, $terms] = $this->authority->assignment($key);
        $original = $this->original($terms);
        $command = new UnitRefundCommand($original, 'original-order-payment-cancellation', array_keys(
            $original->amounts
        ));
        return new Obligation('effect', 'provider.unit-payment', '1', [
            'version' => 1,
            'refund' => $command->toArray(),
        ]);
    }

    public function operation(OperationContext $context, string $mode): void
    {
        $this->authority->authorizeScope($context->scope, $this->authority->principal, $mode);
        $key = explode('.', $context->scope->key)[0];
        $plan = $this->authority->plan($key);
        $expected = $this->obligation($key);
        $actor = $this->authority->db->table('provider_machine_actors')
            ->where('id', Authority::subjectId($key, 'payment'))->sole();
        if (! in_array($mode, ['forward', 'compensate'], true)
            || $context->scope->toArray() !== $plan->childScope('payment')
                ->toArray()
            || $context->obligation->fingerprint() !== $expected->fingerprint()
            || $actor->root_key !== $key || $actor->role !== 'payment'
            || $actor->native_run !== $context->originalRunId
            || $actor->scope !== PortableDescriptor::encode($context->scope->toArray())) {
            throw new LogicException('Unit money belongs to another original business or native Payment owner.');
        }
        $this->authority->subject($key, 'payment');
        $root = $this->authority->subject($key, 'order');
        if ($mode === 'forward' && (bool) $root->cancellation_requested) {
            throw new LogicException('Original business cancellation has closed unit-payment admission.');
        }
        $ledger = (new ScopeRepository())->inspect($context->scope);
        $rows = array_values(array_filter($ledger['obligations'], static fn (array $row): bool =>
            $row['id'] === $context->operationId));
        if ($ledger['original_run_id'] !== $actor->native_run || count($rows) !== 1
            || $rows[0]['fingerprint'] !== $expected->fingerprint()
            || Obligation::fromArray($rows[0]['descriptor'])->fingerprint() !== $expected->fingerprint()) {
            throw new LogicException('Unit money requires its exact original registered Payment obligation.');
        }
    }
}
