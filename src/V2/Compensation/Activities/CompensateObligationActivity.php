<?php

declare(strict_types=1);

namespace Workflow\V2\Compensation\Activities;

use Throwable;
use Workflow\Exceptions\NonRetryableException;
use Workflow\V2\Activity;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\Execution\HandlerContractViolation;
use Workflow\V2\Compensation\Execution\HandlerRegistry;
use Workflow\V2\Compensation\Execution\OperationContext;
use Workflow\V2\Compensation\Execution\Resolution;
use Workflow\V2\Compensation\Persistence\ChildScopes;
use Workflow\V2\Compensation\Persistence\ScopeProgress;
use Workflow\V2\Compensation\Recovery\ChildResolution;
use Workflow\V2\Compensation\Recovery\ReservedChildResolution;
use Workflow\V2\Compensation\Security\ScopeAuthorizationRefused;
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
            reconcileBeforeForward: false,
            forwardActivityId: $row['forward_activity_id'],
            forwardAttemptId: $row['forward_attempt_id'],
            forwardOutcomeUnconfirmed: $row['forward_status'] !== 'observed',
        );
        $unavailable = false;
        $violation = null;
        $refused = false;
        try {
            if ($obligation->handlerType === ChildScopes::TYPE) {
                $result = app(ChildResolution::class)->resolve($scope, $obligation, $id, $this->runId());
            } elseif ($obligation->handlerType === ChildScopes::RESERVED_TYPE) {
                $result = app(ReservedChildResolution::class)->resolve($scope, $obligation, $id, $this->runId());
            } else {
                $handler = app(HandlerRegistry::class)->resolve($obligation);
                $result = match ($row['forward_status']) {
                    'registered' => Resolution::verified(['never-dispatched:' . $id]),
                    // The handler reported that the provider definitely made no effect.
                    'rejected' => Resolution::verified(['forward-rejected:' . $id]),
                    default => $handler->compensate($context),
                };
            }
        } catch (NonRetryableException) {
            $unavailable = true;
            $result = Resolution::unknown('handler-unavailable');
        } catch (ScopeAuthorizationRefused) {
            // A permanent refusal leaves the obligation failed until a new authorized request.
            $refused = true;
            $result = Resolution::failed('authorization-refused');
        } catch (HandlerContractViolation $thrown) {
            $violation = $thrown;
            $result = Resolution::unknown('handler-contract-violation');
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
        if ($refused) {
            throw new NonRetryableException('scope_authorization_refused');
        }
        if ($violation !== null) {
            throw new NonRetryableException(
                'scope_handler_contract_violation: ' . $violation->getMessage(),
                0,
                $violation
            );
        }
        if ($result->outcome === Resolution::FAILED) {
            throw new NonRetryableException('scope_compensation_failed');
        }
        if (! $result->verified) {
            throw new \RuntimeException('scope_compensation_unknown');
        }
    }
}
