<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use Workflow\V2\Activity;
use Workflow\V2\Attributes\Type;

#[Type('example.provider.financial-inspection.v1')]
final class FinancialInspectionActivity extends Activity
{
    public function handle(array $scope, array $descriptor): array
    {
        return app(FinancialInspectionStore::class)->inspect($this->run, $this->activityId(), $scope, $descriptor);
    }
}
