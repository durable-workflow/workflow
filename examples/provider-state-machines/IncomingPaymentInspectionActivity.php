<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use Workflow\V2\Activity;
use Workflow\V2\Attributes\Type;

#[Type('example.provider.incoming-inspection.v1')]
final class IncomingPaymentInspectionActivity extends Activity
{
    public function handle(array $scope, array $descriptor): array
    {
        return app(IncomingPaymentInspectionStore::class)->inspect(
            $this->run,
            $this->activityId(),
            $scope,
            $descriptor
        );
    }
}
