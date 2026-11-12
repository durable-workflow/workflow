<?php

declare(strict_types=1);

namespace Workflow\V2\Compensation\Activities;

use Workflow\V2\Activity;
use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\Execution\HandlerRegistry;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\Compensation\Security\ScopeBoundary;

final class RegisterObligationActivity extends Activity
{
    public function handle(array $identity, array $descriptor, string $principal): string
    {
        $scope = ScopeIdentity::fromArray($identity);
        $obligation = Obligation::fromArray($descriptor);
        return app(ScopeBoundary::class)->run($this, $scope, 'register', $principal, function () use (
            $scope,
            $obligation
        ): string {
            app(HandlerRegistry::class)->resolve($obligation);
            $repository = app(ScopeRepository::class);
            $repository->open($scope, $this->runId());
            return $repository->register($scope, $obligation);
        });
    }
}
