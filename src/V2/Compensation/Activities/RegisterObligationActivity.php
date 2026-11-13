<?php

declare(strict_types=1);

namespace Workflow\V2\Compensation\Activities;

use Workflow\V2\Activity;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\Execution\HandlerRegistry;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\Compensation\Security\ScopeBoundary;

/**
 * @internal Recorded compensation engine boundary.
 *
 * @experimental The Compensation API is not covered by stability guarantees and may change in a minor release.
 */
#[Type('dw.compensation.register-obligation.v1')]
final class RegisterObligationActivity extends Activity
{
    public int $tries = 3;

    public function handle(array $identity, array $descriptor, string $principal): string
    {
        $scope = ScopeIdentity::fromArray($identity);
        $obligation = Obligation::fromArray($descriptor);
        return app(ScopeBoundary::class)->run($this, $scope, 'register', $principal, function () use (
            $scope,
            $obligation
        ): string {
            $repository = app(ScopeRepository::class);
            $repository->open($scope, $this->runId());
            return $repository->register($scope, $obligation);
        }, beforeTransaction: static fn () => app(HandlerRegistry::class)->resolve($obligation));
    }
}
