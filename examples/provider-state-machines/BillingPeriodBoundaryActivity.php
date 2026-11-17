<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use LogicException;
use Workflow\V2\Activity;
use Workflow\V2\Attributes\Type;

#[Type('example.billing.period.boundary.v1')]
final class BillingPeriodBoundaryActivity extends Activity
{
    public function handle(string $operation, array $input): array
    {
        $keys = array_keys($input);
        if ($keys !== ($operation === 'execution' ? ['key'] : [])) {
            throw new LogicException('The recorded billing-period boundary input is malformed.');
        }
        $store = app(BillingPeriodStore::class);
        return match ($operation) {
            'initialize' => $store->initialize($this->run),
            'execution' => $store->execution($this->run, $input['key'])->toArray(),
            'observe' => $store->observation($this->run),
            'finish' => $this->finish($store),
            default => throw new LogicException('The recorded billing-period operation is unavailable.'),
        };
    }

    private function finish(BillingPeriodStore $store): array
    {
        [$period, $subject] = $store->actor($this->run);
        return [
            'paymentState' => $subject->state,
            'service' => $store->access($period->subjectKey()),
        ];
    }
}
