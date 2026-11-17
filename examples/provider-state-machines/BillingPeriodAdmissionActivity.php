<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use Workflow\V2\Activity;
use Workflow\V2\Attributes\Type;

#[Type('example.billing.period.admission.v1')]
final class BillingPeriodAdmissionActivity extends Activity
{
    public function handle(array $request): array
    {
        return app(BillingPeriodAssessment::class)->admit($this->run, $request);
    }
}
