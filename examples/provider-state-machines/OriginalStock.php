<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Journal;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Request;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Status;
use LogicException;
use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Compensation\Persistence\ScopeRepository;

/** Original recorded reservation ownership and provider confirmation, shared by versioned handoffs. */
final readonly class OriginalStock
{
    public function __construct(
        private Authority $authority,
        private Journal $provider
    ) {
    }

    public function confirmed(string $key, BindingPlan $plan): ?string
    {
        $request = $this->request($key, $plan);
        if ($request === null) {
            return null;
        }
        if ($this->authority->cart->selected($key) && ! app(CartStore::class)->stockHeld($key)) {
            return null;
        }
        $value = $this->provider->lookup($request);
        return $value->status === Status::Confirmed && $value->effectId === 'effect-' . $request->identity->key()
            ? $value->effectId : null;
    }

    public function request(string $key, BindingPlan $plan): ?Request
    {
        $this->authority->assignment($key);
        $ledger = (new ScopeRepository())->inspect($plan->rootScope());
        $expected = $this->authority->cart->stockObligation($key);
        $rows = array_values(array_filter($ledger['obligations'], static fn (array $row): bool =>
            ($row['descriptor']['key'] ?? null) === $expected->key));
        if ($rows === []) {
            return null;
        }
        if (count($rows) !== 1 || $ledger['original_run_id'] !== $plan->rootRun
            || $rows[0]['fingerprint'] !== $expected->fingerprint()
            || Obligation::fromArray($rows[0]['descriptor'])->fingerprint() !== $expected->fingerprint()) {
            throw new LogicException('The warehouse evidence is outside its original registered native owner.');
        }
        if ($rows[0]['forward_status'] !== 'observed') {
            return null;
        }
        return Request::fromArray($expected->input['forward']);
    }
}
