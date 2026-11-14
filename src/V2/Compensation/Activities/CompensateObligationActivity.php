<?php

declare(strict_types=1);

namespace Workflow\V2\Compensation\Activities;

use Throwable;
use Workflow\Exceptions\NonRetryableException;
use Workflow\V2\Activity;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\Execution\HandlerRegistry;
use Workflow\V2\Compensation\Execution\OperationContext;
use Workflow\V2\Compensation\Execution\Resolution;
use Workflow\V2\Compensation\Persistence\ChildScopes;
use Workflow\V2\Compensation\Persistence\ScopeProgress;
use Workflow\V2\Compensation\Recovery\ChildResolution;
use Workflow\V2\Compensation\Recovery\ReservedChildResolution;
use Workflow\V2\Compensation\Security\ScopeBoundary;

/**
 * @internal Recorded compensation engine boundary.
 *
 * @experimental The Compensation API is not covered by stability guarantees and may change in a minor release.
 */
#[Type('dw.compensation.compensate-obligation.v1')]
final class CompensateObligationActivity extends Activity
{
    public int $tries = 3;

    public function handle(array $identity, string $id): void
    {
        $scope = ScopeIdentity::fromArray($identity);
        $boundary = app(ScopeBoundary::class);
        $origin = null;
        $row = $boundary->run($this, $scope, 'compensate', null, function (array $snapshot) use (
            $scope,
            $id,
            &$origin
        ): ?array {
            $origin = $snapshot['original_run_id'];
            return app(ScopeProgress::class)->beginCompensation($scope, $id, $this->attemptId());
        });
        if ($row === null) {
            return;
        }
        $obligation = Obligation::fromStored($row);
        $context = new OperationContext(
            $scope,
            $obligation,
            $id,
            $origin,
            $this->activityId(),
            $this->attemptId(),
            $row['forward_status'] !== 'observed',
            $row['forward_activity_id'],
            $row['forward_attempt_id'],
        );
        $unavailable = false;
        try {
            if ($obligation->handlerType === ChildScopes::TYPE) {
                $result = app(ChildResolution::class)->resolve($scope, $obligation, $id);
            } elseif ($obligation->handlerType === ChildScopes::RESERVED_TYPE) {
                $result = app(ReservedChildResolution::class)->resolve($scope, $obligation, $id);
            } else {
                $handler = app(HandlerRegistry::class)->resolve($obligation);
                $result = $row['forward_status'] === 'registered'
                    ? Resolution::verified(['never-dispatched:' . $id], true)
                    : $handler->compensate($context);
            }
        } catch (NonRetryableException) {
            $unavailable = true;
            $result = Resolution::unknown('handler-unavailable');
        } catch (Throwable) {
            $result = Resolution::unknown('provider-outcome-unknown');
        }
        $boundary->run(
            $this,
            $scope,
            'compensate',
            null,
            fn () => app(ScopeProgress::class)->resolve($scope, $id, $this->attemptId(), $result)
        );
        if ($unavailable) {
            throw new NonRetryableException('scope_handler_unavailable');
        }
        if (! $result->verified) {
            throw new \RuntimeException('scope_compensation_unknown');
        }
    }
}
