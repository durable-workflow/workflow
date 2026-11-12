<?php

declare(strict_types=1);

namespace Workflow\V2\Compensation;

use Workflow\V2\Compensation\Activities\CompensateObligationActivity;
use Workflow\V2\Compensation\Activities\ForwardOperationActivity;
use Workflow\V2\Compensation\Activities\RegisterChildScopeActivity;
use Workflow\V2\Compensation\Activities\RegisterObligationActivity;
use Workflow\V2\Compensation\Activities\RequestScopeActivity;
use Workflow\V2\Compensation\Activities\VerifyScopeActivity;
use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Support\ActivityOptions;
use Workflow\V2\Support\WorkflowFiberContext;
use Workflow\V2\Workflow;

/** Workflow authoring helper. Every live boundary is a native recorded activity. */
final readonly class DurableScope
{
    public function __construct(
        private Workflow $saga,
        private ScopeIdentity $identity,
        private string $principal
    ) {
        if (! WorkflowFiberContext::active()) {
            throw new \LogicException('DurableScope must be authored inside a native workflow.');
        }
    }

    public function execute(Obligation $obligation, ?ActivityOptions $options = null): array
    {
        $scope = $this->identity->toArray();
        $principal = $this->principal;
        $id = Workflow::activity(RegisterObligationActivity::class, $scope, $obligation->toArray(), $principal);
        // The callback is rebuilt by replay before dispatching the effect and never needs its return value.
        $this->saga->addCompensation(static function () use ($scope, $principal, $id): void {
            Workflow::activity(RequestScopeActivity::class, $scope, $principal);
            Workflow::activity(CompensateObligationActivity::class, $scope, $id);
        });
        return Workflow::activity(
            ForwardOperationActivity::class,
            $options ?? new ActivityOptions(),
            $scope,
            $id,
            $principal
        );
    }

    public function participate(string $key, ScopeIdentity $child): void
    {
        $scope = $this->identity->toArray();
        $principal = $this->principal;
        $id = Workflow::activity(RegisterChildScopeActivity::class, $scope, $key, $child->toArray(), $principal);
        $this->saga->addCompensation(static function () use ($scope, $principal, $id): void {
            Workflow::activity(RequestScopeActivity::class, $scope, $principal);
            Workflow::activity(CompensateObligationActivity::class, $scope, $id);
        });
    }

    public function verifyCompensation(): array
    {
        return Workflow::activity(VerifyScopeActivity::class, $this->identity->toArray());
    }
}
