<?php

declare(strict_types=1);

namespace Workflow\V2\Compensation\Recovery;

use Workflow\V2\Attributes\Type;
use Workflow\V2\Compensation\Activities\CompensateObligationActivity;
use Workflow\V2\Compensation\Activities\PrepareRecoveryActivity;
use Workflow\V2\Compensation\Activities\VerifyScopeActivity;
use Workflow\V2\Workflow;

/** A normal native workflow with native Saga ordering and native activity retries. */
#[Type('dw.compensation.recovery.v1')]
final class RecoveryWorkflow extends Workflow
{
    public function handle(array $scope): array
    {
        $obligations = self::activity(PrepareRecoveryActivity::class, $scope);
        foreach ($obligations as $id) {
            $this->addCompensation(static fn () => self::activity(CompensateObligationActivity::class, $scope, $id));
        }
        $this->compensate();
        return self::activity(VerifyScopeActivity::class, $scope);
    }
}
