<?php

declare(strict_types=1);

namespace Workflow\V2\Compensation\Activities;

use Throwable;
use Workflow\Exceptions\NonRetryableException;
use Workflow\V2\Activity;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\Execution\ForwardResult;
use Workflow\V2\Compensation\Execution\HandlerContractViolation;
use Workflow\V2\Compensation\Execution\HandlerRegistry;
use Workflow\V2\Compensation\Execution\OperationContext;
use Workflow\V2\Compensation\Execution\ScopedOperation;
use Workflow\V2\Compensation\Persistence\ScopeProgress;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\Compensation\Security\ScopeBoundary;

/**
 * @internal Recorded compensation engine boundary.
 *
 * @experimental The Compensation API is not covered by stability guarantees and may change in a minor release.
 */
#[Type('dw.compensation.forward-operation.v1')]
final class ForwardOperationActivity extends Activity
{
    public int $tries = 3;

    /**
     * @param bool $valueLimits true for workflows that record the registration-checks patch
     */
    public function handle(array $identity, string $id, string $principal, bool $valueLimits = false): array
    {
        $scope = ScopeIdentity::fromArray($identity);
        $boundary = app(ScopeBoundary::class);
        $handler = null;
        $validatedFingerprint = null;
        $admission = $boundary->run(
            $this,
            $scope,
            'forward',
            $principal,
            function () use ($scope, $id, &$validatedFingerprint): array {
                return app(ScopeProgress::class)->admitForward(
                    $scope,
                    $id,
                    $this->attemptId(),
                    $this->activityId(),
                    $validatedFingerprint,
                );
            },
            beforeTransaction: static function () use ($scope, $id, &$handler, &$validatedFingerprint): void {
                $row = app(ScopeRepository::class)->obligation($scope, $id);
                $validatedFingerprint = $row['fingerprint'];
                // Cached forward results require no handler lookup or host I/O.
                if (! in_array($row['forward_status'], ['observed', 'rejected'], true)) {
                    $handler = app(HandlerRegistry::class)->resolve(Obligation::fromStored($row));
                }
            },
        );
        if ($admission['cached'] !== null) {
            $cached = ForwardResult::fromArray($admission['cached']);
            if ($cached->outcome === ForwardResult::REJECTED) {
                throw new NonRetryableException('scope_forward_rejected');
            }
            return $cached->value;
        }
        if (! $handler instanceof ScopedOperation) {
            throw new \LogicException('The forward handler was not validated before dispatch.');
        }
        $obligation = Obligation::fromStored($admission['row']);
        $context = new OperationContext(
            $scope,
            $obligation,
            $id,
            $this->runId(),
            $this->activityId(),
            $this->attemptId(),
            reconcileBeforeForward: $admission['uncertain'],
        );
        $violation = null;
        try {
            $result = $handler->execute($context);
            if ($valueLimits && $result->observed) {
                $result->assertValueWithinLimits();
            }
        } catch (HandlerContractViolation $thrown) {
            // The provider may have acted; compensation reconciles this obligation by operation ID.
            $violation = $thrown;
            $result = ForwardResult::unknown('handler-contract-violation');
        } catch (Throwable) {
            $result = ForwardResult::unknown('provider-outcome-unknown');
        }
        $boundary->run(
            $this,
            $scope,
            'forward',
            $principal,
            fn () => app(ScopeProgress::class)->forwardResult($scope, $id, $this->attemptId(), $result)
        );
        if ($violation !== null) {
            throw new NonRetryableException(
                'scope_handler_contract_violation: ' . $violation->getMessage(),
                0,
                $violation
            );
        }
        if ($result->outcome === ForwardResult::REJECTED) {
            throw new NonRetryableException('scope_forward_rejected');
        }
        if (! $result->observed) {
            throw new \RuntimeException('scope_forward_unknown');
        }
        return $result->value;
    }
}
